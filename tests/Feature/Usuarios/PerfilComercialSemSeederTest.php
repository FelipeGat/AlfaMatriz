<?php

namespace Tests\Feature\Usuarios;

use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O perfil comercial sozinho, exatamente como produção o recebe: só migração,
 * sem seeder nenhum.
 *
 * Existe separado do `PerfilComercialTest` porque aquele semeia no `setUp` — e
 * semear mascarava o defeito. A migração LIA a tabela `permissoes` em vez de
 * garanti-la, e ela é povoada pelo seeder, que não roda em produção: o perfil
 * chegava lá existindo e sem permissão nenhuma, uma conta que entra no painel e
 * não abre tela alguma.
 *
 * Nenhum teste deste arquivo pode chamar o `PerfilPermissaoSeeder`.
 */
class PerfilComercialSemSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_migracao_sozinha_entrega_o_perfil_utilizavel(): void
    {
        $comercial = Perfil::where('slug', 'comercial')->first();

        $this->assertNotNull($comercial, 'A migração é quem leva o perfil a produção.');
        $this->assertSame('Comercial', $comercial->nome);

        // Quatro: funil, clientes, revendas e o painel próprio — este último
        // por `2026_08_15_130000_permissao_dashboard_comercial.php`, porque a
        // rota `/comercial` deixou de aceitar `dashboard`.
        $this->assertSame(4, $comercial->permissoes()->count());
    }

    public function test_e_quem_o_recebe_alcanca_as_quatro_telas(): void
    {
        $this->withoutVite();

        $vendedor = User::factory()->semPerfil()->create();
        $vendedor->perfis()->attach(Perfil::where('slug', 'comercial')->value('id'));

        foreach (['comercial', 'leads.index', 'clientes.index', 'revendas.index'] as $rota) {
            $this->actingAs($vendedor)->get(route($rota))->assertOk();
        }

        $this->actingAs($vendedor)->get(route('centro-controle'))->assertForbidden();
    }
}
