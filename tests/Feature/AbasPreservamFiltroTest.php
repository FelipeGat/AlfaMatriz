<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trocar de aba leva o filtro junto (tarefa #207).
 *
 * O `<x-abas.item>` escapa o `href` ao imprimir. A tela que passava o link
 * como `href="{{ route(...) }}"` entregava o valor JÁ escapado, e o `&` entre
 * os parâmetros saía `&amp;amp;` no HTML — o navegador lia `&amp;` literal,
 * o parâmetro seguinte virava `amp;secao` e a aba não abria. Com um parâmetro
 * só não aparece; aparece assim que há um filtro no endereço.
 */
class AbasPreservamFiltroTest extends TestCase
{
    use RefreshDatabase;

    public function test_as_abas_dos_relatorios_levam_o_filtro_sem_escapar_duas_vezes(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->get(route('relatorios.index', ['secao' => 'comercial', 'competencia' => '2026-09']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringContainsString('secao=desenvolvimento&amp;competencia=2026-09', $html);
    }

    public function test_as_abas_de_produtos_e_revendas_levam_o_filtro_sem_escapar_duas_vezes(): void
    {
        $usuario = User::factory()->create();

        foreach (['produtos.index' => 'internos', 'revendas.index' => 'clientes'] as $rota => $aba) {
            $html = $this->actingAs($usuario)
                ->get(route($rota, ['busca' => 'x', 'aba' => 'z']))
                ->assertOk()
                ->getContent();

            $this->assertStringNotContainsString('&amp;amp;', $html, $rota);
            $this->assertStringContainsString('busca=x&amp;aba='.$aba, $html, $rota);
        }
    }
}
