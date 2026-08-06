<?php

namespace Tests\Feature\Redesign;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarETemaTest extends TestCase
{
    use RefreshDatabase;

    private string $html;

    protected function setUp(): void
    {
        parent::setUp();

        $usuario = User::factory()->create();
        $this->html = $this->actingAs($usuario)->get(route('dashboard'))->getContent();
    }

    /**
     * @spec:AC-041 O menu é fixo: largura única, sem controle de recolher. A
     * direção anterior tinha um menu colapsável, removido por decisão do
     * cliente — este teste impede que ele volte por descuido.
     */
    public function test_menu_e_fixo_sem_controle_de_recolher(): void
    {
        $this->assertStringContainsString('w-60', $this->html, 'O menu fixo tem 240px.');

        foreach (['alternarSidebar', 'alfamatriz-sidebar', 'lg:w-[68px]'] as $resto) {
            $this->assertStringNotContainsString(
                $resto,
                $this->html,
                "Sobrou \"{$resto}\" do menu colapsável, que foi removido da direção."
            );
        }
    }

    /**
     * @spec:AC-041 A busca vive no menu, logo abaixo da marca, com o atalho
     * de teclado indicado.
     */
    public function test_menu_traz_a_busca_com_atalho(): void
    {
        $this->assertStringContainsString('id="busca-menu"', $this->html);
        $this->assertStringContainsString('placeholder="Buscar"', $this->html);
        $this->assertStringContainsString('<kbd', $this->html, 'O atalho precisa estar indicado.');
        $this->assertStringContainsString("busca.focus()", $this->html, 'A tecla / precisa focar a busca.');
    }

    /**
     * @spec:AC-045 O item ativo do menu é neutro: superfície própria e texto
     * ink, sem cor de marca. Cor viva ficou reservada para o que significa
     * algo — gráfico, situação, indicador.
     */
    public function test_item_ativo_e_neutro(): void
    {
        $this->assertStringContainsString('bg-nav-active', $this->html, 'O item ativo usa superfície neutra.');
        $this->assertStringNotContainsString('bg-brand-soft text-brand', $this->html, 'O menu não pode usar cor de marca.');
        $this->assertStringNotContainsString('w-[3px] rounded-r bg-brand', $this->html, 'O marcador colorido saiu da direção.');
    }

    /**
     * @spec:AC-039 O botão de tema alterna claro/escuro e guarda a escolha —
     * é o par visível da leitura que já acontece antes da primeira pintura.
     */
    public function test_botao_de_tema_alterna_e_persiste(): void
    {
        $this->assertStringContainsString('alternarTema()', $this->html);
        $this->assertStringContainsString("localStorage.setItem('alfamatriz-tema'", $this->html);
        $this->assertStringContainsString("setAttribute('data-theme'", $this->html);

        // Rótulo acessível descrevendo para onde o clique leva.
        $this->assertStringContainsString('Mudar para tema claro', $this->html);
        $this->assertStringContainsString('Mudar para tema escuro', $this->html);
    }

    /**
     * @spec:AC-041 O comportamento de drawer sobreposto em telas estreitas,
     * que já existia, continua valendo — recolher é só a partir de `lg`.
     */
    public function test_drawer_de_tela_estreita_continua_existindo(): void
    {
        $this->assertStringContainsString('sidebarOpen', $this->html);
        $this->assertStringContainsString('-translate-x-full', $this->html);
        $this->assertStringContainsString('lg:translate-x-0', $this->html);

        // E o menu volta a ser estático a partir de lg.
        $this->assertStringContainsString('lg:static', $this->html);
    }
}
