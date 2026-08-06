<?php

namespace Tests\Feature\Redesign;

use Tests\TestCase;

class MarcaMonocromaticaTest extends TestCase
{
    /**
     * Todo lugar onde o ícone da marca é desenhado. Uma lista explícita porque
     * o esquecimento aconteceu de verdade: a sidebar foi atualizada para a
     * direção monocromática e a tela de login ficou para trás, azul.
     */
    private const LAYOUTS = [
        'resources/views/layouts/navigation.blade.php',
        'resources/views/layouts/guest.blade.php',
    ];

    /**
     * @spec:AC-045 O ícone da marca é monocromático em toda parte: segue a cor
     * do texto, nunca a cor de marca. Cor viva ficou reservada para o que
     * significa algo.
     */
    public function test_icone_da_marca_e_monocromatico_em_todos_os_layouts(): void
    {
        foreach (self::LAYOUTS as $layout) {
            $conteudo = file_get_contents(base_path($layout));

            preg_match_all('/<svg[^>]*viewBox="2 1 44 45\.6"[^>]*>/', $conteudo, $svgs);

            $this->assertNotEmpty(
                $svgs[0],
                "{$layout} precisa desenhar o ícone da marca com o recorte do handoff."
            );

            foreach ($svgs[0] as $svg) {
                $this->assertStringContainsString(
                    'text-ink',
                    $svg,
                    "O ícone da marca em {$layout} precisa seguir a cor do texto."
                );
                $this->assertStringNotContainsString(
                    'text-brand',
                    $svg,
                    "O ícone da marca em {$layout} ainda usa a cor de marca."
                );
            }

            // Traço 6, a versão aprovada no handoff.
            $this->assertStringContainsString('stroke-width="6"', $conteudo, "Traço do ícone errado em {$layout}.");
            $this->assertStringNotContainsString('stroke-width="4.4"', $conteudo);
        }
    }

    /**
     * @spec:AC-045 O wordmark é PNG colorido: só o filtro por tema o mantém
     * monocromático. Sem ele, a marca volta a destoar.
     */
    public function test_wordmark_recebe_o_filtro_monocromatico(): void
    {
        foreach (self::LAYOUTS as $layout) {
            $conteudo = file_get_contents(base_path($layout));

            if (! str_contains($conteudo, 'alfamatriz-wordmark')) {
                continue;
            }

            $this->assertStringContainsString(
                'var(--logo-filter)',
                $conteudo,
                "O wordmark em {$layout} precisa do filtro que o torna monocromático."
            );
        }
    }
}
