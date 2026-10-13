<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaRunner;
use App\Models\CentralIaTarefa;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\AgentesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chats Business, fase 4: a pessoa vê e corrige a memória do assunto, e a
 * correção dela prevalece sobre o que um agente devolver depois (FR-005, FR-006).
 */
class MemoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.canal_exclusivo' => false]);
    }

    private function comCentral(): User
    {
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);

        return $u;
    }

    /** Um pedido no assunto que o agente conclui com este bloco. */
    private function rodada(User $u, int $id, array $bloco, string $cid): array
    {
        $svc = app(AgentesService::class);
        $e = $svc->pedir($u, 'x', ['projeto' => '', 'tarefa_id' => $id, 'agente' => 'claude', 'modo' => 'analisar', 'instrucao' => "pedido {$cid}"], 'web', $cid);
        $job = $svc->proxima($u, CentralIaRunner::where('user_id', $u->id)->first());
        $svc->concluir($u, $e->id, ['status' => 'concluida', 'resposta' => "ok\n<<<CENTRAL-IA\n".json_encode($bloco + ['resumo' => 'ok'])."\nCENTRAL-IA>>>"]);

        return $job;
    }

    public function test_ver_editar_e_apagar_e_a_correcao_prevalece(): void
    {
        $u = $this->comCentral();
        $id = $this->actingAs($u)->postJson(route('central-ia.assuntos.criar'), ['titulo' => 'Comercial VixCard'])->json('id');
        $this->rodada($u, $id, ['decisoes' => ['Proposta sempre com valores', 'Proposta em PDF'],
            'mapa' => [['texto' => 'Propostas', 'caminho' => '~/OneDrive/Propostas', 'etapa' => 'rascunho']], 'pendencias' => ['Ligar para o Lorenzo']], 'r1');

        $m = $this->getJson(route('central-ia.assuntos.memoria', $id))->assertOk();
        $this->assertSame('Proposta sempre com valores', $m->json('listas.decisoes.0.texto'));
        $this->assertSame('~/OneDrive/Propostas', $m->json('listas.mapa.0.caminho'));

        // Apaga uma decisão errada e corrige a etapa do mapa.
        $this->putJson(route('central-ia.assuntos.memoria.salvar', $id), ['lista' => 'decisoes', 'indice' => 0, 'acao' => 'apagar', 'antes' => 'Proposta sempre com valores'])
            ->assertOk()->assertJsonCount(1, 'listas.decisoes');
        $this->putJson(route('central-ia.assuntos.memoria.salvar', $id), ['lista' => 'mapa', 'indice' => 0, 'acao' => 'editar', 'antes' => 'Propostas',
            'texto' => 'Propostas da VixCard', 'caminho' => '/mnt/c/Users/x/OneDrive/Propostas', 'etapa' => 'enviada ao cliente'])
            ->assertOk()->assertJsonPath('listas.mapa.0.agente', 'Você')->assertJsonPath('correcoes.0.acao', 'editar');

        // O agente devolve a decisão apagada de novo: não volta.
        $job = $this->rodada($u, $id, ['decisoes' => ['Proposta sempre com valores']], 'r2');
        $this->assertStringNotContainsString('Proposta sempre com valores', $job['contexto'], 'apagada não vai no pedido seguinte');
        $this->assertStringContainsString('Propostas da VixCard (/mnt/c/Users/x/OneDrive/Propostas) — etapa: enviada ao cliente', $job['contexto']);
        $decisoes = array_column(CentralIaTarefa::find($id)->dossie['decisoes'], 'texto');
        $this->assertNotContains('Proposta sempre com valores', $decisoes, 'nem volta para a memória');
    }

    public function test_item_que_mudou_no_meio_recusa_e_lista_invalida_tambem(): void
    {
        $u = $this->comCentral();
        $id = $this->actingAs($u)->postJson(route('central-ia.assuntos.criar'), ['titulo' => 'Obras'])->json('id');
        $this->rodada($u, $id, ['pendencias' => ['Medir a laje']], 'r1');

        $this->putJson(route('central-ia.assuntos.memoria.salvar', $id), ['lista' => 'pendencias', 'indice' => 0, 'acao' => 'apagar', 'antes' => 'outro texto'])->assertStatus(409);
        $this->putJson(route('central-ia.assuntos.memoria.salvar', $id), ['lista' => 'ponto', 'indice' => 0, 'acao' => 'apagar', 'antes' => 'x'])->assertUnprocessable();
        $this->assertSame(['Medir a laje'], CentralIaTarefa::find($id)->dossie['pendencias']);
    }

    public function test_memoria_de_outra_conta_e_404_e_tarefa_comum_nao_e_assunto(): void
    {
        $u = $this->comCentral();
        $id = $this->actingAs($u)->postJson(route('central-ia.assuntos.criar'), ['titulo' => 'Comercial'])->json('id');
        $outro = $this->comCentral();
        $this->actingAs($outro)->getJson(route('central-ia.assuntos.memoria', $id))->assertNotFound();
        $this->putJson(route('central-ia.assuntos.memoria.salvar', $id), ['lista' => 'decisoes', 'indice' => 0, 'acao' => 'apagar', 'antes' => ''])->assertNotFound();

        $tarefa = CentralIaTarefa::create(['user_id' => $u->id, 'projeto' => '', 'titulo' => 't', 'objetivo' => 't', 'ultima_atividade_em' => now()]);
        $this->actingAs($u)->getJson(route('central-ia.assuntos.memoria', $tarefa->id))->assertNotFound();
    }
}
