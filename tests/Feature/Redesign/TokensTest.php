<?php

namespace Tests\Feature\Redesign;

use Tests\TestCase;

/**
 * A fundação do sistema visual: os tokens dos dois temas e as três famílias
 * tipográficas.
 *
 * Estes testes leem os arquivos-fonte em vez de renderizar tela, porque o que
 * está sendo provado é o CONTRATO — se o token existe, se os dois temas o
 * declaram e se a cor resultante é legível. Contraste é conta, não opinião:
 * a razão é recalculada aqui a cada execução, então clarear um cinza "só um
 * pouquinho" reprova na hora.
 */
class TokensTest extends TestCase
{
    /** Tokens que toda tela pode usar — os dois temas precisam declarar todos. */
    private const TOKENS_OBRIGATORIOS = [
        // estrutura
        'canvas', 'panel', 'panel-raised', 'head', 'surface', 'subtle',
        'board', 'input', 'chip', 'card-grad',
        // linhas
        'line', 'rule', 'rule-strong', 'btn-line', 'bar-track',
        // tinta
        'ink', 'ink-dim', 'ink-mute', 'ink-faint',
        // marca
        'brand', 'brand-text', 'brand-bright', 'brand-mute', 'on-brand',
        'accent', 'glow', 'nav-active',
        // sinal
        'good', 'warn', 'crit', 'amber', 'chart-out',
        'good-tint', 'good-line', 'warn-tint', 'warn-line', 'crit-tint',
        'tint-alpha', 'grid-line',
    ];

    /** Tintas de texto e o mínimo que cada uma precisa alcançar. */
    private const TINTAS = ['ink', 'ink-dim', 'ink-mute', 'ink-faint', 'brand-text'];

    private const CONTRASTE_MINIMO = 4.5;

    private function css(): string
    {
        return file_get_contents(base_path('resources/css/app.css'));
    }

    private function tailwind(): string
    {
        return file_get_contents(base_path('tailwind.config.js'));
    }

    /**
     * Recorta o corpo de um seletor do CSS (`:root { … }`) e devolve os pares
     * `--token => valor`.
     *
     * @return array<string, string>
     */
    private function tokensDe(string $seletor): array
    {
        $css = $this->css();
        $inicio = strpos($css, $seletor.' {');
        $this->assertNotFalse($inicio, "O bloco `{$seletor}` não existe em resources/css/app.css.");

        $fim = strpos($css, "\n}", $inicio);
        $corpo = substr($css, $inicio, $fim - $inicio);

        preg_match_all('/--([a-z0-9-]+)\s*:\s*([^;]+);/i', $corpo, $achados, PREG_SET_ORDER);

        $tokens = [];
        foreach ($achados as $achado) {
            $tokens[$achado[1]] = trim($achado[2]);
        }

        return $tokens;
    }

