<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('centro-controle', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    /**
     * Tela de login aberta por horas: o token do formulário vence junto com a
     * sessão. Em vez da página "419", de onde só se sai fechando a aba, o
     * envio precisa voltar ao login com aviso e com o e-mail preservado.
     */
    public function test_token_vencido_volta_para_o_login_com_aviso(): void
    {
        // O middleware de CSRF se desliga sozinho quando roda em teste; sem
        // desfazer isso, o cenário que quebrou em produção não acontece aqui.
        $this->app->instance(ValidateCsrfToken::class, new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        $response = $this->post('/login', [
            'email' => 'alguem@alfatecnologia.com.br',
            'password' => 'password',
            '_token' => 'token-de-uma-sessao-que-ja-morreu',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('sessao');
        $this->assertSame('alguem@alfatecnologia.com.br', session('_old_input.email'));
        $this->assertArrayNotHasKey('password', session('_old_input', []));
        $this->assertGuest();
    }

    public function test_tela_de_login_renova_o_token_da_sessao(): void
    {
        $response = $this->get('/csrf-token');

        $response->assertOk();
        $response->assertJson(['token' => csrf_token()]);
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
