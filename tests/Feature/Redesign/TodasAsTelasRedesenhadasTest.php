<?php

namespace Tests\Feature\Redesign;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Três telas — Despesas Fixas, Caixa e Cadastros — receberam só a troca
 * mecânica de nomes de cor e ficaram de fora do redesign: continuavam com o
 * cabeçalho antigo e sem os cards de resumo. Passaram despercebidas porque
 * respondiam 200 e não usavam classe morta.
 *
 * Este teste cobra os sinais do redesign em TODA tela de listagem.
 */
class TodasAsTelasRedesenhadasTest extends TestCase
{
    use RefreshDatabase;

    /** Toda tela de listagem do painel, com o grupo a que pertence no menu. */
    private const TELAS = [
        'dashboard' => 'Painéis',
        'comercial' => 'Painéis',
        'revendas.index' => 'Comercial',
        'clientes.index' => 'Comercial',
        'sistemas.index' => 'Comercial',
        'faturamento.index' => 'Comercial',
        'cobrancas.index' => 'Financeiro',
        'contas-pagar.index' => 'Financeiro',
        'contas-fixas-pagar.index' => 'Financeiro',
        'contas-financeiras.index' => 'Financeiro',
        'cadastros-auxiliares.index' => 'Sistema',
    ];

    /**
     * @spec:AC-042 Toda tela traz o breadcrumb do header novo, com o grupo
     * certo do menu — é o sinal mais visível de que a tela foi redesenhada, e
     * o que faltava nas três esquecidas.
     */
    public function test_toda_tela_tem_o_cabecalho_da_direcao_nova(): void
    {
        $usuario = User::factory()->create();
        $faltando = [];

        foreach (self::TELAS as $rota => $grupo) {
            $html = $this->actingAs($usuario)->get(route($rota))->getContent();

            if (! str_contains($html, '<span class="text-mute">'.$grupo.'</span>')) {
                $faltando[] = "{$rota} (esperado o grupo \"{$grupo}\")";
            }

            // O título antigo do Breeze não pode ter sobrado.
            if (str_contains($html, 'font-semibold text-xl text-ink leading-tight')) {
                $faltando[] = "{$rota} ainda usa o cabeçalho antigo";
            }
        }

        $this->assertSame([], $faltando, "Telas fora do padrão do redesign:\n".implode("\n", $faltando));
    }

    /**
     * @spec:AC-042 Toda tela de listagem abre com a faixa de cards de resumo:
     * é o que dá o número antes da lista, sem obrigar a somar com o olho.
     */
    public function test_toda_listagem_tem_cards_de_resumo(): void
    {
        $usuario = User::factory()->create();
        $semResumo = [];

        // Sistemas tem anatomia própria no handoff: catálogo à esquerda e
        // detalhe à direita, com as métricas dentro do card de detalhe. Não
        // leva faixa de resumo, e cobrar uma seria seguir o teste, não o
        // desenho.
        $comAnatomiaPropria = ['sistemas.index'];

        foreach (array_keys(self::TELAS) as $rota) {
            if (in_array($rota, $comAnatomiaPropria, true)) {
                continue;
            }

            $html = $this->actingAs($usuario)->get(route($rota))->getContent();

            // A faixa usa auto-fit/minmax — a mesma regra em todas.
            if (! str_contains($html, 'repeat(auto-fit, minmax(2')) {
                $semResumo[] = $rota;
            }
        }

        $this->assertSame([], $semResumo, "Telas sem faixa de resumo:\n".implode("\n", $semResumo));
    }

    /**
     * @spec:AC-045 Nenhuma tela de listagem sobrou com o padrão de layout
     * antigo (container centralizado com padding vertical grande), que
     * convivia mal com o header fino.
     */
    public function test_nenhuma_tela_usa_o_container_antigo(): void
    {
        $usuario = User::factory()->create();
        $antigas = [];

        foreach (array_keys(self::TELAS) as $rota) {
            $html = $this->actingAs($usuario)->get(route($rota))->getContent();

            if (str_contains($html, 'max-w-7xl mx-auto') || str_contains($html, '<div class="py-12">')) {
                $antigas[] = $rota;
            }
        }

        $this->assertSame([], $antigas, "Telas com o container antigo:\n".implode("\n", $antigas));
    }
}
