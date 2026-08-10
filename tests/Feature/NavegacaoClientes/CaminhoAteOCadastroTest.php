<?php

namespace Tests\Feature\NavegacaoClientes;

use App\Models\Cliente;
use App\Models\Perfil;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * O CAMINHO até o cliente, não o endpoint.
 *
 * As features anteriores provaram que `GET /clientes/create` respondia 200 e
 * mesmo assim ninguém conseguia cadastrar cliente pela interface: a tela não
 * era referenciada por view nenhuma. Endpoint que responde e tela que alguém
 * alcança são coisas diferentes, e é a segunda que importa para quem usa.
 *
 * Nota para quem for mexer nestas asserções: `route('clientes.store')` e
 * `route('clientes.index')` são a MESMA URL (`/clientes`). Assertar só a URL
 * passa por acaso — o link da logo já a contém para usuário de revenda. Por
 * isso as provas de "existe formulário" usam marcador do próprio formulário.
 */
class CaminhoAteOCadastroTest extends TestCase
{
    use RefreshDatabase;

    /** Marcador do formulário de cadastro: só existe se o modal foi renderizado. */
    private const CAMPO_DO_FORMULARIO = 'name="tipo_pessoa"';

    private const BOTAO_NOVO_CLIENTE = '+ Novo cliente';

    private Revenda $minhaRevenda;

    private Revenda $outraRevenda;

