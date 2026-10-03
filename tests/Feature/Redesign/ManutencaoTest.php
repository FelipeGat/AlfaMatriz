<?php

namespace Tests\Feature\Redesign;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A tela de Manutenção e atualizações: as abas Erros (#224), Atualizações e
 * Programadas (#225). Vazias, elas dizem de onde o conteúdo vem em vez de
 * fingi-lo. A porta continua no menu da matriz (para revenda o grupo segue
 * invisível: AC-094, provado no MenuDesenvolvimentoTest).
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

    public function test_as_outras_abas_abrem_vazias_dizendo_de_onde_o_conteudo_vem(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('manutencao.index', ['aba' => 'atualizacoes']))
            ->assertOk()->assertSee('Nenhuma atualização registrada ainda.')->assertSee('publicar-changelog.sh');

        $this->get(route('manutencao.index', ['aba' => 'programadas']))
            ->assertOk()->assertSee('Nenhuma janela de manutenção marcada.')->assertSee('Deploy / manutenção');
    }

    public function test_o_menu_oferece_a_porta_nas_outras_telas(): void
    {
        $this->actingAs(User::factory()->create());

        $resposta = $this->get(route('centro-controle'));

        $resposta->assertOk();
        $resposta->assertSee('Manutenção e atualizações');
    }
}
