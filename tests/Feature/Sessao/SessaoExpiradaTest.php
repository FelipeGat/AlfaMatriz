<?php

namespace Tests\Feature\Sessao;

use App\Http\Controllers\SessaoController;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A sessão que vence com a tela aberta.
 *
 * O painel desenha a página uma vez e ela fica aberta; a sessão continua
 * correndo no servidor. Quando vencia, o HTML na tela não sabia de nada: o
 * primeiro clique voltava uma faixa vermelha pedindo F5, e quem estava numa
 * tela sem atualização automática ficava olhando um painel morto com cara de
 * vivo. Ver `resources/views/layouts/sessao.blade.php`.
 */
class SessaoExpiradaTest extends TestCase
{
    use RefreshDatabase;

    /** O CSRF se desliga sozinho na suíte; sem desfazer isso, o cenário do
     *  token vencido — que é o do mundo real — não acontece aqui. */
    private function comCsrfLigado(): void
    {
        $this->app->instance(ValidateCsrfToken::class, new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    public function test_o_estado_da_sessao_diz_quando_ela_vence(): void
    {
        Carbon::setTestNow('2026-08-21 10:00:00');
        config(['session.lifetime' => 120]);

        $response = $this->actingAs(User::factory()->create())->getJson('/sessao');

        $response->assertOk();
        $response->assertExactJson([
            'expira_em' => Carbon::parse('2026-08-21 12:00:00')->getTimestampMs(),
        ]);
    }

    public function test_o_estado_da_sessao_recusa_quem_nao_esta_logado(): void
    {
        // O 401 é o que a tela observa para saber que acabou: é ele que dispara
        // a ida ao login em qualquer `fetch` do painel.
        $this->getJson('/sessao')->assertUnauthorized();
    }

    public function test_encerrar_derruba_a_sessao_e_volta_ao_login_dizendo_por_que(): void
    {
        $response = $this->actingAs(User::factory()->create())->post('/sessao/encerrar');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors(['sessao' => SessaoController::AVISO_DE_EXPIRACAO]);
        $this->assertGuest();
    }

    /**
     * Os dois caminhos até o login dizem a MESMA frase.
     *
     * Encerrar por ociosidade e esbarrar num token vencido são o mesmo
     * acontecimento visto de dois lugares — a tela nem sabe em qual deles está
     * quando manda o formulário. Ler duas explicações diferentes para a mesma
     * coisa é o tipo de detalhe que faz desconfiar do sistema.
     */
    public function test_o_token_vencido_chega_ao_login_com_a_mesma_frase(): void
    {
        $this->comCsrfLigado();

        $response = $this->actingAs(User::factory()->create())
            ->post('/sessao/encerrar', ['_token' => 'token-de-uma-sessao-que-ja-morreu']);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors(['sessao' => SessaoController::AVISO_DE_EXPIRACAO]);
    }

    public function test_a_moldura_do_painel_traz_o_relogio_da_sessao(): void
    {
        config(['session.lifetime' => 120]);

        $response = $this->actingAs(User::factory()->create())->get('/profile');

        $response->assertOk();
        $response->assertSee(route('sessao.encerrar'), false);
        $response->assertSee('Continuar conectado');
    }

    /**
     * O limite de ociosidade SAI da vida da sessão — não é número escolhido no
     * JavaScript. Quem mexer em `SESSION_LIFETIME` move os dois juntos, e a
     * margem entre eles é o que garante que o aviso apareça com a sessão ainda
     * viva: sem ela o botão "Continuar conectado" não teria o que renovar.
     */
    public function test_o_limite_de_ociosidade_acompanha_a_vida_da_sessao(): void
    {
        $usuario = User::factory()->create();

        config(['session.lifetime' => 120]);
        $this->actingAs($usuario)->get('/profile')
            ->assertSee('const LIMITE_MS = 110 * 60 * 1000;', false);

        // Sessão curta: a margem de dez minutos não cabe, e encolhe para um
        // quarto da vida em vez de zerar o limite — ou negativá-lo.
        config(['session.lifetime' => 20]);
        $this->actingAs($usuario)->get('/profile')
            ->assertSee('const LIMITE_MS = 15 * 60 * 1000;', false);
    }
}
