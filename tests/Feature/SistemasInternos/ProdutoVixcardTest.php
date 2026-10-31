<?php

namespace Tests\Feature\SistemasInternos;

use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Produto da VixCard (Felipe, 17/10/2026): no catálogo e nas tarefas, fora do dinheiro da Alfa. */
class ProdutoVixcardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markTestSkipped('Desligado em 31/10/2026: o Matriz ficou só com a Alfa e o Grupo Soluções, e a aba Produtos VixCard saiu (o código fica comentado).');
    }

    public function test_cadastra_sem_bloco_comercial_e_volta_para_a_aba_dele(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('sistemas.store'), ['nome' => 'Vix-ID', 'natureza' => 'vixcard', 'categoria' => 'saas', 'unidade_cobranca' => 'leitor', 'ativo' => '1'])
            ->assertRedirect(route('produtos.index', ['aba' => 'vixcard']));

        $s = Sistema::sole();
        $this->assertSame('vixcard', $s->natureza);
        $this->assertNull($s->categoria, 'A Alfa não cobra produto da VixCard');
        $this->assertNull($s->unidade_cobranca);
    }

    public function test_aparece_na_aba_propria_e_fora_dos_produtos_da_alfa(): void
    {
        Sistema::factory()->create(['nome' => 'AlfaGym', 'natureza' => 'produto']);
        Sistema::factory()->create(['nome' => 'Vix-Beer', 'slug' => 'vix-beer', 'natureza' => 'vixcard', 'categoria' => null, 'unidade_cobranca' => null]);

        $this->assertSame(['AlfaGym'], Sistema::produtos()->pluck('nome')->all());
        $u = User::factory()->create();
        $this->actingAs($u)->get(route('produtos.index'))->assertOk()->assertSee('Produtos VixCard · 1')->assertDontSee('vix-beer');
        $this->get(route('produtos.index', ['aba' => 'vixcard']))->assertOk()->assertSee('Vix-Beer')->assertSee('+ Novo produto VixCard')->assertDontSee('AlfaGym');
        $this->get(route('sistemas.create', ['natureza' => 'vixcard']))->assertOk()->assertSee('Produto VixCard');
        $this->get(route('sistemas.edit', Sistema::where('slug', 'vix-beer')->sole()))->assertOk()->assertDontSee('Tiers de atacado');
    }
}
