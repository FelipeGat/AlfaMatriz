<?php

namespace Tests\Feature\Redesign;

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
    /**
     * @spec:AC-060 O login traz a marca, os campos e o estado do sistema — card
     * centrado, e-mail e senha com mostrar/ocultar, lembrar-me, recuperação e
     * o selo alimentado pela checagem de saúde que já existe.
     */
    public function test_a_tela_de_entrada_traz_marca_campos_e_estado_do_sistema(): void
    {
        $resposta = $this->get(route('login'));

        $resposta->assertOk();
        $this->assertGuest();

        $html = $resposta->getContent();

        // ── A marca do handoff, ícone e wordmark.
        $resposta->assertSee('/icon-matriz.svg', escape: false);
        $resposta->assertSee('/alfamatriz.png', escape: false);
        $resposta->assertDontSee('logo-tile.svg', escape: false);

        // ── Os campos, com o olho de mostrar/ocultar senha.
        $resposta->assertSee('Acesso restrito à equipe AlfaMatriz.', escape: false);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringContainsString("showPw ? 'text' : 'password'", $html);
        $this->assertStringContainsString('name="remember"', $html);
        $resposta->assertSee('Lembrar-me', escape: false);
        $resposta->assertSee(route('password.request'), escape: false);

        // ── O selo de estado, alimentado pela rota de saúde do deploy.
        $resposta->assertSee(route('healthz'), escape: false);
        $this->assertStringContainsString("ok ? 'Sistemas operacionais' : 'Sistema com instabilidade'", $html);
        $resposta->assertSee('acesso somente por convite', escape: false);

        // ── E a checagem que alimenta o selo responde de verdade, sem sessão.
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
        foreach ([route('login'), route('password.request')] as $url) {
            $resposta = $this->get($url);

            $resposta->assertOk();
            $resposta->assertSee('/icon-matriz.svg', escape: false);
            $resposta->assertSee('Painel interno', escape: false);
        }

        // O cadastro público continua desativado — a moldura nova não reabre
        // uma porta que foi fechada de propósito.
        $this->get('/register')->assertNotFound();
    }
}
