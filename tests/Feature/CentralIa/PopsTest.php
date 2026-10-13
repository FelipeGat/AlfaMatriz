<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaPop;
use App\Models\CentralIaRunner;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\AgentesService;
use App\Services\CentralIa\Pops;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** POPs das IAs (FR-030 a FR-033). */
class PopsTest extends TestCase
{
    use RefreshDatabase;

    private function comCentral(): User
    {
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));

        return $u;
    }

    public function test_agente_aprende_o_pop_e_o_proximo_pedido_ja_recebe(): void
    {
        config(['central_ia.canal_exclusivo' => false]);
        $u = $this->comCentral();
        $r = CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['codex'], 'projetos' => ['gestor', 'alfamatriz'], 'visto_em' => now()]);
        $s = app(AgentesService::class);

        $s->pedir($u, 'agende o Vitor amanhã', ['projeto' => 'gestor', 'agente' => 'codex', 'modo' => 'auto', 'instrucao' => 'agende o Vitor amanhã'], 'web', 'p1');
        $job = $s->proxima($u, $r);
        $this->assertSame([], $job['pops']);
        $pop = ['titulo' => 'Agendar técnico', 'conteudo' => "Use AgendaTecnicaService.\nDB_PASSWORD=sintetica-123"];
        $s->concluir($u, $job['id'], ['status' => 'concluida', 'resposta' => "feito\n<<<CENTRAL-IA\n".json_encode(['resumo' => 'ok', 'pop' => $pop])."\nCENTRAL-IA>>>"]);

        $salvo = CentralIaPop::where('user_id', $u->id)->sole();
        $this->assertSame('gestor', $salvo->sistema, 'sem sistema, vale o do projeto');
        $this->assertSame('GPT (Codex)', $salvo->autor);
        $this->assertStringNotContainsString('sintetica-123', $salvo->conteudo);
        $this->assertDatabaseHas('central_ia_eventos', ['user_id' => $u->id, 'tipo' => 'pop_atualizado']);

        // Mesmo sistema: vai junto. Outro projeto que CITA o gestor: também.
        $s->pedir($u, 'remarque o Vitor', ['projeto' => 'gestor', 'agente' => 'codex', 'modo' => 'auto', 'instrucao' => 'remarque o Vitor'], 'web', 'p2');
        $this->assertSame('Agendar técnico', $s->proxima($u, $r)['pops'][0]['titulo']);
        $this->assertSame([], app(Pops::class)->paraPedido($u, 'alfamatriz', 'corrija a tela de clientes'));
        $this->assertCount(1, app(Pops::class)->paraPedido($u, 'alfamatriz', 'faça igual ao Gestor'));
    }

    public function test_versao_guardada_e_so_o_dono_ve_e_edita(): void
    {
        $u = $this->comCentral();
        $pops = app(Pops::class);
        $p = $pops->gravar($u, 'gestor', 'Dar baixa', 'v1', 'Claude', 10);
        $pops->gravar($u, 'gestor', 'Dar baixa', 'v2', 'GPT (Codex)', 11);
        $this->assertSame(2, $p->fresh()->versao);
        $this->assertSame('v1', $p->versoes()->sole()->conteudo);

        $this->actingAs($u)->putJson(route('central-ia.pops.salvar', $p->id), ['sistema' => 'gestor', 'titulo' => 'Dar baixa', 'conteudo' => 'v3 pela pessoa'])->assertOk();
        $this->assertSame(3, $p->fresh()->versao);
        $this->getJson(route('central-ia.painel'))->assertOk()->assertJsonPath('pops.0.conteudo', 'v3 pela pessoa');

        $outro = $this->comCentral();
        // O factory padrão já nasce administrador: nem assim vê os POPs de outra conta.
        $this->actingAs($outro)->getJson(route('central-ia.painel'))->assertOk()->assertJsonCount(0, 'pops');
        $this->putJson(route('central-ia.pops.salvar', $p->id), ['sistema' => 'gestor', 'titulo' => 'x', 'conteudo' => 'x'])->assertNotFound();
        $this->deleteJson(route('central-ia.pops.apagar', $p->id))->assertNotFound();
        $this->assertSame([], $pops->paraPedido($outro, 'gestor', 'qualquer'));

        $this->actingAs($u)->deleteJson(route('central-ia.pops.apagar', $p->id))->assertOk();
        $this->assertSame(0, CentralIaPop::count());
    }
}
