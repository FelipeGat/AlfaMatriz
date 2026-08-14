<?php

namespace Tests\Feature\Redesign;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A tela de entrada.
 *
 * Ela é a primeira impressão do sistema e, quando alguém não consegue entrar,
 * é a única informação disponível. Por isso o selo de estado importa: sem ele,
 * "não entrei" e "o servidor caiu" são indistinguíveis para quem está de fora.
 */
class LoginTest extends TestCase
{
    // A moldura das telas de dentro só se confere com alguém autenticado, e
    // isso passou a valer quando a tela de recuperação — a única irmã do login
    // aberta a visitante — saiu do ar (AC-260).
    use RefreshDatabase;

    /**
     * @spec:AC-060 O login traz a marca e os campos, sem ruído — card centrado,
     * marca centralizada nele, e-mail e senha com mostrar/ocultar, lembrar-me e
     * recuperação de senha, e nada além disso.
     */
    public function test_a_tela_de_entrada_traz_marca_centralizada_e_campos(): void
    {
        $resposta = $this->get(route('login'));

        $resposta->assertOk();
        $this->assertGuest();

        $html = $resposta->getContent();

        // ── A marca do handoff, ícone e wordmark, CENTRALIZADA no card.
        $resposta->assertSee('/icon-matriz-solid.svg', escape: false);
        $resposta->assertSee('/alfamatriz.png', escape: false);
        $resposta->assertDontSee('logo-tile.svg', escape: false);
        $this->assertMatchesRegularExpression(
            '/<a href="\/" class="flex items-center justify-center/',
            file_get_contents(base_path('resources/views/layouts/guest.blade.php')),
            'A marca precisa ficar centralizada no card.'
        );

        // ── Os campos, com o olho de mostrar/ocultar senha.
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringContainsString("showPw ? 'text' : 'password'", $html);
        $this->assertStringContainsString('name="remember"', $html);
        $resposta->assertSee('Lembrar-me', escape: false);

        // A recuperação de senha SAIU da tela (AC-260). A view nunca a
        // desenhou incondicionalmente — ela pergunta por `Route::has` —, então
        // some a rota, some o link. A afirmação aqui é pela ausência do texto,
        // e não por `route('password.request')`: essa chamada agora derruba o
        // teste com "rota não definida" antes de chegar a afirmar coisa alguma.
        $resposta->assertDontSee('Esqueci minha senha', escape: false);
        $resposta->assertDontSee('forgot-password', escape: false);

        // ── Sem ruído: o selo de estado e o texto de apoio saíram a pedido do
        // dono do produto. A tela diz o que é e para de falar.
        $resposta->assertDontSee('Sistemas operacionais', escape: false);
        $resposta->assertDontSee('Acesso restrito', escape: false);
        $resposta->assertSee('acesso somente por convite', escape: false);

        // A checagem de saúde continua de pé — ela serve ao deploy, que é quem
        // sempre dependeu dela; o que saiu foi só o selo na tela.
        $this->get(route('healthz'))->assertOk();

        // ── A tela fala em token: nenhuma cor cravada, senão ela não troca de
        // tema junto com o resto do painel.
        foreach (['resources/views/auth/login.blade.php', 'resources/views/layouts/guest.blade.php'] as $arquivo) {
            $this->assertDoesNotMatchRegularExpression(
                '/#[0-9a-fA-F]{6}\b/',
                file_get_contents(base_path($arquivo)),
                "{$arquivo} tem cor em hexadecimal — ela não vai acompanhar a troca de tema."
            );
        }

        // ── O tema é decidido antes da primeira pintura, como na porta de
        // dentro: senão quem usa o claro leva um flash escuro ao abrir.
        $guest = file_get_contents(base_path('resources/views/layouts/guest.blade.php'));
        $this->assertLessThan(
            strpos($guest, '@vite'),
            strpos($guest, "localStorage.getItem('alfamatriz:tema')"),
            'A decisão de tema precisa vir antes dos assets.'
        );
    }

    /**
     * @spec:AC-060 As demais telas de autenticação herdam a mesma moldura, para
     * a porta de entrada não ter duas caras.
     */
    public function test_as_demais_telas_de_autenticacao_usam_a_mesma_moldura(): void
    {
        // A tela de "esqueci minha senha" era a segunda desta lista e saiu do
        // ar (AC-260). A moldura continua sendo de mais de uma tela, e é isso
        // que o critério cobra — o que mudou é que as irmãs do login agora
        // ficam DEPOIS da porta, não antes dela.
        $resposta = $this->get(route('login'));
        $resposta->assertOk();
        $resposta->assertSee('/icon-matriz-solid.svg', escape: false);
        $resposta->assertSee('Painel interno', escape: false);

        $resposta = $this->actingAs(User::factory()->create())->get(route('password.confirm'));
        $resposta->assertOk();
        $resposta->assertSee('/icon-matriz-solid.svg', escape: false);
        $resposta->assertSee('Painel interno', escape: false);

        // O cadastro público continua desativado — a moldura nova não reabre
        // uma porta que foi fechada de propósito.
        $this->get('/register')->assertNotFound();
    }
}