    protected function setUp(): void
    {
        parent::setUp();

        (new PerfilPermissaoSeeder)->run();

        Sistema::factory()->alfagym()->create(['slug' => 'alfagym', 'nome' => 'AlfaGym', 'ativo' => true]);

        $this->minhaRevenda = Revenda::create(['nome' => 'Invest Soluções', 'ativo' => true]);
        $this->outraRevenda = Revenda::create(['nome' => 'Concorrente Ltda', 'ativo' => true]);

        Cliente::create(['nome' => 'Academia Corpo em Movimento', 'revenda_id' => $this->minhaRevenda->id, 'ativo' => true]);
        Cliente::create(['nome' => 'Academia da Concorrente', 'revenda_id' => $this->outraRevenda->id, 'ativo' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create(); // sem revenda_id: usuário da matriz
    }

    private function usuarioDaRevenda(): User
    {
        $usuario = User::factory()->semPerfil()->create(['revenda_id' => $this->minhaRevenda->id]);
        $usuario->perfis()->attach(Perfil::where('slug', 'revenda')->value('id'));

        return $usuario;
    }

    /** Os endereços que o menu lateral oferece a quem está logado. */
    private function linksDoMenu(string $html): array
    {
        // Só o menu: a página inteira tem dezenas de links (ações de tabela,
        // paginação, logo). O caminho que interessa é o que o menu oferece.
        preg_match('/<nav id="menu-principal".*?<\/nav>/s', $html, $nav);

        $this->assertNotEmpty($nav, 'Não encontrei o menu principal na página.');

        preg_match_all('/href="([^"#]+)"/', $nav[0], $hrefs);

        return array_values(array_unique($hrefs[1]));
    }

    /** @spec:AC-115 A aba de clientes traz o cadastro junto com a lista. */
    public function test_aba_de_clientes_traz_lista_botao_e_formulario(): void
    {
        $resposta = $this->actingAs($this->admin())
            ->get(route('revendas.index', ['aba' => 'clientes']))
            ->assertOk();

        // A lista...
        $resposta->assertSee('Academia Corpo em Movimento');
        // ...o gatilho...
        $resposta->assertSee(self::BOTAO_NOVO_CLIENTE);
        $resposta->assertSee("open-modal', 'novo-cliente'", escape: false);
        // ...e o formulário. Os três juntos: botão sem formulário não cadastra,
        // formulário sem botão ninguém abre.
        $resposta->assertSee(self::CAMPO_DO_FORMULARIO, escape: false);
        $resposta->assertSee('name="revenda_id"', escape: false);
    }

    /** @spec:AC-116 A aba de revendas não oferece cadastro de cliente. */
    public function test_aba_de_revendas_nao_traz_o_cadastro_de_cliente(): void
    {
        $resposta = $this->actingAs($this->admin())
            ->get(route('revendas.index'))
            ->assertOk();

        $resposta->assertSee('+ Nova revenda');
        $resposta->assertDontSee(self::BOTAO_NOVO_CLIENTE);
        // Formulário sem gatilho é exatamente a órfã que esta feature removeu.
        $resposta->assertDontSee("open-modal', 'novo-cliente'", escape: false);
    }

    /** @spec:AC-117 Partindo do menu se chega à lista e ao cadastro. */
    public function test_o_menu_leva_a_lista_e_ao_cadastro_de_clientes(): void
    {
        $admin = $this->admin();

        $inicio = $this->actingAs($admin)->get(route('centro-controle'))->assertOk();

        $encontrou = false;

        // Salto 1: cada item do menu. Salto 2: os links daquela página.
        foreach ($this->linksDoMenu($inicio->getContent()) as $doMenu) {
            $pagina = $this->actingAs($admin)->get($doMenu);

            if ($pagina->status() !== 200) {
                continue;
            }

            foreach ([$doMenu, ...$this->linksDaPagina($pagina->getContent())] as $candidato) {
                $destino = $this->actingAs($admin)->get($candidato);

                if ($destino->status() !== 200) {
                    continue;
                }

                $html = $destino->getContent();

                if (str_contains($html, 'Academia Corpo em Movimento')
                    && str_contains($html, self::CAMPO_DO_FORMULARIO)
                    && str_contains($html, self::BOTAO_NOVO_CLIENTE)) {
                    $encontrou = true;
                    break 2;
                }
            }
        }

        $this->assertTrue(
            $encontrou,
            'Não existe caminho, a partir do menu e em até dois saltos, até uma tela '
            .'que mostre os clientes e permita cadastrar um novo. Foi exatamente esta '
            .'a queixa que originou a feature: "não vi em lugar nenhum interface para '
            .'cadastrar clientes".'
        );
    }

    /**
     * Links internos de uma página já carregada (o segundo salto).
     *
     * O endereço vem de `config('app.url')`, não escrito à mão: com
     * `http://localhost` fixo aqui, a varredura só enxergava links no ambiente
     * de quem tem essa APP_URL. No staging, onde ela é o endereço Tailscale,
     * nenhum link casava, o segundo salto vinha vazio e o teste acusava
     * "não existe caminho" — com a tela funcionando perfeitamente.
     */
    private function linksDaPagina(string $html): array
    {
        $base = preg_quote(rtrim(config('app.url'), '/'), '/');

        preg_match_all('/href="('.$base.'[^"#]*)"/', $html, $hrefs);

        return array_values(array_unique($hrefs[1]));
    }

    /** @spec:AC-118 A revenda cai direto na carteira dela. */
    public function test_revenda_cai_na_carteira_de_clientes(): void
    {
        $usuario = $this->usuarioDaRevenda();

        // Pela raiz.
        $this->actingAs($usuario)
            ->get('/')
            ->assertRedirect(route('revendas.index', ['aba' => 'clientes']));

        // E pelo item de menu dela, que aponta para o mesmo recorte.
        $inicio = $this->actingAs($usuario)
            ->get(route('revendas.index', ['aba' => 'clientes']))
            ->assertOk();

        $this->assertContains(
            route('revendas.index', ['aba' => 'clientes']),
            $this->linksDoMenu($inicio->getContent()),
            'O item de menu da revenda não aponta para a carteira de clientes dela.'
        );

        $inicio->assertSee('Academia Corpo em Movimento');
        $inicio->assertSee(self::BOTAO_NOVO_CLIENTE);
        $inicio->assertSee(self::CAMPO_DO_FORMULARIO, escape: false);

        // Navegação nova não pode abrir porta para a carteira alheia.
        $inicio->assertDontSee('Academia da Concorrente');
        $inicio->assertDontSee('Concorrente Ltda');
    }

    /** @spec:AC-118 Nenhum item do menu da revenda termina em 403. */
    public function test_o_menu_da_revenda_nao_oferece_porta_fechada(): void
    {
        $usuario = $this->usuarioDaRevenda();

        $inicio = $this->actingAs($usuario)->get(route('revendas.index', ['aba' => 'clientes']))->assertOk();

        foreach ($this->linksDoMenu($inicio->getContent()) as $link) {
            $this->actingAs($usuario)
                ->get($link)
                ->assertOk("O menu da revenda oferece {$link}, que ela não pode abrir.");
        }
    }

    /** @spec:AC-119 Filtrar na aba de clientes não expulsa da aba. */
    public function test_filtrar_na_aba_de_clientes_continua_na_aba(): void
    {
        $admin = $this->admin();

        // O formulário de filtro carrega a aba consigo...
        $this->actingAs($admin)
            ->get(route('revendas.index', ['aba' => 'clientes']))
            ->assertSee('name="aba"', escape: false);

        // ...e buscar mantém o usuário na aba, com o resultado.
        $resposta = $this->actingAs($admin)
            ->get(route('revendas.index', ['aba' => 'clientes', 'busca' => 'Corpo em Movimento']))
            ->assertOk();

        $resposta->assertSee('Academia Corpo em Movimento');
        $resposta->assertDontSee('Academia da Concorrente');
        $resposta->assertSee(self::BOTAO_NOVO_CLIENTE);
    }

    /** @spec:AC-120 Recusa no cadastro reabre o formulário com o motivo. */
    public function test_erro_no_cadastro_reabre_o_formulario(): void
    {
        $admin = $this->admin();

        // Formulário incompleto: falta tipo_cliente, exigido pelo controller.
        $resposta = $this->actingAs($admin)
            ->from(route('revendas.index', ['aba' => 'clientes']))
            ->post(route('clientes.store'), [
                'revenda_id' => $this->minhaRevenda->id,
                'tipo_pessoa' => 'PJ',
                'razao_social' => 'Corpo em Movimento Ltda',
            ])
            ->assertSessionHasErrors();

        $volta = $this->actingAs($admin)->followingRedirects()->get($resposta->headers->get('Location'));

        // `show: true` é o que o x-modal imprime no x-data quando nasce aberto.
        // Sem isso o usuário volta para a lista e a tela parece não ter feito nada.
        $volta->assertSee('show: true', escape: false);
    }

    /** @spec:AC-121 Botão só aparece para quem pode usá-lo. */
    public function test_quem_nao_pode_incluir_nao_ve_o_botao(): void
    {
        $financeiro = User::factory()->semPerfil()->create();
        $financeiro->perfis()->attach(Perfil::where('slug', 'financeiro')->value('id'));

        // O perfil financeiro lê clientes, mas não inclui.
        $this->assertTrue($financeiro->canPermissao('clientes', 'ler'));
        $this->assertFalse($financeiro->canPermissao('clientes', 'incluir'));

        $this->actingAs($financeiro)
            ->get(route('revendas.index', ['aba' => 'clientes']))
            ->assertOk()
            ->assertDontSee(self::BOTAO_NOVO_CLIENTE);
    }

    /** @spec:AC-121 A revenda não vê o botão de cadastrar revenda. */
    public function test_revenda_nao_ve_o_botao_de_nova_revenda(): void
    {
        $this->actingAs($this->usuarioDaRevenda())
            ->get(route('revendas.index'))
            ->assertOk()
            ->assertDontSee('+ Nova revenda');
    }

    /** @spec:AC-116 A tela órfã de cadastro não volta. */
    public function test_a_tela_orfa_de_cadastro_nao_existe_mais(): void
    {
        // Cadastro de cliente tem UM lugar: o modal da lista. Duas telas para a
        // mesma coisa é uma que vai divergir da outra — e foi a que ninguém
        // linkava que ficou órfã.
        $this->assertFalse(Route::has('clientes.create'));
        $this->assertFileDoesNotExist(resource_path('views/clientes/create.blade.php'));
    }
}
