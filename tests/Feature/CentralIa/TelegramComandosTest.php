<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaRunner;
use App\Models\CentralIaTarefa;
use App\Models\CentralIaTelegramVinculo;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\TelegramCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Os comandos do cockpit pelo Telegram.
 */
class TelegramComandosTest extends TestCase
{
    use BotProprioNosTestes, RefreshDatabase;

    private const CHAT = 4242;

    private int $update = 100;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.canal_exclusivo' => false]); // Compatibilidade antes da ativação.
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $this->user = User::factory()->create();
        $this->user->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        CentralIaRunner::create(['user_id' => $this->user->id, 'maquina' => 'wsl', 'agentes' => ['claude', 'codex', 'copilot'],
            'projetos' => ['alfahome', 'gestor'], 'visto_em' => now()]);
        $canal = app(TelegramCanal::class);
        $this->entregarAoBot($canal, $this->msg('/start '.$canal->gerarCodigo($this->user)));
    }

    private function msg(string $texto): array
    {
        return ['update_id' => ++$this->update, 'message' => ['text' => $texto, 'chat' => ['id' => self::CHAT, 'type' => 'private'], 'from' => ['id' => self::CHAT, 'is_bot' => false]]];
    }

    private function enviar(string $texto): string
    {
        $this->entregarAoBot(app(TelegramCanal::class), $this->msg($texto));

        return (string) collect(Http::recorded())->map(fn ($p) => $p[0]['text'] ?? '')->last();
    }

    public function test_sem_projeto_em_uso_mensagem_comum_so_anota(): void
    {
        $this->enviar('uma ideia solta');

        $this->assertSame(0, CentralIaExecucao::count());
        $this->assertNull(CentralIaMensagem::where('conteudo', 'uma ideia solta')->value('projeto'));
    }

    public function test_usar_faz_a_mensagem_virar_pedido_na_tarefa_mais_recente(): void
    {
        $this->assertStringContainsString('Usando alfahome', $this->enviar('/usar alfahome'));

        $this->enviar('Implemente o filtro');
        $this->enviar('Ajuste o rótulo também');
        $this->enviar('/nova Analise a performance');

        $this->assertSame(3, CentralIaExecucao::count());
        $this->assertSame(2, CentralIaTarefa::count(), 'A segunda mensagem continua a tarefa; /nova abre outra.');
        $this->assertSame(['alfahome'], CentralIaExecucao::distinct()->pluck('projeto')->all());

        $this->enviar('/geral');
        $this->enviar('volta a ser anotação');
        $this->assertSame(3, CentralIaExecucao::count());
    }

    public function test_escolher_agente_e_trocar_no_meio_da_tarefa(): void
    {
        $this->enviar('/usar gestor');
        $this->enviar('/gpt');
        $this->enviar('Analise o cálculo de custo');
        $this->assertSame('codex', CentralIaExecucao::latest('id')->value('agente'));

        $this->enviar('/trocar claude Agora implemente a correção');
        $ultima = CentralIaExecucao::latest('id')->first();

        $this->assertSame('claude', $ultima->agente);
        $this->assertSame(1, CentralIaTarefa::count(), 'A troca continua a mesma tarefa.');
        $this->assertSame('Agora implemente a correção', $ultima->instrucao);
    }

    public function test_projetos_status_e_ajuda(): void
    {
        $this->enviar('/usar alfahome');
        $this->enviar('Implemente o filtro');

        $projetos = $this->enviar('/projetos');
        $this->assertStringContainsString('▶ alfahome', $projetos);

        $this->assertStringContainsString('alfahome', $this->enviar('/status'));
        $this->assertStringContainsString('/usar', $this->enviar('/naoexiste'));
        $this->assertStringContainsString('Não achei', $this->enviar('/usar inventado'));
    }

    /**
     * Calado, parecia que a mensagem tinha se perdido (10/10/2026).
     */
    public function test_mensagem_solta_e_anotada_e_o_bot_avisa(): void
    {
        $this->assertStringContainsString('Anotado na conversa Geral', $this->enviar('uma ideia solta para depois'));
    }

    public function test_intencao_com_palavras_usa_o_projeto(): void
    {
        $this->assertStringContainsString('Usando alfahome', $this->enviar('usar o alfahome'));
        $this->assertSame('alfahome', CentralIaTelegramVinculo::value('projeto_atual'));

        $recibo = $this->enviar('Implemente o filtro de obras');
        $this->assertStringContainsString('Recebido em alfahome: Claude vai implementar', $recibo);
    }

    public function test_frase_sem_projeto_conhecido_nao_vira_comando(): void
    {
        $this->assertStringContainsString('Anotado', $this->enviar('continuar amanhã com calma'));
        $this->assertNull(CentralIaTelegramVinculo::value('projeto_atual'));
    }

    public function test_tarefa_esperando_voce_aparece_no_status(): void
    {
        CentralIaTarefa::create(['user_id' => $this->user->id, 'projeto' => 'gestor', 'titulo' => 'Relatório', 'objetivo' => 'x',
            'status' => 'aguardando_voce', 'dossie' => ['duvida' => 'Por mês ou por obra?']]);

        $this->assertStringContainsString('Por mês ou por obra?', $this->enviar('/status'));
    }
}
