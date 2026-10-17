<?php

namespace Tests\Feature\Secretario;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaRunner;
use App\Models\SecretarioItem;
use App\Models\SecretarioProposta;
use App\Services\CentralIa\AgentesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** FR-007: frase que as regras não entendem vai à IA da pessoa e volta como proposta a confirmar. */
class PropostaDaIaTest extends TestCase
{
    use RefreshDatabase, SecretarioNosTestes;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.canal_exclusivo' => false]);
        Carbon::setTestNow(Carbon::parse('2026-10-16 14:20', 'America/Sao_Paulo'));
        $this->fakeTelegram();
    }

    private function enviar(string $texto)
    {
        return $this->postJson(route('central-ia.enviar'), ['client_id' => (string) Str::uuid(), 'conteudo' => $texto, 'agente' => 'auto']);
    }

    private function concluirCom(array|string $bloco): void
    {
        $u = auth()->user();
        $job = app(AgentesService::class)->proxima($u, CentralIaRunner::where('user_id', $u->id)->first());
        $json = is_array($bloco) ? json_encode($bloco) : $bloco;
        app(AgentesService::class)->concluir($u, $job['id'], ['status' => 'concluida', 'resposta' => "<<<SECRETARIO\n{$json}\nSECRETARIO>>>"]);
    }

    public function test_ia_propoe_e_a_pessoa_confirma(): void
    {
        $u = $this->dono(telegram: false);
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['codex', 'claude'], 'projetos' => [], 'visto_em' => now()]);
        $this->actingAs($u);
        $this->enviar('me lembra semana que vem, depois da reunião de diretoria, de cobrar o Victor')->assertCreated();
        $this->assertSame(1, CentralIaExecucao::count());
        $this->assertStringContainsString('pedi à sua IA', CentralIaMensagem::where('autor_nome', 'Secretário')->value('conteudo'));

        $this->concluirCom(['tipo' => 'lembrete', 'titulo' => 'cobrar o Victor', 'quando' => '2026-10-21 11:00']);
        $resposta = CentralIaMensagem::latest('id')->value('conteudo');
        $this->assertStringContainsString('qua, 21/10 às 11:00', $resposta);
        $this->assertStringNotContainsString('<<<SECRETARIO', $resposta);
        $this->assertSame(0, SecretarioItem::count(), 'Proposta da IA nunca grava sozinha');

        $this->enviar('sim')->assertCreated();
        $this->assertSame('Cobrar o Victor', SecretarioItem::sole()->titulo);
    }

    public function test_bloco_invalido_ou_data_passada_vira_pergunta(): void
    {
        $u = $this->dono(telegram: false);
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['codex', 'claude'], 'projetos' => [], 'visto_em' => now()]);
        $this->actingAs($u);
        $this->enviar('me lembra mês que vem de renovar o domínio')->assertCreated();
        $this->concluirCom(['tipo' => 'apagar_tudo', 'titulo' => 'x', 'quando' => '2020-01-01 10:00']);
        $this->assertStringContainsString('A IA também não conseguiu', CentralIaMensagem::latest('id')->value('conteudo'));
        $this->assertSame('quando', SecretarioProposta::sole()->falta);
        $this->assertSame(0, SecretarioItem::count());
    }

    public function test_maquina_desligada_pergunta_direto_sem_gastar_execucao(): void
    {
        $u = $this->dono(telegram: false);
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['codex'], 'projetos' => [], 'visto_em' => now()->subHour()]);
        $this->actingAs($u);
        $this->enviar('me lembra semana que vem de cobrar o Victor')->assertCreated();
        $this->assertSame(0, CentralIaExecucao::count());
        $this->assertStringContainsString('Quando devo lembrar você', CentralIaMensagem::where('autor_nome', 'Secretário')->value('conteudo'));
    }
}
