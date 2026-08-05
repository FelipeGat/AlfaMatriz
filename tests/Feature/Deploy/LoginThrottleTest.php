<?php

namespace Tests\Feature\Deploy;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('');
    }

    /**
     * @spec:AC-003 Quem erra a senha cinco vezes seguidas e tenta a sexta é
     * recusado com aviso de espera, em vez de continuar tendo tentativas —
     * a URL é pública, então adivinhação de senha precisa esbarrar num muro.
     */
    public function test_sexta_tentativa_seguida_e_recusada_com_aviso_de_espera(): void
    {
        $usuario = User::factory()->create();

        for ($tentativa = 1; $tentativa <= 5; $tentativa++) {
            $resposta = $this->post('/login', [
                'email' => $usuario->email,
                'password' => 'senha-errada',
            ]);

            $resposta->assertSessionHasErrors('email');
            $this->assertGuest();

            $erro = session('errors')->first('email');
            $this->assertStringNotContainsStringIgnoringCase(
                'segundos',
                $erro,
                "A tentativa {$tentativa} não deveria estar bloqueada ainda."
            );

            $this->flushSession();
        }

        $this->post('/login', [
            'email' => $usuario->email,
            'password' => 'senha-errada',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsStringIgnoringCase(
            'segundos',
            session('errors')->first('email'),
            'A sexta tentativa precisa avisar que é necessário esperar.'
        );

        // E o bloqueio vale mesmo com a senha certa: não adianta acertar depois.
        $this->flushSession();
        $this->post('/login', [
            'email' => $usuario->email,
            'password' => 'password',
        ]);
        $this->assertGuest();
    }
}
