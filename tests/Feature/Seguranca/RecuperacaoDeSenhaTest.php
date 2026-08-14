<?php

namespace Tests\Feature\Seguranca;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A recuperação de senha por e-mail não sai do controller nem da view: sai da
 * rota. Em produção o `MAIL_MAILER` é `log`, então o e-mail nunca chegaria —
 * manter a rota no ar só para 404 silencioso serviria de porta para um link
 * de redefinição parar num arquivo de texto no dia em que o log baixasse de
 * nível.
 */
class RecuperacaoDeSenhaTest extends TestCase
{
    /** @spec:AC-260 A tela de login não oferece mais recuperação de senha. */
    public function test_login_nao_tem_link_de_recuperacao_de_senha(): void
    {
        $this->assertFalse(Route::has('password.request'), 'A rota password.request ainda existe — a view voltaria a mostrar o link.');

        $view = file_get_contents(resource_path('views/auth/login.blade.php'));

        $this->assertStringContainsString(
            "Route::has('password.request')",
            $view,
            'A view de login parou de condicionar o link à rota — sem a rota, o link some sozinho.'
        );
    }

    /** @spec:AC-261 Os endereços de recuperação deixam de existir (GET). */
    public function test_forgot_password_responde_404(): void
    {
        $this->get('/forgot-password')->assertNotFound();
    }

    /** @spec:AC-261 Os endereços de recuperação deixam de existir (POST). */
    public function test_forgot_password_post_responde_404(): void
    {
        $this->post('/forgot-password', ['email' => 'alguem@example.com'])->assertNotFound();
    }

    /** @spec:AC-261 Os endereços de recuperação deixam de existir (reset GET). */
    public function test_reset_password_responde_404(): void
    {
        $this->get('/reset-password/qualquer-token')->assertNotFound();
    }

    /** @spec:AC-261 Os endereços de recuperação deixam de existir (reset POST). */
    public function test_reset_password_post_responde_404(): void
    {
        $this->post('/reset-password', [
            'token' => 'qualquer-token',
            'email' => 'alguem@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();
    }

    /** @spec:AC-262 O cadastro público não sobra nem como código (controller). */
    public function test_controller_de_cadastro_publico_nao_existe(): void
    {
        $this->assertFileDoesNotExist(
            app_path('Http/Controllers/Auth/RegisteredUserController.php'),
            'RegisteredUserController continua no repositório — é uma linha de rota de distância de virar cadastro público.'
        );
    }

    /** @spec:AC-262 O cadastro público não sobra nem como código (view). */
    public function test_view_de_cadastro_publico_nao_existe(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/auth/register.blade.php'));
    }

    /** @spec:AC-262 O cadastro público não sobra nem como código (rota). */
    public function test_rota_de_cadastro_nao_existe(): void
    {
        $this->assertFalse(Route::has('register'), 'A rota register não deveria existir.');
    }
}
