<?php

namespace Tests\Feature\Redesign;

use Tests\TestCase;

/**
 * O contrato do build do front: o mesmo commit produz o mesmo CSS.
 *
 * Lê o arquivo-fonte em vez de compilar, como o `TokensTest`: o que se prova
 * aqui é a REGRA de varredura, e compilar duas vezes num teste custaria alguns
 * segundos para chegar à mesma conclusão que uma linha de configuração dá.
 */
class BuildDoCssTest extends TestCase
{
    /**
     * @spec:AC-217 A varredura do Tailwind não pode depender do cache de views.
     *
     * `storage/framework/views` é estado da máquina, não código: com ele na
     * lista, o CSS mudava de tamanho conforme as telas que alguém tivesse
     * aberto antes de compilar — 74kB com o cache quente, 66kB com ele frio, do
     * mesmo commit. O que ele acrescentava era a página de erro do modo debug
     * do Laravel, que injeta o próprio CSS e nunca usou este bundle.
     */
    public function test_a_varredura_do_tailwind_nao_depende_do_cache_de_views(): void
    {
        $config = file_get_contents(base_path('tailwind.config.js'));

        // A busca é pela ENTRADA da lista, entre aspas, e não pelo caminho
        // solto: ele aparece de novo logo acima, no comentário que explica por
        // que saiu — e um teste que reprova por causa da própria explicação
        // ensina a apagar a explicação.
        $this->assertStringNotContainsString("'./storage/framework/views", $config,
            'O cache de views compiladas não pode entrar na varredura: ele torna o build não determinístico.');

        // As fontes que valem são código versionado — e a view de vendor que
        // precisa deste CSS entra explícita, como a paginação.
        $this->assertStringContainsString("'./resources/views/**/*.blade.php'", $config);
        $this->assertStringContainsString('Pagination/resources/views', $config);
    }
}
