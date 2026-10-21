<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaRunner;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\FontesDeDados;
use App\Services\CentralIa\Roteador;
use App\Services\CentralIa\TelegramCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pedido do dono (10/10/2026): resumo, análise e relatório vão ao Codex, e
 * "me faça um resumo da agenda de hoje" não pode trazer uma resposta por IA.
 */
class RespostasConsistentesTest extends TestCase
{
    use BotProprioNosTestes, RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.canal_exclusivo' => false]); // Protocolo anterior à ativação da troca de canal.
    }


    private function comCentral(): User
    {
        $user = User::factory()->create();
        $user->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        CentralIaRunner::create(['user_id' => $user->id, 'maquina' => 'wsl', 'agentes' => ['claude', 'codex', 'copilot'],
            'projetos' => ['gestor'], 'visto_em' => now()]);

        return $user;
    }

    /**
     * "Faça" caía em implementação e mandava o resumo ao Claude.
     */
    public function test_resumo_analise_e_relatorio_vao_ao_codex_mesmo_com_verbo_generico(): void
    {
        $r = app(Roteador::class);
        $todos = ['claude', 'codex', 'copilot'];
        $agente = fn (string $t) => [$r->decidir($t, $todos)['agente'], $r->decidir($t, $todos)['modo']];

        $this->assertSame(['codex', 'analisar'], $agente('Me faça um resumo de minha agenda de hoje'));
        $this->assertSame(['codex', 'analisar'], $agente('Faça um relatório das obras atrasadas'));
        $this->assertSame(['codex', 'analisar'], $agente('Crie uma análise do módulo financeiro'));
        $this->assertSame(['codex', 'analisar'], $agente('Quais tarefas estão atrasadas?'));
        $this->assertSame(['claude', 'implementar'], $agente('Implemente o relatório de vendas'));
        $this->assertSame(['claude', 'implementar'], $agente('Crie a tela de login'));
        $this->assertSame(['claude', 'implementar'], $agente('Corrija o resumo que sai errado'));
        // Verbo de alteração vence "status" e "lista" (revisão de 12/10).
        $this->assertSame(['claude', 'implementar'], $agente('Adicione um filtro de status na lista'));
        $this->assertSame(['claude', 'implementar'], $agente('Remova a coluna de quais clientes'));
    }

    public function test_agenda_e_detectada_com_o_periodo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 10:00', 'America/Sao_Paulo'));
        $f = app(FontesDeDados::class);

        $this->assertSame([['tipo' => 'agenda', 'inicio' => '2026-10-13', 'dias' => 1, 'rotulo' => 'hoje']], $f->detectar('Me faça um resumo de minha agenda de hoje'));
        $this->assertSame('2026-10-14', $f->detectar('Quais reuniões tenho amanhã?')[0]['inicio']);
        $this->assertSame(7, $f->detectar('compromissos da semana')[0]['dias']);
        $this->assertSame('2026-10-19', $f->detectar('agenda da próxima semana')[0]['inicio']);
        $this->assertSame([], $f->detectar('Analise o módulo financeiro'));
    }

    /**
     * Na conversa Geral, o pedido de agenda vai ao Codex com a fonte oficial
     * marcada — e o runner recebe o período para ler sempre do mesmo jeito.
     */
    public function test_pedido_de_agenda_na_geral_vai_ao_codex_com_a_fonte_oficial(): void
    {
        $user = $this->comCentral();

        $this->actingAs($user)->postJson(route('central-ia.enviar'), [
            'conteudo' => 'Me faça um resumo de minha agenda de hoje', 'client_id' => (string) Str::uuid(), 'agente' => 'auto', 'modo' => 'auto',
        ])->assertCreated();

        $e = CentralIaExecucao::firstOrFail();
        $this->assertSame(['', 'codex', 'analisar'], [$e->projeto, $e->agente, $e->modo]);
        $this->assertSame('agenda', $e->roteamento['fontes'][0]['tipo']);
        $this->assertNull(CentralIaMensagem::first()->projeto, 'O pedido aparece na conversa Geral.');

        Sanctum::actingAs($user, ['central-ia-runner']);
        $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->assertOk()
            ->assertJsonPath('projeto', '')
            ->assertJsonPath('fontes.0.tipo', 'agenda')
            ->assertJsonPath('fontes.0.dias', 1);

        $nomes = array_column($this->actingAs($user)->getJson(route('central-ia.painel'))->json('projetos'), 'nome');
        $this->assertNotContains('', $nomes, 'A Geral não vira projeto na barra.');
    }

    public function test_anotacao_na_geral_nao_aciona_agente(): void
    {
        $user = $this->comCentral();

        $this->actingAs($user)->postJson(route('central-ia.enviar'), [
            'conteudo' => 'ideia: pintar a sala de reunião de verde', 'client_id' => (string) Str::uuid(), 'agente' => 'auto',
        ])->assertCreated();
        $this->actingAs($user)->postJson(route('central-ia.enviar'), [
            'conteudo' => 'lembrar de ligar pro contador', 'client_id' => (string) Str::uuid(), 'agente' => 'auto',
        ])->assertCreated();

        $this->assertSame(1, CentralIaExecucao::count(), '"reunião" é tema de agenda; "lembrar…" é anotação.');
    }

    public function test_implementar_sem_projeto_e_recusado(): void
    {
        $user = $this->comCentral();

        $this->actingAs($user)->postJson(route('central-ia.enviar'), [
            'conteudo' => 'Corrija o bug do login', 'client_id' => (string) Str::uuid(), 'agente' => 'claude', 'modo' => 'implementar',
        ])->assertUnprocessable()->assertJsonValidationErrors('projeto');
    }

    public function test_pelo_telegram_sem_projeto_o_resumo_da_agenda_vai_ao_codex(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $user = $this->comCentral();
        $canal = app(TelegramCanal::class);
        $msg = fn ($id, $t) => ['update_id' => $id, 'message' => ['text' => $t, 'chat' => ['id' => 9, 'type' => 'private'], 'from' => ['id' => 9, 'is_bot' => false]]];
        $this->entregarAoBot($canal, $msg(1, '/start '.$canal->gerarCodigo($user)));

        $this->entregarAoBot($canal, $msg(2, 'Me faça um resumo de minha agenda de hoje'));

        $e = CentralIaExecucao::firstOrFail();
        $this->assertSame(['', 'codex'], [$e->projeto, $e->agente]);
        $recibo = (string) collect(Http::recorded())->map(fn ($p) => $p[0]['text'] ?? '')->last();
        $this->assertStringContainsString('Recebido em Geral: GPT (Codex)', $recibo);
        $this->assertStringContainsString('Agenda (hoje)', $recibo);
    }

    /** 21/10/2026: "crie uma agenda para amanhã" é pedido de criar, não consulta sem IA. */
    public function test_pedido_de_criar_compromisso_nao_vira_consulta_da_agenda(): void
    {
        $f = app(\App\Services\CentralIa\FontesDeDados::class);
        $this->assertSame([], $f->detectar('Crie uma agenda para amanhã no sistema: agenda da VixCard às 10:00 para o Alexandre ir na Medsenior'));
        $this->assertSame([], $f->detectar('marque na agenda de sexta uma reunião com o Victor'));
        $this->assertNotEmpty($f->detectar('qual a minha agenda de amanhã?'));
    }
}
