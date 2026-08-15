<?php

namespace Tests\Feature\Redesign;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A aba de Manutenção e atualizações ainda é promessa: nenhum dado ligado.
 * O que se prova é o contrato do placeholder — a porta existe no menu da
 * matriz (para revenda o grupo continua invisível: AC-094, provado no
 * MenuDesenvolvimentoTest), a tela abre e diz "Em breve" nomeando o que vem
 * (agenda por sistema e histórico com changelog), em vez de fingir conteúdo.
 */
class ManutencaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tela_reservada_abre_e_nomeia_o_que_vai_morar_nela(): void
    {
        $this->actingAs(User::factory()->create());

        $resposta = $this->get(route('manutencao.index'));

        $resposta->assertOk();
        $resposta->assertSee('Em breve');
        $resposta->assertSee('Atualizações programadas');
        $resposta->assertSee('Histórico de atualizações');
        $resposta->assertSee('changelog');
    }

    public function test_o_menu_oferece_a_porta_nas_outras_telas(): void
    {
        $this->actingAs(User::factory()->create());

        $resposta = $this->get(route('centro-controle'));

        $resposta->assertOk();
        $resposta->assertSee('Manutenção e atualizações');
    }
}
