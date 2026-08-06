<?php

namespace Tests\Feature\Redesign;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ComponentesBaseTest extends TestCase
{
    /**
     * @spec:AC-043 Valor em dinheiro não ganha permissão de quebrar linha —
     * e a regra mora no componente, não espalhada por tela. Um valor longo
     * num card estreito precisa encolher, nunca virar duas linhas.
     */
    public function test_valor_monetario_nunca_quebra_em_duas_linhas(): void
    {
        $longo = 'R$ 1.234.567.890,12';

        foreach (['kpi-card', 'summary-card'] as $componente) {
            $html = Blade::render(
                '<x-'.$componente.' label="Total" :value="$v" />',
                ['v' => $longo]
            );

            $this->assertStringContainsString($longo, $html);

            // A classe `valor` carrega mono + whitespace-nowrap.
            $this->assertMatchesRegularExpression(
                '/class="[^"]*\bvalor\b[^"]*"/',
                $html,
                "O componente {$componente} precisa marcar o valor como monetário."
            );

            // E o tamanho é fluido: é o que faz encolher em vez de quebrar.
            $this->assertStringContainsString('clamp(', $html, "O valor em {$componente} precisa de tamanho fluido.");
        }

        // A classe `valor` existe de fato no CSS, com as duas propriedades.
        $css = file_get_contents(base_path('resources/css/app.css'));
        $this->assertMatchesRegularExpression('/\.valor\s*\{[^}]*whitespace-nowrap/s', $css);
    }

    /**
     * @spec:AC-043 O card de KPI aceita as duas formas de apoio do handoff:
     * variação percentual colorida, ou barra de proporção.
     */
    public function test_kpi_mostra_variacao_colorida_e_barra_de_proporcao(): void
    {
        $alta = Blade::render('<x-kpi-card label="MRR" value="R$ 10" :variacao="12.5" apoio="vs. mês anterior" />');
        $this->assertStringContainsString('+12,5%', $alta);
        $this->assertStringContainsString('text-good', $alta, 'Variação positiva é verde.');

        $baixa = Blade::render('<x-kpi-card label="MRR" value="R$ 10" :variacao="-4.2" />');
        $this->assertStringContainsString('-4,2%', $baixa);
        $this->assertStringContainsString('text-bad', $baixa, 'Variação negativa é vermelha.');

        $barra = Blade::render('<x-kpi-card label="Uso" value="70" :proporcao="70" />');
        $this->assertStringContainsString('width: 70%', $barra);

        // Proporção fora da faixa não pode vazar do trilho.
        $estouro = Blade::render('<x-kpi-card label="Uso" value="999" :proporcao="180" />');
        $this->assertStringContainsString('width: 100%', $estouro);
    }

    /**
     * @spec:AC-043 As pílulas de status seguem o significado do handoff, e o
     * texto delas também não quebra linha.
     */
    public function test_pilulas_de_status_seguem_o_significado_do_handoff(): void
    {
        // "brand" aqui é o estado de destaque (ex.: Gerada). Na direção nova
        // ele é NEUTRO: superfície própria e texto ink, sem cor de marca.
        $casos = [
            'good' => 'text-good',
            'bad' => 'text-bad',
            'brand' => 'bg-raised text-ink',
            'neutro' => 'text-dim',
        ];

        foreach ($casos as $tom => $classe) {
            $html = Blade::render('<x-status-pill tom="'.$tom.'">Situação</x-status-pill>');

            $this->assertStringContainsString($classe, $html, "A pílula \"{$tom}\" está com a cor errada.");
            $this->assertStringContainsString('whitespace-nowrap', $html);
        }

        // Tom desconhecido cai no neutro em vez de sair sem estilo.
        $this->assertStringContainsString('text-dim', Blade::render('<x-status-pill tom="inexistente">X</x-status-pill>'));
    }
}
