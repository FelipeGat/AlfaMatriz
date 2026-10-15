<?php

namespace Tests\Feature\CentralIa;

use App\Models\{CentralIaExecucao, CentralIaPreferencia, CentralIaRunner, CentralIaTarefa, Perfil, User};
use App\Services\CentralIa\{AgentesService, ConversaService, Eventos, Governanca, Preferencias};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GovernancaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `$admin`: perfil Administrador além do "Central de IA" — quem administra
     * a Central (T-374; antes era o e-mail do `CENTRAL_IA_DONO`).
     */
    private function pessoa(bool $admin = false): User
    {
        $u = User::factory()->create();
        $u->perfis()->sync(array_filter([Perfil::where('slug', 'central_ia')->value('id'), $admin ? Perfil::where('slug', 'admin')->value('id') : null]));
        return $u->fresh();
    }

    public function test_quem_administra_e_o_admin_que_usa_a_central_e_nao_um_email(): void
    {
        $felipe = $this->pessoa(true);
        $rossini = $this->pessoa(true);
        $alexandre = $this->pessoa();
        // O e-mail no .env não dá nem tira nada: a regra é o perfil.
        config(['central_ia.dono' => $felipe->email]);
        $this->actingAs($rossini)->getJson(route('central-ia.administracao', ['usuario' => $alexandre->id]))->assertOk();
        // Admin administra qualquer conta, inclusive a de outro admin (T-368).
        $this->getJson(route('central-ia.administracao', ['usuario' => $felipe->id]))->assertOk();
        $this->putJson(route('central-ia.usuarios.politica', $alexandre->id), $this->politica())->assertOk();
        $this->getJson(route('central-ia.relatorio', ['inicio' => now()->toDateString(), 'fim' => now()->toDateString(), 'usuario' => $felipe->id]))->assertOk();
        // A própria conta não aparece na lista: é dos Ajustes dela.
        $painel = $this->getJson(route('central-ia.painel'))->assertOk()->assertJsonPath('administracao.permitida', true);
        $ids = array_column($painel->json('administracao.usuarios'), 'id');
        $this->assertNotContains($rossini->id, $ids);
        $this->assertContains($felipe->id, $ids);
        $this->assertContains($alexandre->id, $ids);
        $this->putJson(route('central-ia.usuarios.politica', $rossini->id), $this->politica())->assertUnprocessable();
        $this->assertFalse(app(Preferencias::class)->ler($rossini)['execucao_bloqueada'], 'quem administra não é bloqueado');
    }

    public function test_admin_sem_o_perfil_da_central_nao_administra_a_central(): void
    {
        // T-368: o Admin só usa a Central se tiver o perfil "Central de IA".
        $admin = User::factory()->create();
        $membro = $this->pessoa();
        $this->actingAs($admin)->getJson(route('central-ia.administracao', ['usuario' => $membro->id]))->assertForbidden();
        $this->putJson(route('central-ia.usuarios.politica', $membro->id), $this->politica())->assertForbidden();
    }

    public function test_lista_so_traz_quem_usa_a_central(): void
    {
        $admin = $this->pessoa(true); $membro = $this->pessoa();
        $semCentral = User::factory()->semPerfil()->create();
        $ids = array_column($this->actingAs($admin)->getJson(route('central-ia.painel'))->json('administracao.usuarios'), 'id');
        $this->assertContains($membro->id, $ids);
        $this->assertNotContains($semCentral->id, $ids);
    }

    /** Revisão da T-374: o painel chama a lista a cada ~5 s; era uma consulta por conta. */
    public function test_a_lista_sai_numa_consulta_so_qualquer_que_seja_o_numero_de_contas(): void
    {
        $admin = $this->pessoa(true);
        $contar = function () use ($admin) {
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            $lista = app(Governanca::class)->usuarios($admin);
            $n = count(\Illuminate\Support\Facades\DB::getQueryLog());
            \Illuminate\Support\Facades\DB::disableQueryLog();

            return [$n, count($lista)];
        };
        $this->pessoa();
        $contar(); // o perfil do próprio admin fica em cache na conta
        [$antes, $listadosAntes] = $contar();
        $this->assertSame(1, $antes);
        foreach (range(1, 5) as $_) { $this->pessoa(); User::factory()->semPerfil()->create(); }
        [$depois, $listados] = $contar();
        $this->assertSame($listadosAntes + 5, $listados, 'entram as 5 que usam a Central, e só elas');
        $this->assertSame($antes, $depois);
    }

    public function test_admin_libera_a_execucao_de_outra_conta(): void
    {
        $dono = $this->pessoa(true); $membro = $this->pessoa();
        $this->assertTrue(app(Preferencias::class)->ler($membro)['execucao_bloqueada'], 'conta nova nasce bloqueada até um admin liberar');
        $this->actingAs($dono)->putJson(route('central-ia.usuarios.politica', $membro->id), $this->politica() + ['execucao_bloqueada' => false])
            ->assertOk()->assertJsonPath('politica.execucao_bloqueada', false);
        $this->assertFalse(app(Preferencias::class)->ler($membro)['execucao_bloqueada']);
    }

    private function politica(): array
    {
        return ['revisao' => 0, 'habilitados' => ['claude' => true,'codex' => true,'copilot' => false],
            'pedidos_dia' => ['claude' => 2,'codex' => 3,'copilot' => 0], 'limite_total' => 4, 'paralelo' => 1];
    }

    public function test_membro_nao_pode_consultar_nem_modificar_outro_usuario(): void
    {
        $membro = $this->pessoa(); $outro = $this->pessoa();
        $this->actingAs($membro)->getJson(route('central-ia.administracao', ['usuario' => $outro->id]))->assertForbidden();
        $this->putJson(route('central-ia.usuarios.politica', $outro->id), $this->politica())->assertForbidden();
        $this->getJson(route('central-ia.painel'))->assertOk()->assertJsonPath('administracao.permitida', false)->assertJsonCount(0, 'administracao.usuarios');
    }

    public function test_admin_recebe_apenas_metadados_sem_prompts_historico_ou_erros_privados(): void
    {
        $admin = $this->pessoa(true); $membro = $this->pessoa();
        $t = CentralIaTarefa::create(['user_id' => $membro->id, 'projeto' => 'projeto-privado', 'titulo' => 'SEGREDO-TITULO', 'objetivo' => 'SEGREDO-OBJETIVO']);
        $m = app(ConversaService::class)->registrarDoAgente($membro, 'Central', 'SEGREDO-CONVERSA', projeto: $t->projeto, tarefaId: $t->id);
        CentralIaExecucao::create(['user_id' => $membro->id, 'pedido_id' => $m->id, 'tarefa_id' => $t->id, 'projeto' => $t->projeto, 'agente' => 'claude', 'modo' => 'analisar', 'instrucao' => 'SEGREDO-INSTRUCAO', 'erro' => 'SEGREDO-ERRO']);
        Eventos::registrar($membro, 'pedido', 'SEGREDO-EVENTO');
        $r = $this->actingAs($admin)->getJson(route('central-ia.administracao', ['usuario' => $membro->id]))->assertOk()->assertJsonCount(1, 'execucoes')->assertJsonPath('uso_hoje.claude', 1);
        $this->assertStringNotContainsString('SEGREDO', $r->getContent());
        $this->assertStringNotContainsString('projeto-privado', $r->getContent());
        $this->getJson(route('central-ia.mensagens', ['projeto' => $t->projeto, 'tarefa' => $t->id]))->assertOk()->assertJsonCount(0, 'mensagens');
    }

    public function test_politica_admin_persiste_na_conta_alvo_e_membro_nao_pode_remover(): void
    {
        $admin = $this->pessoa(true); $membro = $this->pessoa();
        $this->actingAs($admin)->putJson(route('central-ia.usuarios.politica', $membro->id), $this->politica())->assertOk()->assertJsonPath('politica.execucao_bloqueada', true)->assertJsonPath('politica.deploy', false)->assertJsonPath('politica.onedrive', false);
        $r = app(Preferencias::class)->ler($membro);
        $r['habilitados']['copilot'] = true; $r['pedidos_dia']['claude'] = 999;
        $r['governanca'] = ['execucao_bloqueada' => false];
        $this->actingAs($membro)->putJson(route('central-ia.preferencias'), $r)->assertOk();
        $efetiva = app(Preferencias::class)->ler($membro);
        $this->assertTrue($efetiva['execucao_bloqueada']);
        $this->assertFalse($efetiva['habilitados']['copilot']);
        $this->assertSame(2, $efetiva['pedidos_dia']['claude']);
        $this->assertFalse(app(Preferencias::class)->pode($membro, 'claude'));
        $this->assertNull(CentralIaPreferencia::where('user_id', $admin->id)->first());
        $registro = CentralIaPreferencia::where('user_id', $membro->id)->firstOrFail();
        $regras = $registro->regras;
        $regras['governanca']['pedidos_dia']['claude'] = null;
        $regras['governanca']['limite_total'] = null;
        $regras['limite_total'] = 7;
        $registro->update(['regras' => $regras]);
        $efetiva = app(Preferencias::class)->ler($membro);
        $this->assertSame(999, $efetiva['pedidos_dia']['claude']);
        $this->assertSame(7, $efetiva['limite_total']);
    }

    public function test_deploy_onedrive_e_conflitos_nao_podem_ser_liberados_por_payload(): void
    {
        $admin = $this->pessoa(true); $membro = $this->pessoa(); $r = $this->politica();
        $this->actingAs($admin)->putJson(route('central-ia.usuarios.politica', $membro->id), $r + ['deploy' => true])->assertUnprocessable();
        $this->putJson(route('central-ia.usuarios.politica', $membro->id), $r + ['onedrive' => true])->assertUnprocessable();
        $this->putJson(route('central-ia.usuarios.politica', $membro->id), $r)->assertOk();
        $this->putJson(route('central-ia.usuarios.politica', $membro->id), $r)->assertConflict();
    }

    public function test_membro_sem_politica_nao_recebe_trabalho_no_executor_legado(): void
    {
        $this->pessoa(true);
        $membro = $this->pessoa();
        $t = CentralIaTarefa::create(['user_id' => $membro->id, 'projeto' => 'teste', 'titulo' => 'Teste', 'objetivo' => 'Teste']);
        $m = app(ConversaService::class)->registrarDoAgente($membro, 'Central', 'Teste', projeto: 'teste', tarefaId: $t->id);
        CentralIaExecucao::create(['user_id' => $membro->id, 'pedido_id' => $m->id, 'tarefa_id' => $t->id, 'projeto' => 'teste', 'agente' => 'claude', 'modo' => 'implementar', 'instrucao' => 'Teste']);
        $r = CentralIaRunner::create(['user_id' => $membro->id, 'maquina' => 'teste', 'agentes' => ['claude'], 'projetos' => ['teste'], 'visto_em' => now()]);
        $this->assertNull(app(AgentesService::class)->proxima($membro, $r));
        $this->assertFalse(app(Preferencias::class)->pode($membro, 'claude'));
    }
    public function test_relatorio_filtra_periodo_ia_e_protege_consumo_de_outra_conta(): void
    {
        $admin = $this->pessoa(true); $membro = $this->pessoa();
        CentralIaRunner::create(['user_id' => $membro->id, 'maquina' => 'teste', 'agentes' => ['claude'], 'projetos' => ['PRIVADO'], 'visto_em' => now(),
            'consumo' => ['linhas' => [
                ['dia' => '2026-10-10', 'agente' => 'claude', 'projeto' => 'PRIVADO', 'entrada' => 5, 'saida' => 2],
                ['dia' => '2026-10-11', 'agente' => 'claude', 'projeto' => 'PRIVADO', 'entrada' => 9, 'saida' => 3],
                ['dia' => '2026-10-11', 'agente' => 'codex', 'projeto' => 'PRIVADO', 'entrada' => 99, 'saida' => 3],
            ]]]);
        Eventos::registrar($membro, 'pedido', 'SEGREDO');
        $f = ['inicio' => '2026-10-11', 'fim' => '2026-10-11', 'usuario' => $membro->id, 'agente' => 'claude'];
        $r = $this->actingAs($admin)->getJson(route('central-ia.relatorio', $f))->assertOk()->assertJsonCount(1, 'consumo')->assertJsonPath('consumo.0.entrada', 9);
        $this->assertStringNotContainsString('PRIVADO', $r->getContent());
        $this->assertStringNotContainsString('SEGREDO', $r->getContent());
        $this->actingAs($membro)->getJson(route('central-ia.relatorio', $f + []))->assertOk();
        $f['usuario'] = $admin->id;
        // O membro não vê o relatório de admin nenhum (nem de ninguém).
        $this->getJson(route('central-ia.relatorio', $f))->assertForbidden();
        $f['usuario'] = $membro->id; $f['fim'] = '2026-10-09';
        $this->getJson(route('central-ia.relatorio', $f))->assertUnprocessable();
    }

}