    /** Luminância relativa (WCAG) de uma cor em canais `R G B`. */
    private function luminancia(string $canais): float
    {
        $partes = preg_split('/\s+/', trim($canais));

        $linear = array_map(function (string $canal): float {
            $c = ((float) $canal) / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $partes);

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    /** Razão de contraste (WCAG) entre duas cores em canais `R G B`. */
    private function contraste(string $frente, string $fundo): float
    {
        $a = $this->luminancia($frente);
        $b = $this->luminancia($fundo);

        [$claro, $escuro] = $a > $b ? [$a, $b] : [$b, $a];

        return ($claro + 0.05) / ($escuro + 0.05);
    }

    /**
     * @spec:AC-032 Os tokens do design existem e alimentam os dois temas — cada
     * cor da interface é declarada uma vez só, com a versão escura e a clara
     * lado a lado, e as telas consomem o token em vez do hexadecimal cru.
     */
    public function test_os_dois_temas_declaram_o_mesmo_conjunto_de_tokens(): void
    {
        $escuro = $this->tokensDe(':root');
        $claro = $this->tokensDe('.theme-light');

        foreach (self::TOKENS_OBRIGATORIOS as $token) {
            $this->assertArrayHasKey(
                $token,
                $escuro,
                "O tema escuro (`:root`) não declara `--{$token}`."
            );
            $this->assertArrayHasKey(
                $token,
                $claro,
                "O tema claro (`.theme-light`) não declara `--{$token}` — trocar de tema deixaria essa cor presa no escuro."
            );
        }

        // A marca é a mesma nos dois temas; o que muda é a marca em texto.
        $this->assertSame(
            $escuro['brand'],
            $claro['brand'],
            'A cor de marca (#029caf) precisa ser idêntica nos dois temas.'
        );
        $this->assertNotSame(
            $escuro['brand-text'],
            $claro['brand-text'],
            'A marca em TEXTO precisa mudar por tema — #5be3ef não contrasta sobre fundo branco.'
        );

        // O Tailwind só dá nome ao token: quem guarda valor é o CSS. As duas
        // funções auxiliares do config (`cheia` para cor com opacidade,
        // `veu` para superfície que já traz alpha) são o único caminho.
        $tailwind = $this->tailwind();
        $this->assertStringContainsString(
            'var(--${nome})',
            $tailwind,
            'As cores do Tailwind precisam ser montadas a partir da custom property.'
        );

        foreach (['canvas', 'panel', 'ink', 'ink-faint', 'brand', 'rule', 'chip', 'good', 'warn', 'crit'] as $token) {
            $this->assertMatchesRegularExpression(
                '/(cheia|veu)\(\''.preg_quote($token, '/').'\'\)/',
                $tailwind,
                "O tailwind.config.js não aponta `{$token}` para a custom property — se ele guardar o valor, o tema não troca."
            );
        }
        $this->assertStringNotContainsString(
            '#029caf',
            $tailwind,
            'A marca não pode estar hard-coded no tailwind.config.js: o valor mora no CSS.'
        );

        // As telas consomem token, não hexadecimal. (welcome.blade.php é a
        // página padrão do Laravel, fora do painel e fora do redesign; o
        // documento dos Relatórios — prévia/PDF/impressão — é papel, que
        // circula impresso fora do tema: lá o hexadecimal é o meio, não fuga
        // do token.)
        $cruas = [];
        foreach ($this->viewsDoPainel() as $view) {
            if (preg_match_all('/#[0-9a-fA-F]{6}\b/', file_get_contents($view), $achados)) {
                $cruas[] = str_replace(base_path().'/', '', $view).': '.implode(', ', array_unique($achados[0]));
            }
        }
        $this->assertSame(
            [],
            $cruas,
            "Estas telas escrevem cor em hexadecimal em vez de usar o token — elas não vão trocar de tema:\n".implode("\n", $cruas)
        );
    }

    /**
     * @spec:AC-033 O texto informativo é legível nos dois temas — toda tinta de
     * texto alcança pelo menos 4,5:1 contra o fundo da aplicação e contra a
     * superfície de painel do próprio tema.
     */
    public function test_toda_tinta_de_texto_alcanca_45_de_contraste_nos_dois_temas(): void
    {
        $temas = [
            'escuro' => $this->tokensDe(':root'),
            'claro' => $this->tokensDe('.theme-light'),
        ];

        foreach ($temas as $nome => $tokens) {
            foreach (['canvas' => 'o fundo da aplicação', 'panel' => 'a superfície de painel'] as $fundo => $descricao) {
                foreach (self::TINTAS as $tinta) {
                    $razao = $this->contraste($tokens[$tinta], $tokens[$fundo]);

                    $this->assertGreaterThanOrEqual(
                        self::CONTRASTE_MINIMO,
                        $razao,
                        sprintf(
                            'No tema %s, `%s` sobre %s dá %.2f:1 — abaixo do mínimo de %.1f:1. '
                            .'Escureça (tema claro) ou clareie (tema escuro) a tinta; não afrouxe o mínimo.',
                            $nome,
                            $tinta,
                            $descricao,
                            $razao,
                            self::CONTRASTE_MINIMO
                        )
                    );
                }
            }
        }

        // O cinza reprovado na revisão do handoff não pode voltar por descuido.
        $this->assertStringNotContainsString(
            '149 163 168',
            $this->css(),
            'O cinza #95a3a8 mede 2,6:1 no tema claro e já foi reprovado uma vez.'
        );
    }

    /**
     * @spec:AC-034 Cada família tipográfica tem um papel e está disponível —
     * Space Grotesk nos títulos e números de destaque, Geist no corpo, Geist
     * Mono nos rótulos em caixa alta, números de tabela e datas.
     */
    public function test_as_tres_familias_carregam_e_tem_papel_declarado(): void
    {
        $css = $this->css();

        foreach (['space-grotesk', 'Geist', 'Geist+Mono'] as $familia) {
            $this->assertStringContainsString(
                $familia,
                $css,
                "A família {$familia} não é carregada em resources/css/app.css."
            );
        }

        $tailwind = $this->tailwind();

        // O papel de cada família é o que amarra o desenho: `font-sans` é
        // corpo, `font-display` é destaque, `font-mono` é dado tabular.
        $papeis = [
            'sans' => 'Geist',
            'display' => 'Space Grotesk',
            'mono' => 'Geist Mono',
        ];

        foreach ($papeis as $papel => $familia) {
            $this->assertMatchesRegularExpression(
                '/'.$papel.':\s*\[[^\]]*'.preg_quote($familia, '/').'/s',
                $tailwind,
                "A família `{$papel}` do Tailwind precisa começar por {$familia}."
            );
        }

        // A Inter era a fonte de corpo antes do redesign; deixá-la no topo da
        // pilha faria o painel inteiro continuar com a tipografia antiga.
        $this->assertDoesNotMatchRegularExpression(
            "/sans:\s*\['Inter'/",
            $tailwind,
            'A Inter não é mais a fonte de corpo — o corpo é Geist.'
        );
    }

    /**
     * As telas do painel — tudo em resources/views, menos a página inicial
     * padrão do Laravel, que não faz parte do produto.
     *
     * @return list<string>
     */
    private function viewsDoPainel(): array
    {
        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('resources/views'))
        );

        $views = [];
        foreach ($iterador as $arquivo) {
            if ($arquivo->isFile()
                && str_ends_with($arquivo->getFilename(), '.blade.php')
                && $arquivo->getFilename() !== 'welcome.blade.php'
                && ! str_ends_with($arquivo->getPathname(), 'relatorios/documento.blade.php')) {
                $views[] = $arquivo->getPathname();
            }
        }

        sort($views);

        return $views;
    }
}
