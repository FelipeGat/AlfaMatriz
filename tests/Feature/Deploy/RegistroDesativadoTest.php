<?php

namespace Tests\Feature\Deploy;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RegistroDesativadoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-001 Um visitante que abre a tela de cadastro, ou tenta enviar
     * um cadastro novo, recebe "página não encontrada" e nenhuma conta é
     * criada — o painel fica numa URL pública e ninguém cria a própria conta.
     */
    public function test_visitante_nao_consegue_abrir_nem_enviar_o_cadastro(): void
    {
        $this->get('/register')->assertNotFound();

        $this->post('/register', [
            'name' => 'Invasor Qualquer',
            'email' => 'invasor@exemplo.com',
            'password' => 'SenhaForte@2026',
            'password_confirmation' => 'SenhaForte@2026',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'invasor@exemplo.com']);
        $this->assertSame(0, User::count());

        $this->assertFalse(
            Route::has('register'),
            'A rota nomeada "register" não pode existir: as telas de convidado a usam para mostrar o link de cadastro.'
        );
    }
}
