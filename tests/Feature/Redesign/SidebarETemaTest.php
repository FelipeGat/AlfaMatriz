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
     * @spec:AC-041 O menu recolhe para o trilho de ícones e o estado sobrevive
     * à navegação — sem isso ele voltaria ao tamanho original a cada página,
     * que é o que torna um menu colapsável inútil.
     */
    public function test_menu_recolhe_e_o_estado_e_lembrado(): void
    {
        // As duas larguras do handoff.
        $this->assertStringContainsString('w-[236px]', $this->html, 'Largura expandida fora do handoff.');
        $this->assertStringContainsString('lg:w-[68px]', $this->html, 'Largura recolhida fora do handoff.');

        // O botão que alterna e a função que persiste.
        $this->assertStringContainsString('alternarSidebar()', $this->html);
        $this->assertStringContainsString("localStorage.setItem('alfamatriz-sidebar'", $this->html);
        $this->assertStringContainsString("localStorage.getItem('alfamatriz-sidebar')", $this->html);

        // Recolhida, os rótulos somem por opacidade e largura (não por display,
        // que mataria a transição).
        $this->assertStringContainsString('lg:w-0 lg:opacity-0', $this->html);

        // E cada item mantém o rótulo em `title`, que vira tooltip no trilho.
        $this->assertStringContainsString('title="Faturamento"', $this->html);
    }

    /**
     * @spec:AC-041 O item da tela atual é destacado com o marcador da borda
     * esquerda, para a navegação continuar legível mesmo no trilho estreito.
     */
    public function test_item_ativo_recebe_marcador_e_cor_de_marca(): void
    {
        $this->assertStringContainsString('bg-brand-soft text-brand', $this->html, 'O item ativo precisa da cor de marca.');
        $this->assertStringContainsString('-left-3 h-[18px] w-[3px]', $this->html, 'Falta o marcador de 3×18px do item ativo.');
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

        // O botão de recolher não aparece abaixo de lg: ali a sidebar já é
        // sobreposta, e recolher não faria sentido.
        $this->assertMatchesRegularExpression(
            '/hidden[^"]*lg:flex/',
            $this->html,
            'O botão de recolher precisa ficar oculto abaixo de lg.'
        );
    }
}
