<?php

namespace Tests\Feature\Seguranca;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfirmarSenhaTest extends TestCase
{
    use RefreshDatabase;

    /** @spec:AC-263 A sétima tentativa errada de confirmar a senha é recusada. */
    public function test_setima_tentativa_errada_de_confirmar_a_senha_e_recusada(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->post('/confirm-password', [
                'password' => 'senha-errada',
            ])->assertSessionHasErrors();
        }

        $this->actingAs($user)->post('/confirm-password', [
            'password' => 'senha-errada',
        ])->assertStatus(429);
    }
}
