<?php

namespace Tests\Feature\Permissoes;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A recusa de permissão ganha uma tela do sistema.
 *
 * Antes ela caía na página padrão do framework — em inglês, sem a moldura do
 * painel e sem caminho de volta. A tela nova mantém a moldura de quem está
 * logado, repete o motivo que o abort() já dizia e oferece uma saída que a
 * própria conta garante enxergar (`telaInicial()`).
 */
class TelaDeAcessoNegadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_sem_permissao_a_tela_diz_o_motivo_e_oferece_saida(): void
    {
        $usuario = User::factory()->semPerfil()->create();

        $this->actingAs($usuario)
            ->get(route('clientes.index'))
            ->assertForbidden()
            ->assertSee('Sua conta não tem este acesso')
            // O motivo é o do abort() — a tela não pode trocá-lo pelo genérico,
            // senão "só os leads da sua revenda" viraria "sem permissão".
            ->assertSee('Você não tem permissão para executar esta ação.')
            ->assertSee('Ir para minha tela inicial');
    }
}
