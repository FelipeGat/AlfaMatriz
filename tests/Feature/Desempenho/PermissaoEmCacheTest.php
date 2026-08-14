<?php

namespace Tests\Feature\Desempenho;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\TarefaComentario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A permissão é a mesma pergunta o tempo todo, e ela ia ao banco a cada vez:
 * 17 consultas fixas por página — 14 links da sidebar, o sino, a linha do tempo
 * — mais 3 por card do quadro. Num quadro com 120 tarefas eram 379 das 397
 * consultas da tela.
 *
 * Os testes medem a CONTAGEM, não o tempo: tempo de parede depende da máquina e
 * transforma a suíte num oráculo instável. Número de consultas é determinístico
 * e é exatamente o que regrediria se alguém voltasse a perguntar por item.
 */
class PermissaoEmCacheTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Quantas consultas a requisição fez, e quantas delas foram de permissão.
     *
     * @return array{total: int, permissao: int}
     */
    private function consultasDe(callable $requisicao): array
    {
        $sqls = [];

        DB::listen(function ($consulta) use (&$sqls): void {
            $sqls[] = $consulta->sql;
        });

        $requisicao();

        // As quatro tabelas do modelo de acesso. `perfil_user` e
        // `perfil_permissao` entram porque a checagem antiga era um `exists`
        // com dois joins: contar só `permissoes` deixaria a regressão passar.
        $daPermissao = array_filter($sqls, fn (string $sql) => (bool) preg_match(
            '/\b(perfis|permissoes|perfil_user|perfil_permissao)\b/', $sql
        ));

        return ['total' => count($sqls), 'permissao' => count($daPermissao)];
    }

    /** Um quadro com `$quantas` tarefas, cada uma com conversa e sistema. */
    private function quadroCom(int $quantas, User $usuario): void
    {
        $sistema = Sistema::factory()->create();

        Tarefa::factory()->count($quantas)->create([
            'criado_por_id' => $usuario->id,
            'sistema_id' => $sistema->id,
            'status' => 'em_desenvolvimento',
        ])->each(fn (Tarefa $tarefa) => TarefaComentario::create([
            'tarefa_id' => $tarefa->id,
            'autor_id' => $usuario->id,
            'corpo' => 'Comentário de volume.',
        ]));
    }

    /**
     * @spec:AC-236 O quadro não repergunta a permissão a cada card: com 120
     * tarefas, as consultas de perfil e permissão não passam de 2.
     */
    public function test_quadro_nao_repergunta_permissao_por_card(): void
    {
        $usuario = User::factory()->create();
        $this->quadroCom(120, $usuario);

        $medido = $this->consultasDe(
            fn () => $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()
        );

        $this->assertLessThanOrEqual(2, $medido['permissao'],
            "O quadro com 120 tarefas fez {$medido['permissao']} consultas de permissão — ".
            'a permissão voltou a ser perguntada por card.');
    }

    /**
     * @spec:AC-237 O custo da tela não cresce com o volume: o quadro com 120
     * tarefas faz no máximo 2 consultas a mais que o quadro com 5.
     */
    public function test_custo_do_quadro_nao_cresce_com_o_volume(): void
    {
        $poucas = User::factory()->create();
        $this->quadroCom(5, $poucas);

        $com5 = $this->consultasDe(
            fn () => $this->actingAs($poucas)->get(route('tarefas.index'))->assertOk()
        );

        // Zera o quadro e refaz com volume: o mesmo usuário, para que a
        // diferença medida seja só a das tarefas.
        Tarefa::query()->forceDelete();
        $this->quadroCom(120, $poucas);

        $com120 = $this->consultasDe(
            fn () => $this->actingAs($poucas)->get(route('tarefas.index'))->assertOk()
        );

        $this->assertLessThanOrEqual(2, $com120['total'] - $com5['total'],
            "O quadro passou de {$com5['total']} para {$com120['total']} consultas ".
            'ao sair de 5 para 120 tarefas — alguma coisa consulta por card.');
    }

    /**
     * @spec:AC-238 A sidebar decide os links sem uma consulta por item: em
     * qualquer tela, as consultas de permissão não passam de 2.
     */
    public function test_sidebar_decide_os_links_sem_uma_consulta_por_item(): void
    {
        $usuario = User::factory()->create();

        $medido = $this->consultasDe(
            fn () => $this->actingAs($usuario)->get(route('clientes.index'))->assertOk()
        );

        $this->assertLessThanOrEqual(2, $medido['permissao'],
            "A tela fez {$medido['permissao']} consultas de permissão — ".
            'a sidebar voltou a perguntar link por link.');
    }

    /**
     * @spec:AC-239 O cache não afrouxa recusa nenhuma: quem não alcança o
     * recurso continua sem o item no menu e continua tomando 403 na rota.
     */
    public function test_quem_nao_tem_permissao_continua_sem_ver_e_sem_entrar(): void
    {
        // `membro` só alcança tarefas — nem clientes, nem usuários, nem painéis.
        $membro = User::factory()->membro()->create();

        $quadro = $this->actingAs($membro)->get(route('tarefas.index'))->assertOk();

        $quadro->assertSee('Tarefas');
        $quadro->assertDontSee('Revendas e clientes');
        $quadro->assertDontSee('Usuários e permissões');
        $quadro->assertDontSee('Auditoria');

        $this->actingAs($membro)->get(route('clientes.index'))->assertForbidden();
        $this->actingAs($membro)->get(route('usuarios.index'))->assertForbidden();
    }
}
