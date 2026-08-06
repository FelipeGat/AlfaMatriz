<?php

namespace Tests\Feature\Redesign;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A moldura do painel: sidebar que recolhe, topbar, alternador de tema.
 *
 * O que dá para provar aqui é o que chega no navegador. Recolher o menu e
 * trocar o tema acontecem no cliente, então o que se prova é o CONTRATO que
 * torna esses comportamentos possíveis: o estado existe, é persistido, e o
 * tema é decidido antes da primeira pintura (senão ele pisca a cada página).
 */
class ShellTest extends TestCase
{
    use RefreshDatabase;

    /** As quatro divisões do menu, na ordem do desenho. */
    private const GRUPOS = ['Painéis', 'Comercial', 'Financeiro', 'Sistema'];

    private function navegacao(): string
    {
        return file_get_contents(base_path('resources/views/layouts/navigation.blade.php'));
    }

    private function moldura(): string
    {
        return file_get_contents(base_path('resources/views/layouts/app.blade.php'));
    }

    private function alpine(): string
    {
        return file_get_contents(base_path('resources/js/app.js'));
    }

    private function operador(): User
    {
        return User::factory()->create();
    }

    /**
     * @spec:AC-035 Toda tela autenticada usa o mesmo shell — sidebar com os
     * quatro grupos, topbar com título e busca, e rodapé com avatar,
     * notificações e tema.
     */
    public function test_toda_tela_do_painel_abre_dentro_da_mesma_moldura(): void
    {
        $operador = $this->operador();
        $this->actingAs($operador);

        foreach (['centro-controle', 'dashboard', 'comercial'] as $rota) {
            $resposta = $this->get(route($rota));

            $resposta->assertOk();

            foreach (self::GRUPOS as $grupo) {
                $resposta->assertSee($grupo, escape: false);
            }

            // Topbar: busca ancorada e o botão que recolhe o menu.
            $resposta->assertSee('Buscar cliente, revenda, cobrança…', escape: false);
            $resposta->assertSee('Recolher ou expandir o menu', escape: false);

            // Rodapé da sidebar.
            $resposta->assertSee('Notificações', escape: false);
            $resposta->assertSee('menu-principal', escape: false);

            // O menu da conta, aberto pelo avatar. Ele guarda o que não
            // merece lugar fixo no rodapé — e o SAIR, que já se perdeu uma vez
            // num redesenho: num painel financeiro interno, deixar a sessão
            // aberta porque não se acha o botão é problema de segurança.
            $resposta->assertSee('aria-haspopup="menu"', escape: false);
            $resposta->assertSee('Configurações', escape: false);
            $resposta->assertSee(route('profile.edit'), escape: false);
            $resposta->assertSee('Sair da conta', escape: false);
            $resposta->assertSee(route('logout'), escape: false);

            // A identidade de quem está logado: no rail recolhido o nome some
            // do rodapé, e o menu passa a ser a única resposta na tela.
            $resposta->assertSee($operador->email, escape: false);
        }

        // A marca do handoff substituiu a anterior em toda tela do painel.
        $resposta = $this->get(route('centro-controle'));
        $resposta->assertSee('/icon-matriz.svg', escape: false);
        $resposta->assertSee('/alfamatriz.png', escape: false);
        $resposta->assertDontSee('logo-tile.svg', escape: false);

        // E o botão de sair encerra a sessão de verdade.
        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    /**
     * @spec:AC-036 Recolher o menu vira um rail de ícones — com divisória
     * entre os grupos no lugar dos rótulos — e a escolha sobrevive à
     * navegação.
     */
    public function test_o_menu_recolhe_para_o_rail_e_a_escolha_fica_guardada(): void
    {
        $navegacao = $this->navegacao();
        $alpine = $this->alpine();

        // Duas larguras, uma transição: 228px expandido, 60px recolhido.
        $this->assertStringContainsString('w-sidebar', $navegacao);
        $this->assertStringContainsString('lg:w-rail', $navegacao);
        $this->assertStringContainsString('duration-rail', $navegacao);

        // Recolhido, o rótulo do grupo dá lugar a uma régua — e a régua é um
        // elemento com altura e fundo, porque `border-top` em elemento de
        // altura zero não pinta.
        $this->assertMatchesRegularExpression(
            '/h-px[^"]*bg-rule-strong/',
            $navegacao,
            'Falta a divisória de 1px que separa os grupos quando o menu está recolhido.'
        );
        $this->assertStringContainsString(
            '$loop->first',
            $navegacao,
            'A divisória não pode aparecer antes do primeiro grupo — ali ela não separa nada.'
        );

        // O menu recolhido não reserva largura de barra de rolagem: se
        // reservar, os ícones do rail saem do centro.
        $this->assertStringContainsString(
            '.rail-fechado nav#menu-principal',
            file_get_contents(base_path('resources/css/app.css'))
        );

        // A escolha sobrevive à navegação porque mora no navegador.
        $this->assertStringContainsString('alfamatriz:rail', $alpine);
        $this->assertStringContainsString('alfamatriz:rail', $this->moldura());
        $this->assertMatchesRegularExpression(
            '/alternarRail\(\)\s*\{.*localStorage|lembrar\(\s*\'alfamatriz:rail\'/s',
            $alpine,
            'Recolher o menu precisa gravar a escolha, senão ela morre na próxima tela.'
        );

        // ── E o menu não pode NASCER expandido para só então encolher.
        //
        // Foi o defeito relatado: a posição da marca vinha de um `:class` do
        // Alpine, aplicado depois da primeira pintura — a página desenhava o
        // menu aberto e, quando o Alpine acordava, a marca deslizava para o
        // lugar certo. A decisão agora é uma classe no <html>, escrita antes
        // de qualquer pintura, e a aparência sai do CSS.
        $moldura = $this->moldura();
        $posicaoRail = strpos($moldura, "localStorage.getItem('alfamatriz:rail')");
        $this->assertNotFalse($posicaoRail, 'O <head> precisa decidir o estado do menu.');
        $this->assertLessThan(
            strpos($moldura, '@vite'),
            $posicaoRail,
            'A decisão do menu precisa vir antes dos assets — depois dela já houve pintura, e a marca salta.'
        );
        $this->assertStringContainsString('rail-fechado', $moldura);

        // Nenhuma peça da moldura pode depender do Alpine para se posicionar.
        foreach (['w-sidebar', 'px-4', 'rail:lg:hidden'] as $classeEstatica) {
            $this->assertStringContainsString($classeEstatica, $navegacao);
        }
        $this->assertDoesNotMatchRegularExpression(
            '/:class="\(?railAberto/',
            $navegacao,
            'Layout do menu preso a `:class` volta a fazer a marca saltar na carga da página.'
        );
    }

    /**
     * @spec:AC-037 O tema alterna pelo rodapé da sidebar, troca a interface
     * inteira de uma vez e sobrevive à navegação — sem piscar o tema errado ao
     * carregar a página.
     */
    public function test_o_tema_alterna_persiste_e_nao_pisca(): void
    {
        $moldura = $this->moldura();
        $alpine = $this->alpine();

        // Uma classe no <html> troca o conjunto inteiro de tokens: é isso que
        // impede a tela mestiça (metade clara, metade escura).
        $this->assertStringContainsString('theme-light', $moldura);
        $this->assertStringContainsString('theme-light', $alpine);
        $this->assertStringContainsString('alfamatriz:tema', $alpine);

        // A decisão precisa acontecer ANTES da primeira pintura. Se ela
        // esperasse o Alpine, quem usa o tema claro veria o escuro piscar a
        // cada navegação — por isso o script vem antes do @vite.
        $posicaoScript = strpos($moldura, "localStorage.getItem('alfamatriz:tema')");
        $posicaoVite = strpos($moldura, '@vite');

        $this->assertNotFalse($posicaoScript, 'O <head> precisa decidir o tema a partir do que ficou guardado.');
        $this->assertNotFalse($posicaoVite);
        $this->assertLessThan(
            $posicaoVite,
            $posicaoScript,
            'A decisão de tema precisa vir antes dos assets — depois dela já houve pintura, e o tema pisca.'
        );

        // O botão vive no rodapé da sidebar e troca de ícone conforme o tema.
        $navegacao = $this->navegacao();
        $this->assertStringContainsString('alternarTema()', $navegacao);
        $this->assertStringContainsString('name="sun"', $navegacao);
        $this->assertStringContainsString('name="moon"', $navegacao);

        // Quem entra pela primeira vez vê o escuro (decisão registrada em
        // Q-008): sem preferência guardada, nada de `theme-light`.
        $this->actingAs($this->operador());
        $resposta = $this->get(route('centro-controle'));
        $resposta->assertOk();
        $this->assertDoesNotMatchRegularExpression(
            '/<html[^>]*class="[^"]*theme-light/',
            $resposta->getContent(),
            'O servidor não pode entregar o tema claro por padrão — o padrão é o escuro.'
        );
    }

    /**
     * @spec:AC-038 O menu marca o item certo, inclusive nas telas filhas — o
     * formulário de cliente acende Clientes, a tela de conta acende Caixa.
     */
    public function test_a_tela_filha_mantem_o_item_do_pai_aceso(): void
    {
        $this->actingAs($this->operador());

        $filhas = [
            route('clientes.create') => 'Clientes',
            route('contas-financeiras.create') => 'Caixa',
            route('revendas.create') => 'Revendas',
        ];

        foreach ($filhas as $url => $itemEsperado) {
            $resposta = $this->get($url);
            $resposta->assertOk();

            $html = $resposta->getContent();

            // O item ativo é o único com aria-current — e é ele que carrega a
            // barra de marca de 3px à esquerda.
            preg_match_all('/<a\b[^>]*aria-current="page"[^>]*>(.*?)<\/a>/s', $html, $ativos);

            $this->assertCount(
                1,
                $ativos[0],
                "Em {$url} deveria haver exatamente um item de menu aceso, e há ".count($ativos[0]).'.'
            );
            $this->assertStringContainsString(
                $itemEsperado,
                $ativos[1][0],
                "Em {$url} o item aceso deveria ser {$itemEsperado}."
            );
            $this->assertStringContainsString(
                'border-brand',
                $ativos[0][0],
                'O item aceso precisa da barra de marca à esquerda.'
            );
        }
    }

    /**
     * @spec:AC-061 Em tela estreita o menu vira gaveta sobreposta e o conteúdo
     * não estoura a largura da página.
     */
    public function test_em_tela_estreita_o_menu_vira_gaveta(): void
    {
        $navegacao = $this->navegacao();
        $moldura = $this->moldura();

        // Abaixo de 1024px a sidebar sai do fluxo e desliza por cima.
        $this->assertStringContainsString('-translate-x-full', $navegacao);
        $this->assertStringContainsString('lg:translate-x-0', $navegacao);
        $this->assertStringContainsString('lg:sticky', $navegacao);
        $this->assertStringContainsString('gavetaAberta', $navegacao);

        // Véu por baixo da gaveta, só no estreito, e fechável pelo Esc.
        $this->assertMatchesRegularExpression(
            '/x-show="gavetaAberta"[^>]*class="[^"]*lg:hidden/s',
            $moldura,
            'A gaveta precisa de um véu que só existe abaixo de 1024px.'
        );
        $this->assertStringContainsString('keydown.escape.window', $navegacao);

        // O mesmo botão do topbar abre a gaveta no estreito e recolhe o rail
        // no largo — ele não muda de lugar entre os dois mundos.
        $this->assertMatchesRegularExpression(
            '/innerWidth\s*<\s*1024\s*\?\s*gavetaAberta\s*=\s*true\s*:\s*alternarRail\(\)/',
            $moldura
        );

        // Nada de rolagem horizontal na página inteira.
        $this->assertStringContainsString(
            'overflow-x: hidden',
            file_get_contents(base_path('resources/css/app.css'))
        );
        $this->assertStringContainsString('min-w-0', $moldura);
    }
}
