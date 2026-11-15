<?php

namespace Tests\Feature\Conversas;

use App\Models\Perfil;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * O protótipo da tela Conversas (T-602) só existe com a chave ligada.
 *
 * Ele foi pedido para o staging, e a produção não pode vê-lo: protótipo que
 * aparece para o cliente vira promessa. Por isso o teste fixa os dois lados —
 * desligado é 404 e menu sem o item; ligado abre e mostra a conversa real
 * copiada do quadro — e que não há porta de escrita.
 */
class PrototipoConversasTest extends TestCase
{
    use RefreshDatabase;

    public function test_desligado_a_tela_da_404_e_o_menu_nao_mostra_o_item(): void
    {
        config(['alfa.conversas_prototipo' => false]);
        $usuario = User::factory()->membro()->create();

        $this->actingAs($usuario)->get('/conversas')->assertNotFound();

        $this->actingAs($usuario)->get(route('agenda.index'))
            ->assertOk()
            ->assertDontSee('Conversas (protótipo)')
            ->assertDontSee(route('conversas.prototipo'), false);
    }

    public function test_ligado_a_tela_abre_com_as_conversas_de_exemplo_e_o_menu_mostra_o_item(): void
    {
        config(['alfa.conversas_prototipo' => true]);
        $usuario = User::factory()->membro()->create();

        $this->actingAs($usuario)->get(route('conversas.prototipo'))
            ->assertOk()
            ->assertSee('nada é gravado')
            ->assertSee('Instabilidade no Alfasync apoena')
            ->assertSee('T-556');

        $this->actingAs($usuario)->get(route('agenda.index'))
            ->assertSee('Conversas (protótipo)');
    }

    /** A régua é a da Agenda: conversa do time não é para o monitor da parede. */
    public function test_perfil_de_exibicao_nao_alcanca_o_prototipo(): void
    {
        config(['alfa.conversas_prototipo' => true]);
        (new PerfilPermissaoSeeder)->run();
        $usuario = User::factory()->semPerfil()->create();
        $usuario->perfis()->attach(Perfil::where('slug', 'exibicao')->value('id'));

        $this->actingAs($usuario)->get(route('conversas.prototipo'))->assertForbidden();
    }

    /** Nada grava: a única rota do protótipo é de leitura. */
    public function test_o_prototipo_nao_tem_rota_de_escrita(): void
    {
        $rotas = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => $r->uri() === 'conversas' || str_starts_with($r->uri(), 'conversas/'));

        $this->assertCount(1, $rotas);
        $this->assertSame(['GET', 'HEAD'], $rotas->first()->methods());
    }
}
