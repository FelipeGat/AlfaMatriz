<?php

namespace Tests\Feature\Redesign;

use Tests\TestCase;

/**
 * Os componentes compartilhados — e as duas armadilhas de tabela que eles
 * existem para tornar impossíveis.
 *
 * Uma tela sozinha acertando não prova nada: a coluna de ações some quando a
 * PRÓXIMA tela esquece o wrapper de rolagem. Por isso estes testes varrem
 * todas as telas de uma vez e cobram o uso do componente, não a repetição do
 * remendo.
 */
class ComponentesTest extends TestCase
{
    /**
     * Telas que fecham a tabela com uma linha de totais, conforme o desenho.
     * É onde a armadilha da altura dupla aparece.
     */
    private const TELAS_COM_TOTAL = [
        'revendas/index.blade.php',
        'clientes/index.blade.php',
        'produtos/index.blade.php',
        'faturamento/index.blade.php',
    ];

    /** @return list<string> caminhos relativos de todas as telas do painel */
    private function telas(): array
    {
        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('resources/views'))
        );

        $telas = [];
        foreach ($iterador as $arquivo) {
            if ($arquivo->isFile() && str_ends_with($arquivo->getFilename(), '.blade.php')) {
                $telas[] = str_replace(base_path('resources/views').'/', '', $arquivo->getPathname());
            }
        }

        sort($telas);

        return $telas;
    }

    private function conteudo(string $tela): string
    {
        return file_get_contents(base_path('resources/views/'.$tela));
    }

    /**
     * @spec:AC-046 Nenhuma tabela esconde a coluna de ações — a tabela larga
     * rola na horizontal dentro do próprio painel, em vez de ser cortada pelo
     * canto arredondado.
     */
    public function test_toda_tabela_rola_por_dentro_e_mantem_as_acoes_alcancaveis(): void
    {
        // O componente é quem garante a estrutura: raio na moldura, rolagem
        // num wrapper interno, largura mínima na tabela.
        $componente = $this->conteudo('components/tabela.blade.php');

        $this->assertMatchesRegularExpression(
            '/<div class="overflow-x-auto">\s*<table/s',
            $componente,
            'A rolagem precisa envolver a <table> — é ela que devolve a coluna de ações.'
        );
        $this->assertMatchesRegularExpression(
            '/<table[^>]*style="min-width:/',
            $componente,
            'Sem largura mínima a tabela se espreme em vez de rolar, e os números quebram no meio.'
        );
        $this->assertStringContainsString(
            'rounded-panel',
            $componente,
            'O raio fica na moldura externa, nunca no wrapper que rola.'
        );

        // E nenhuma tela pode abrir a própria <table> por fora do componente:
        // uma que escape já é a coluna de ações inalcançável de volta.
        $foraDoComponente = [];
        foreach ($this->telas() as $tela) {
            if ($tela === 'components/tabela.blade.php') {
                continue;
            }

            if (str_contains($this->conteudo($tela), '<table')) {
                $foraDoComponente[] = $tela;
            }
        }

        $this->assertSame(
            [],
            $foraDoComponente,
            "Estas telas montam a tabela na mão em vez de usar <x-tabela> — nelas a coluna de ações some "
            ."em janela estreita:\n".implode("\n", $foraDoComponente)
        );
    }

    /**
     * @spec:AC-047 As linhas de total não quebram em duas alturas — cada
     * célula de total fica em uma linha só.
     */
    public function test_a_linha_de_total_nunca_quebra_em_duas_alturas(): void
    {
        $componente = $this->conteudo('components/linha-total.blade.php');

        // O `nowrap` vale para TODAS as células por seletor: proteger só
        // algumas não resolve, porque basta uma quebrar para a faixa ganhar
        // duas alturas.
        $this->assertStringContainsString('[&>td]:whitespace-nowrap', $componente);
        $this->assertStringContainsString('[&>th]:whitespace-nowrap', $componente);

        $semComponente = [];
        foreach (self::TELAS_COM_TOTAL as $tela) {
            if (! str_contains($this->conteudo($tela), '<x-linha-total')) {
                $semComponente[] = $tela;
            }
        }

        $this->assertSame(
            [],
            $semComponente,
            "Estas telas têm linha de totais e não usam <x-linha-total> — os rótulos em caixa alta "
            ."vão quebrar e desalinhar a faixa:\n".implode("\n", $semComponente)
        );
    }
}
