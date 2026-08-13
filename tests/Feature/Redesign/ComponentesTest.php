<?php

namespace Tests\Feature\Redesign;

use Illuminate\Pagination\LengthAwarePaginator;
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
        'clientes/_tabela.blade.php',
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
            'Estas telas montam a tabela na mão em vez de usar <x-tabela> — nelas a coluna de ações some '
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
            'Estas telas têm linha de totais e não usam <x-linha-total> — os rótulos em caixa alta '
            ."vão quebrar e desalinhar a faixa:\n".implode("\n", $semComponente)
        );
    }

    /**
     * @spec:AC-221 O seletor de páginas obedece ao botão de tema — desenha com
     * os tokens do sistema, e não com as variantes `dark:` do Tailwind.
     *
     * A falha que este teste tranca é SILENCIOSA no HTML: a view de paginação
     * do framework produz marcação correta, só que pintada em `bg-white` e
     * `gray-300`, com o escuro resolvido por `dark:`. Como o
     * `tailwind.config.js` não declara `darkMode`, `dark:` cai no padrão
     * `media` e obedece ao sistema operacional — não à classe `.theme-light`
     * do <html>, que é quem manda no tema aqui. O seletor ficava branco no
     * tema escuro, e ninguém repara olhando só o Blade.
     */
    public function test_o_seletor_de_paginas_desenha_com_os_tokens_do_sistema(): void
    {
        $seletor = (new LengthAwarePaginator(range(1, 15), 87, 15, 3, ['path' => '/x']))
            ->links()->toHtml();

        foreach (['dark:', 'bg-white', 'bg-gray-', 'text-gray-', 'border-gray-'] as $cru) {
            $this->assertStringNotContainsString(
                $cru,
                $seletor,
                "O seletor de páginas voltou a usar `{$cru}`, que não acompanha o botão de tema."
            );
        }

        // E o que ele usa no lugar: a receita do handoff em tokens do sistema.
        foreach (['rounded-tile', 'border-btn-line', 'text-ink-mute', 'bg-chip'] as $token) {
            $this->assertStringContainsString($token, $seletor, "Faltou o token `{$token}` no seletor de páginas.");
        }

        // Nenhuma tela pode escapar apontando outra view no próprio `links()`:
        // seria a view do framework de volta, numa listagem só.
        $comViewPropria = [];
        foreach ($this->telas() as $tela) {
            if (preg_match('/->links\(\s*[\'"]/', $this->conteudo($tela))) {
                $comViewPropria[] = $tela;
            }
        }

        $this->assertSame(
            [],
            $comViewPropria,
            "Estas telas passam uma view ao `links()` e furam a paginação do painel:\n"
            .implode("\n", $comViewPropria)
        );
    }
}
