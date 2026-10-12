<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaRunner;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\AgentesService;
use App\Services\CentralIa\Preferencias;
use App\Services\CentralIa\Roteador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Níveis de custo (FR-027). Dono, 12/10/2026: "quero um relatório da minha
 * agenda, é somente para ler… não preciso usar o fable do claude".
 */
class NiveisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.canal_exclusivo' => false]);
    }

    private function comMaquina(): User
    {
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['claude', 'codex', 'copilot'], 'projetos' => ['alfahome'], 'visto_em' => now()]);

        return $u;
    }

    private function pedir(User $u, string $texto, string $projeto = '', array $extra = []): array
    {
        return app(AgentesService::class)->pedir($u, $texto, $extra + ['projeto' => $projeto, 'agente' => 'auto', 'modo' => 'auto', 'instrucao' => $texto],
            'web', (string) \Illuminate\Support\Str::uuid())->roteamento;
    }

    public function test_cada_pedido_vai_ao_menor_nivel_que_resolve(): void
    {
        $u = $this->comMaquina();

        $r = $this->pedir($u, 'quais são meus compromissos de hoje?');
        $this->assertSame('sem_ia', $r['nivel']);
        $this->assertNull($r['modelo']);
        $this->assertStringContainsString('sem IA', $r['motivo']);

        $r = $this->pedir($u, 'me faça um resumo da agenda da semana');
        $this->assertSame('leve', $r['nivel']);
        $this->assertSame(config("central_ia.niveis.leve.{$r['agente']}.modelo"), $r['modelo']);

        $r = $this->pedir($u, 'revise o módulo de faturamento', 'alfahome');
        $this->assertSame('medio', $r['nivel']);

        $r = $this->pedir($u, 'implemente o filtro de status', 'alfahome');
        $this->assertSame('forte', $r['nivel']);
        $this->assertSame('implementar', $r['modo'] ?? 'implementar');
    }

    public function test_pessoa_escolhe_o_nivel_e_sem_ia_so_vale_com_dado_oficial(): void
    {
        $u = $this->comMaquina();
        $this->assertSame('forte', $this->pedir($u, 'me faça um resumo da agenda', '', ['nivel' => 'forte'])['nivel']);
        // "Sem IA" num pedido que não é de agenda não tem o que mostrar: vira leve.
        $this->assertSame('leve', $this->pedir($u, 'quais tarefas estão atrasadas?', '', ['nivel' => 'sem_ia'])['nivel']);
    }

    public function test_modelos_por_conta_e_payload_do_runner(): void
    {
        $u = $this->comMaquina();
        $regras = app(Preferencias::class)->ler($u);
        $regras['niveis']['leve']['codex'] = ['modelo' => 'gpt-6-luna', 'esforco' => 'low'];
        $regras['padroes']['investigacao'] = 'codex';
        $this->actingAs($u)->putJson(route('central-ia.preferencias'), $regras)->assertOk();
        $this->assertSame(['modelo' => 'gpt-6-luna', 'esforco' => 'low'], app(Preferencias::class)->modelo($u, 'leve', 'codex'));

        $regras = app(Preferencias::class)->ler($u);
        $regras['niveis']['leve']['codex']['modelo'] = 'x; rm -rf ~';
        $this->putJson(route('central-ia.preferencias'), $regras)->assertUnprocessable();

        $this->pedir($u, 'liste os projetos com pendências', 'alfahome', ['agente' => 'codex']);
        $job = app(AgentesService::class)->proxima($u, CentralIaRunner::first());
        $this->assertArrayHasKey('nivel', $job);
        $this->assertArrayHasKey('modelo', $job);
    }

    public function test_roteador_separa_leitura_de_raciocinio(): void
    {
        $r = app(Roteador::class);
        $agenda = [['tipo' => 'agenda']];
        $this->assertSame('sem_ia', $r->nivel('minha agenda de amanhã', 'investigacao', 'analisar', $agenda));
        $this->assertSame('leve', $r->nivel('resuma minha agenda de amanhã', 'investigacao', 'analisar', $agenda));
        $this->assertSame('medio', $r->nivel('por que o deploy falhou?', 'investigacao', 'analisar'));
        $this->assertSame('leve', $r->nivel('quais branches estão abertas?', 'investigacao', 'analisar'));
        $this->assertSame('forte', $r->nivel('qualquer coisa', 'implementacao', 'implementar'));
    }
}
