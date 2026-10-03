<?php

namespace Tests\Feature\Redesign;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A tela de Manutenção e atualizações: a aba Erros (#224) tem dado; as abas
 * Atualizações e Programadas ainda dizem "Em breve" nomeando o que vem, em
 * vez de fingir conteúdo. A porta continua no menu da matriz (para revenda o
 * grupo segue invisível: AC-094, provado no MenuDesenvolvimentoTest).
 */
class ManutencaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tela_abre_na_aba_erros(): void
    {
        $this->actingAs(User::factory()->create());

        $resposta = $this->get(route('manutencao.index'));

        $resposta->assertOk();
        $resposta->assertSee('Erros');
        $resposta->assertSee('Erros vigiados');
        $resposta->assertSee('O que o vigia ignora');
    }

    public function test_as_abas_ainda_reservadas_nomeiam_o_que_vai_morar_nelas(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('manutencao.index', ['aba' => 'atualizacoes']))
            ->assertOk()->assertSee('Em breve')->assertSee('Histórico de atualizações')->assertSee('changelog');

        $this->get(route('manutencao.index', ['aba' => 'programadas']))
            ->assertOk()->assertSee('Em breve')->assertSee('Atualizações programadas');
    }

    public function test_o_menu_oferece_a_porta_nas_outras_telas(): void
    {
        $this->actingAs(User::factory()->create());

        $resposta = $this->get(route('centro-controle'));

        $resposta->assertOk();
        $resposta->assertSee('Manutenção e atualizações');
    }
}
