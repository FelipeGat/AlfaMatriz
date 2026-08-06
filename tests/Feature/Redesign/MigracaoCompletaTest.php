<?php

namespace Tests\Feature\Redesign;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MigracaoCompletaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Nomes de cor das direções anteriores. Enquanto a migração acontecia,
     * eles viviam como apelidos no Tailwind para as telas não ficarem sem
     * estilo. Os apelidos foram removidos: quem usar estes nomes agora não
     * ganha estilo nenhum, e o defeito é invisível em teste de renderização —
     * a tela responde 200 e aparece quebrada.
     */
    private const CLASSES_MORTAS = [
        'bg-canvas', 'text-ink-dim', 'text-ink-mute', 'bg-panel-raised',
        'text-brand-dim', 'text-brand-bright', 'bg-brand-mute',
        'text-status-good', 'text-status-warning', 'text-status-critical',
        'bg-amber-signal', 'shadow-panel',
    ];

    /**
     * @spec:AC-045 Nenhuma tela usa nome de cor de direção anterior. Sem os
     * apelidos, essas classes não existem mais no CSS gerado.
     */
    public function test_nenhuma_tela_usa_classe_de_direcao_antiga(): void
    {
        $encontrados = [];

        foreach (File::allFiles(resource_path('views')) as $arquivo) {
            if (! str_ends_with($arquivo->getFilename(), '.blade.php')) {
                continue;
            }

            // A welcome.blade.php é a página padrão do Laravel, com CSS
            // embutido e sem rota — não faz parte do painel.
            if ($arquivo->getFilename() === 'welcome.blade.php') {
                continue;
            }

            $conteudo = $arquivo->getContents();

            foreach (self::CLASSES_MORTAS as $classe) {
                if (str_contains($conteudo, $classe)) {
                    $encontrados[] = $arquivo->getRelativePathname().' → '.$classe;
                }
            }
        }

        $this->assertSame([], $encontrados, "Classes de direção anterior ainda em uso:\n".implode("\n", $encontrados));
    }

    /**
     * @spec:AC-045 Os apelidos de compatibilidade saíram da configuração: se
     * voltarem, o defeito acima deixa de ser detectável.
     */
    public function test_configuracao_nao_tem_mais_apelidos_de_compatibilidade(): void
    {
        $config = file_get_contents(base_path('tailwind.config.js'));

        foreach (['canvas:', 'panel-raised', 'ink-dim', 'ink-mute', 'Compatibilidade'] as $residuo) {
            $this->assertStringNotContainsString(
                $residuo,
                $config,
                "O apelido \"{$residuo}\" voltou à configuração e esconderia telas quebradas."
            );
        }
    }

    /**
     * @spec:AC-042 A confirmação de ação aparece como aviso flutuante, lendo a
     * mesma `session('status')` que os controllers já gravavam — nenhuma
     * alteração de controller foi necessária.
     */
    public function test_acao_concluida_mostra_aviso_flutuante(): void
    {
        $usuario = User::factory()->create();

        $html = $this->actingAs($usuario)
            ->withSession(['status' => 'Revenda removida.'])
            ->get(route('dashboard'))
            ->getContent();

        $this->assertStringContainsString('Revenda removida.', $html);
        $this->assertStringContainsString('anim-toast', $html);
        $this->assertStringContainsString('role="status"', $html, 'Leitor de tela precisa anunciar sem roubar o foco.');
        $this->assertStringContainsString('2600', $html, 'O aviso some sozinho.');

        // Sem status na sessão, nada é desenhado. O flush é necessário porque
        // `withSession` grava de forma persistente, não como mensagem única.
        $this->flushSession();
        $limpo = $this->actingAs($usuario)->get(route('dashboard'))->getContent();
        $this->assertStringNotContainsString('anim-toast', $limpo);
    }

    /**
     * @spec:AC-045 O botão primário é monocromático invertido em todo o
     * sistema, inclusive nos formulários herdados do Breeze.
     */
    public function test_botao_primario_e_monocromatico(): void
    {
        $componente = file_get_contents(resource_path('views/components/primary-button.blade.php'));

        $this->assertStringContainsString('bg-ink', $componente);
        $this->assertStringContainsString('text-bg', $componente);
        $this->assertStringNotContainsString('bg-brand', $componente, 'O primário não usa cor de marca.');

        // Desabilitado tem aparência própria, não só opacidade.
        $this->assertStringContainsString('disabled:bg-raised', $componente);
    }
}
