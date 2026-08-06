<?php

namespace Tests\Feature\Redesign;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemasETipografiaTest extends TestCase
{
    use RefreshDatabase;

    private string $css;

    private string $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->css = file_get_contents(base_path('resources/css/app.css'));
        $this->config = file_get_contents(base_path('tailwind.config.js'));
    }

    /**
     * @spec:AC-039 Os dois temas existem, cada um com a paleta completa do
     * handoff, e as cores do Tailwind apontam para as variáveis — é isso que
     * faz a troca valer para a interface inteira sem duplicar classe.
     */
    public function test_os_dois_temas_tem_a_paleta_completa(): void
    {
        $this->assertStringContainsString("data-theme='dark'", $this->css);
        $this->assertStringContainsString("data-theme='light'", $this->css);

        $tokens = [
            '--bg', '--sidebar', '--panel', '--raised', '--border',
            '--ink', '--dim', '--mute',
            '--brand', '--chart', '--good', '--warn', '--bad',
            '--track', '--track2', '--nav-active', '--nav-hover', '--logo-filter',
        ];

        // Cada token precisa existir nos DOIS blocos de tema.
        [$dark, $light] = $this->blocosDeTema();

        foreach ($tokens as $token) {
            $this->assertStringContainsString($token.':', $dark, "O tema escuro não define {$token}.");
            $this->assertStringContainsString($token.':', $light, "O tema claro não define {$token}.");
        }

        // Valores exatos por token — asserção por presença deixava passar um
        // hex que migrou de um token para outro.
        $this->assertStringContainsString('--bg: #000000', $dark, 'O fundo escuro é preto puro, como a referência.');
        $this->assertStringContainsString('--panel: #0a0a0a', $dark, 'O card precisa se destacar do fundo preto.');
        $this->assertStringContainsString('--ink: #ededed', $dark);

        $this->assertStringContainsString('--bg: #fafafa', $light);
        $this->assertStringContainsString('--ink: #171717', $light);

        // A borda clara precisa ser lida contra o fundo: com #ebebeb ela
        // praticamente sumia no #fafafa.
        preg_match('/--border:\s*#([0-9a-f]{6})/i', $light, $m);
        $this->assertNotEmpty($m, 'O tema claro precisa definir a borda em hex.');
        $this->assertLessThan(
            0xe0e0e0,
            hexdec($m[1]),
            'A borda do tema claro está clara demais para separar card de fundo.'
        );

        // E o Tailwind precisa consumir as variáveis, não os hex.
        foreach (['bg', 'panel', 'raised', 'ink', 'dim', 'mute'] as $cor) {
            $this->assertMatchesRegularExpression(
                '/'.$cor.":\s*'var\(--/",
                $this->config,
                "A cor \"{$cor}\" precisa apontar para a custom property, senão o tema não troca."
            );
        }
    }

    /**
     * @spec:AC-040 A tipografia é a do handoff e a antiga saiu: três famílias,
     * com mono para número, e nenhuma referência ao Inter.
     */
    public function test_tipografia_nova_entra_e_a_antiga_sai(): void
    {
        // Os DOIS layouts: o de convidado ficou para trás numa troca de
        // direção e a tela de login passou a usar outra fonte que o painel.
        $layouts = [
            'resources/views/layouts/app.blade.php',
            'resources/views/layouts/guest.blade.php',
        ];

        foreach ($layouts as $caminho) {
            $layout = file_get_contents(base_path($caminho));

            foreach (['geist:', 'geist-mono'] as $familia) {
                $this->assertStringContainsString($familia, $layout, "A fonte {$familia} não é carregada em {$caminho}.");
            }

            foreach (['inter:', 'space-grotesk', 'ibm-plex'] as $antiga) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $antiga,
                    $layout,
                    "A fonte {$antiga} é de uma direção anterior e continua em {$caminho}."
                );
            }

            // E o tema salvo vale nos dois, aplicado antes da primeira pintura.
            $this->assertStringContainsString('alfamatriz-tema', $layout, "{$caminho} ignora o tema salvo.");
            $this->assertStringContainsString('data-theme=', $layout);
        }

        foreach (['inter:', 'space-grotesk', 'ibm-plex'] as $antiga) {
            $this->assertStringNotContainsStringIgnoringCase($antiga, $this->config);
        }

        $this->assertStringContainsString('Geist Mono', $this->config, 'Número precisa de família mono própria.');
    }

    /**
     * @spec:AC-040 O ícone da marca é servido como favicon e é o do handoff —
     * duas setas convergindo para um núcleo, na cor da marca.
     */
    public function test_favicon_da_marca_esta_publicado_e_referenciado(): void
    {
        $caminho = public_path('favicon.svg');
        $this->assertFileExists($caminho, 'O favicon do pacote precisa estar em public/.');

        $svg = file_get_contents($caminho);
        $this->assertStringContainsString('circle', $svg, 'O núcleo do ícone é um círculo.');
        $this->assertSame(2, substr_count($svg, '<path'), 'O ícone tem duas setas convergindo.');

        // Monocromático e adaptável: a aba do navegador não é superfície nossa,
        // e cor fixa sumiria num dos dois temas.
        $this->assertStringNotContainsString('#029caf', $svg, 'O favicon não é mais teal.');
        $this->assertStringContainsString('prefers-color-scheme: dark', $svg, 'O favicon precisa acompanhar o tema da aba.');
        // Conta só nos atributos de desenho: o comentário do arquivo também
        // menciona `currentColor` ao explicar a decisão.
        $this->assertSame(
            3,
            preg_match_all('/(?:stroke|fill)="currentColor"/', $svg),
            'As três formas seguem a cor do tema.'
        );

        // O que o Firefox exige, e sem o que ele simplesmente não desenha:
        // dimensão explícita e uma cor padrão no elemento raiz — ele renderiza
        // favicon SVG em modo restrito e pode ignorar o <style>.
        $this->assertMatchesRegularExpression('/<svg[^>]*\bwidth="\d+"/', $svg, 'O favicon precisa de largura explícita.');
        $this->assertMatchesRegularExpression('/<svg[^>]*\bheight="\d+"/', $svg, 'O favicon precisa de altura explícita.');
        $this->assertMatchesRegularExpression(
            '/<svg[^>]*\bcolor="#[0-9a-f]{6}"/i',
            $svg,
            'Sem cor padrão no elemento raiz, `currentColor` não resolve onde o <style> é ignorado.'
        );

        // Um favicon.ico vazio faz o navegador preferir o arquivo em branco ao
        // ícone declarado — foi o que deixou o Firefox sem ícone nenhum.
        $ico = public_path('favicon.ico');
        $this->assertTrue(
            ! file_exists($ico) || filesize($ico) > 0,
            'Existe um favicon.ico vazio: o navegador o usa e não mostra ícone.'
        );

        $layout = file_get_contents(base_path('resources/views/layouts/app.blade.php'));
        $this->assertStringContainsString('favicon.svg', $layout, 'O layout precisa referenciar o favicon.');
    }

    /**
     * @spec:AC-039 A preferência de tema é aplicada antes da primeira pintura.
     * Se ficasse depois, quem usa o tema escuro veria um flash branco a cada
     * navegação.
     */
    public function test_tema_e_aplicado_antes_da_primeira_pintura(): void
    {
        $usuario = User::factory()->create();
        $html = $this->actingAs($usuario)->get(route('dashboard'))->getContent();

        $posScript = strpos($html, 'alfamatriz-tema');
        $posBody = strpos($html, '<body');

        $this->assertNotFalse($posScript, 'A leitura da preferência de tema não está na página.');
        $this->assertLessThan(
            $posBody,
            $posScript,
            'A preferência precisa ser lida no <head>, antes do <body>, para não piscar.'
        );

        $this->assertStringContainsString('data-theme=', $html);
    }

    /** @return array{0: string, 1: string} */
    private function blocosDeTema(): array
    {
        $inicioLight = strpos($this->css, "[data-theme='light']");
        $this->assertNotFalse($inicioLight);

        $fimLight = strpos($this->css, '}', $inicioLight);

        return [
            substr($this->css, 0, $inicioLight),
            substr($this->css, $inicioLight, $fimLight - $inicioLight),
        ];
    }
}
