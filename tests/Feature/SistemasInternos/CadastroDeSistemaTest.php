<?php

namespace Tests\Feature\SistemasInternos;

use App\Models\Cliente;
use App\Models\Perfil;
use App\Models\PrecoAtacado;
use App\Models\Sistema;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Até aqui o catálogo só se editava: sistema entrava no banco por seeder ou por
 * migração, e quem precisava de um novo abria o MySQL. O cadastro fecha esse
 * buraco — e é onde a distinção entre produto e interno é DECLARADA, então é
 * aqui que ela pode ser declarada errado.
 */
class CadastroDeSistemaTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $extra */
    private function campos(array $extra = []): array
    {
        return array_merge([
            'nome' => 'AlfaHome',
            'natureza' => 'produto',
            'categoria' => 'saas',
            'unidade_cobranca' => 'família ativa',
            'ativo' => '1',
        ], $extra);
    }

    public function test_cadastra_um_produto(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('sistemas.store'), $this->campos())
            ->assertRedirect(route('produtos.index'));

        $sistema = Sistema::sole();

        $this->assertSame('AlfaHome', $sistema->nome);
        $this->assertSame('produto', $sistema->natureza);
        $this->assertSame('família ativa', $sistema->unidade_cobranca);
        $this->assertTrue($sistema->ativo);
    }

    /** O cadastro volta para a aba de onde saiu — senão o interno recém-criado some de vista. */
    public function test_cadastra_um_interno_e_volta_para_a_aba_dele(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('sistemas.store'), $this->campos([
                'nome' => 'Infra e Deploy',
                'natureza' => 'interno',
                'categoria' => null,
                'unidade_cobranca' => null,
            ]))
            ->assertRedirect(route('produtos.index', ['aba' => 'internos']));

        $sistema = Sistema::sole();

        $this->assertSame('interno', $sistema->natureza);
        $this->assertNull($sistema->unidade_cobranca);
        $this->assertNull($sistema->categoria);
    }

    /**
     * Produto sem unidade de cobrança entraria no fechamento sem ter o que
     * contar. O `nullable` do banco existe para o interno, e não é permissão
     * para cadastrar produto pela metade.
     */
    public function test_produto_exige_categoria_e_unidade_de_cobranca(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('sistemas.store'), $this->campos([
                'categoria' => null,
                'unidade_cobranca' => null,
            ]))
            ->assertSessionHasErrors(['categoria', 'unidade_cobranca']);

        $this->assertSame(0, Sistema::count());
    }

    /**
     * Os campos comerciais viajam no formulário mesmo escondidos pelo `x-show`.
     * Sem a limpeza no servidor, quem preenche a unidade e só então troca a
     * natureza grava um interno carregando a unidade pela qual seria cobrado.
     */
    public function test_o_interno_nao_guarda_o_que_foi_digitado_no_bloco_comercial(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('sistemas.store'), $this->campos([
                'nome' => 'Site institucional',
                'natureza' => 'interno',
                'categoria' => 'crm',
                'unidade_cobranca' => 'visita',
            ]));

        $sistema = Sistema::sole();

        $this->assertNull($sistema->unidade_cobranca);
        $this->assertNull($sistema->categoria);
    }

    public function test_o_slug_sai_do_nome_quando_nao_e_informado(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('sistemas.store'), $this->campos(['nome' => 'Alfa Home Condomínios']));

        $this->assertSame('alfa-home-condominios', Sistema::sole()->slug);
    }

    /** Nome repetido não pode estourar unicidade num campo que a pessoa nem viu. */
    public function test_o_slug_repetido_ganha_sufixo(): void
    {
        Sistema::factory()->create(['nome' => 'AlfaHome', 'slug' => 'alfahome']);

        $this->actingAs(User::factory()->create())
            ->post(route('sistemas.store'), $this->campos(['nome' => 'AlfaHome']));

        $this->assertSame('alfahome-2', Sistema::where('nome', 'AlfaHome')->latest('id')->first()->slug);
    }

    /** Sistema novo não ganha poder por descuido: capacidade se declara depois, uma a uma. */
    public function test_o_sistema_nasce_sem_capacidade_nenhuma(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('sistemas.store'), $this->campos());

        $this->assertSame([], Sistema::sole()->capacidades);
    }

    /**
     * Cadastrar é `incluir` no recurso `sistemas` — a mesma porta de preço e
     * tier. O perfil Financeiro lê a tela e não cadastra; sem o gate, ele
     * criaria produto no catálogo pela URL.
     */
    public function test_quem_so_le_o_catalogo_nao_cadastra(): void
    {
        (new PerfilPermissaoSeeder)->run();

        $financeiro = User::factory()->semPerfil()->create();
        $financeiro->perfis()->attach(Perfil::where('slug', 'financeiro')->value('id'));

        $this->actingAs($financeiro)->get(route('sistemas.create'))->assertOk();
        $this->actingAs($financeiro)->post(route('sistemas.store'), $this->campos())->assertForbidden();

        $this->assertSame(0, Sistema::count());
    }

    public function test_a_natureza_muda_enquanto_ninguem_depende_dela(): void
    {
        $sistema = Sistema::factory()->create(['nome' => 'Painel de suporte']);

        $this->actingAs(User::factory()->create())
            ->put(route('sistemas.update', $sistema), $this->campos([
                'nome' => 'Painel de suporte',
                'natureza' => 'interno',
            ]))
            ->assertRedirect(route('produtos.index', ['aba' => 'internos']));

        $this->assertSame('interno', $sistema->refresh()->natureza);
    }

    /**
     * A recusa é validação, e não um descarte silencioso: quem tentou precisa
     * saber que não mudou. Salvo em silêncio, veria o campo voltar ao que era e
     * concluiria que a tela perdeu o que ele digitou.
     */
    public function test_produto_com_cliente_nao_vira_interno(): void
    {
        $sistema = Sistema::factory()->create(['nome' => 'AlfaGym']);
        Cliente::create(['nome' => 'Academia Central', 'ativo' => true])
            ->sistemas()->attach($sistema->id, ['ativo' => true]);

        $this->actingAs(User::factory()->create())
            ->put(route('sistemas.update', $sistema), $this->campos([
                'nome' => 'AlfaGym',
                'natureza' => 'interno',
            ]))
            ->assertSessionHasErrors('natureza');

        $this->assertSame('produto', $sistema->refresh()->natureza);
    }

    public function test_produto_com_tier_nao_vira_interno(): void
    {
        $sistema = Sistema::factory()->create(['nome' => 'AlfaGym']);
        PrecoAtacado::create([
            'sistema_id' => $sistema->id, 'revenda_id' => null, 'nome' => 'Único',
            'preco_base' => 500, 'unidades_inclusas' => 100, 'ordem' => 1,
            'vigencia_inicio' => now()->subYear()->toDateString(),
        ]);

        $this->actingAs(User::factory()->create())
            ->put(route('sistemas.update', $sistema), $this->campos([
                'nome' => 'AlfaGym',
                'natureza' => 'interno',
            ]))
            ->assertSessionHasErrors('natureza');

        $this->assertSame('produto', $sistema->refresh()->natureza);
    }

    /**
     * O slug amarra a marca na tela e o `sincronizar:sistemas` na linha certa.
     * A edição não o oferece; mandá-lo pela URL também não pode mudá-lo, senão
     * as duas amarras se desfazem sem nada na tela avisar.
     */
    public function test_a_edicao_nao_troca_o_slug(): void
    {
        $sistema = Sistema::factory()->create(['nome' => 'AlfaGym', 'slug' => 'alfagym']);

        $this->actingAs(User::factory()->create())
            ->put(route('sistemas.update', $sistema), $this->campos([
                'nome' => 'AlfaGym',
                'slug' => 'outro-slug',
            ]));

        $this->assertSame('alfagym', $sistema->refresh()->slug);
    }
}
