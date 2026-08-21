<?php

namespace Tests\Feature\Sessao;

use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O painel de parede — a conta que fica aberta o dia todo num monitor da sala.
 *
 * O pedido foi isentar a TELA de tarefas do relógio de ociosidade, e ela não
 * serve: a sessão não tem tela. Do quadro, a barra lateral leva a Caixa,
 * Faturamento e Usuários e permissões em um clique — isentar o quadro
 * isentaria a conta inteira. A isenção é da CONTA, e a conta só enxerga o
 * quadro. Ver a migração `2026_08_21_150000_...`.
 */
class PainelDeParedeTest extends TestCase
{
    use RefreshDatabase;

    private function contaDeExibicao(): User
    {
        // `semPerfil`: a fábrica anexa o perfil ADMIN por padrão, e com ele
        // junto o teste provaria o contrário do que investiga.
        $usuario = User::factory()->semPerfil()->create();
        $usuario->perfis()->attach(Perfil::where('slug', 'exibicao')->firstOrFail()->id);

        return $usuario->fresh();
    }

    /** Sem seeder: o deploy roda só `migrate --force`. */
    public function test_a_migracao_sozinha_entrega_o_perfil_utilizavel(): void
    {
        $perfil = Perfil::where('slug', 'exibicao')->first();

        $this->assertNotNull($perfil, 'a migração precisa criar o perfil sozinha');
        $this->assertTrue($perfil->nao_expira_por_ociosidade);

        $tarefas = $perfil->permissoes->firstWhere('recurso', 'tarefas');

        $this->assertNotNull($tarefas, 'perfil sem permissão entra no painel e não abre tela nenhuma');
        $this->assertEquals(1, $tarefas->pivot->ler);

        // Monitor não escreve. É o que sobra de garantia quando alguém encosta
        // no teclado dele.
        $this->assertEquals(0, $tarefas->pivot->incluir);
        $this->assertEquals(0, $tarefas->pivot->editar);
        $this->assertEquals(0, $tarefas->pivot->excluir);
    }

    public function test_a_conta_de_exibicao_abre_o_quadro_e_nada_do_dinheiro(): void
    {
        $usuario = $this->contaDeExibicao();

        $this->assertSame(route('tarefas.index', absolute: false), $usuario->telaInicial());
        $this->actingAs($usuario)->get('/tarefas')->assertOk();

        // As três telas que a barra lateral oferece a um clique de quem tem
        // sessão perene — e que é por elas que a isenção não podia ser da tela.
        foreach (['/centro-controle', '/contas-financeiras', '/usuarios'] as $porta) {
            $this->actingAs($usuario)->get($porta)->assertForbidden();
        }
    }

    public function test_o_menu_dela_nao_oferece_porta_que_nao_abre(): void
    {
        $resposta = $this->actingAs($this->contaDeExibicao())->get('/tarefas');

        $resposta->assertSee('Tarefas');
        $resposta->assertDontSee('Usuários e permissões');
        $resposta->assertDontSee('Faturamento');
        $resposta->assertDontSee('Auditoria');
    }

    /**
     * O quadro não oferece porta que ela não abre.
     *
     * O menu já seguia essa regra; os botões de criar não seguiam — e isso só
     * apareceu quando nasceu o primeiro perfil de LEITURA. Numa parede ninguém
     * clica, mas quem encostar no teclado não pode receber um 403 como
     * resposta: item que leva a 403 é pior que item ausente.
     */
    public function test_o_quadro_dela_nao_oferece_o_que_ela_nao_pode_criar(): void
    {
        $exibicao = $this->actingAs($this->contaDeExibicao())->get('/tarefas');

        $exibicao->assertDontSee('+ Nova tarefa');
        $exibicao->assertDontSee('+ nova tarefa · Enter para criar');

        // E quem PODE criar continua vendo os dois — o portão é a permissão,
        // não a existência do perfil de exibição.
        $comum = $this->actingAs(User::factory()->create())->get('/tarefas');

        $comum->assertSee('+ Nova tarefa');
        $comum->assertSee('+ nova tarefa · Enter para criar');
    }

    public function test_o_relogio_de_ociosidade_nao_roda_nela(): void
    {
        $resposta = $this->actingAs($this->contaDeExibicao())->get('/tarefas');

        $resposta->assertDontSee('const LIMITE_MS', false);
        $resposta->assertDontSee('Continuar conectado');
    }

    /**
     * Mas a volta ao login continua valendo — e é o ponto todo.
     *
     * Se a sessão morrer por outro motivo (servidor reiniciado, sessões
     * limpas), o monitor tem de mostrar a tela de entrada, e não um quadro
     * parado com cara de vivo — que é o defeito que tudo isto veio consertar.
     */
    public function test_mas_a_volta_ao_login_continua_valendo_nela(): void
    {
        $resposta = $this->actingAs($this->contaDeExibicao())->get('/tarefas');

        $resposta->assertSee(route('sessao.encerrar'), false);
        $resposta->assertSee('resposta.status === 401', false);
    }

    public function test_conta_comum_continua_com_o_relogio(): void
    {
        $resposta = $this->actingAs(User::factory()->create())->get('/tarefas');

        $resposta->assertSee('const LIMITE_MS', false);
        $resposta->assertSee('Continuar conectado');
    }
}
