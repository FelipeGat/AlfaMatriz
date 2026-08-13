<?php

namespace Tests\Feature\Autorizacao;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\Lead;
use App\Models\Perfil;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A autorização em duas camadas: o middleware de permissão (quem pode abrir
 * cada tela) e o escopo por revenda (quem, podendo abrir, só vê o próprio
 * portfólio). Usuário com revenda_id é de uma revenda: trabalha nos próprios
 * clientes/cobranças/leads e não enxerga a matriz.
 */
class EscopoDeRevendaTest extends TestCase
{
    use RefreshDatabase;

    /** Usuário da matriz: sem revenda, perfil admin (todas permissões). */
    private function usuarioDaMatriz(): User
    {
        return User::factory()->create();
    }

    /** Usuário de uma revenda: tem escopo, perfil admin. */
    private function usuarioDaRevenda(Revenda $revenda): User
    {
        return User::factory()->create(['revenda_id' => $revenda->id]);
    }

    /** Usuário sem perfil nenhum: deve tomar 403 do middleware em qualquer tela. */
    private function usuarioSemPerfil(): User
    {
        return User::factory()->semPerfil()->create();
    }

    private function cenario(): array
    {
        $sistema = Sistema::create([
            'nome' => 'AlfaGym', 'slug' => 'alfagym',
            'unidade_cobranca' => 'academia ativa', 'ativo' => true,
        ]);

        $alpha = Revenda::create(['nome' => 'Alpha Rev', 'ativo' => true]);
        $beta = Revenda::create(['nome' => 'Beta Rev', 'ativo' => true]);

        $clienteAlpha = Cliente::create(['nome' => 'Cliente Alpha', 'revenda_id' => $alpha->id, 'ativo' => true]);
        $clienteAlpha->sistemas()->attach($sistema->id, ['ativo' => true]);
        $clienteBeta = Cliente::create(['nome' => 'Cliente Beta', 'revenda_id' => $beta->id, 'ativo' => true]);

        Cobranca::create([
            'descricao' => 'Mensalidade Alpha', 'valor' => 100.00, 'revenda_id' => $alpha->id,
            'cliente_id' => $clienteAlpha->id, 'data_vencimento' => now()->addDays(5)->toDateString(),
            'status' => 'pendente', 'tipo' => 'direta',
        ]);
        Cobranca::create([
            'descricao' => 'Mensalidade Beta', 'valor' => 200.00, 'revenda_id' => $beta->id,
            'cliente_id' => $clienteBeta->id, 'data_vencimento' => now()->addDays(5)->toDateString(),
            'status' => 'pendente', 'tipo' => 'direta',
        ]);

        Lead::create(['nome' => 'Lead Alpha', 'revenda_id' => $alpha->id, 'estagio' => 'lead', 'tipo_interesse' => 'saas']);
        Lead::create(['nome' => 'Lead Beta', 'revenda_id' => $beta->id, 'estagio' => 'lead', 'tipo_interesse' => 'saas']);

        return compact('sistema', 'alpha', 'beta', 'clienteAlpha', 'clienteBeta');
    }

    /**
     * @spec:AC-XXX O middleware devolve 403 para quem não tem o recurso: um
     * usuário sem perfil nenhum não abre tela autenticada.
     */
    public function test_usuario_sem_perfil_toma_403_em_toda_tela(): void
    {
        $this->cenario();
        $semPerfil = $this->usuarioSemPerfil();

        foreach (['clientes.index', 'revendas.index', 'cobrancas.index', 'leads.index', 'faturamento.index'] as $rota) {
            $this->actingAs($semPerfil)->get(route($rota))->assertForbidden();
        }
    }

    /**
     * @spec:AC-XXX Usuário de revenda só vê a própria revenda na lista de
     * clientes — o dobro do que seria dele não aparece.
     */
    public function test_usuario_de_revenda_so_ve_clientes_da_propria_revenda(): void
    {
        $cenario = $this->cenario();
        $usuario = $this->usuarioDaRevenda($cenario['alpha']);

        $resposta = $this->actingAs($usuario)->get(route('clientes.index'));

        $resposta->assertOk();
        $nomes = $resposta->viewData('clientes')->pluck('nome')->all();
        $this->assertSame(['Cliente Alpha'], $nomes);
    }

    /**
     * @spec:AC-XXX Mesmo com o filtro por revenda no query string, o escopo
     * vence: pedir a revenda do vizinho não entrega nada dele.
     */
    public function test_filtro_por_revenda_nao_vaza_entre_revendas(): void
    {
        $cenario = $this->cenario();
        $usuario = $this->usuarioDaRevenda($cenario['alpha']);

        $resposta = $this->actingAs($usuario)->get(route('clientes.index', ['revenda' => $cenario['beta']->id]));

        $resposta->assertOk();
        $this->assertSame(['Cliente Alpha'], $resposta->viewData('clientes')->pluck('nome')->all());
    }

    /**
     * @spec:AC-XXX Usuário de revenda não edita o cliente de outra revenda:
     * o recurso existe, mas o dono é outro.
     */
    public function test_usuario_de_revenda_nao_edita_cliente_alheio(): void
    {
        $cenario = $this->cenario();
        $usuario = $this->usuarioDaRevenda($cenario['alpha']);

        $this->actingAs($usuario)->get(route('clientes.edit', $cenario['clienteBeta']))
            ->assertForbidden();
    }

    /**
     * @spec:AC-XXX Na lista de cobranças, o usuário de revenda vê só as da
     * própria revenda, e os KPIs respeitam o mesmo recorte.
     */
    public function test_usuario_de_revenda_so_ve_cobrancas_da_propria_revenda(): void
    {
        $cenario = $this->cenario();
        $usuario = $this->usuarioDaRevenda($cenario['alpha']);

        $resposta = $this->actingAs($usuario)->get(route('cobrancas.index'));

        $resposta->assertOk();
        $descricoes = $resposta->viewData('cobrancas')->pluck('descricao')->all();
        $this->assertSame(['Mensalidade Alpha'], $descricoes);
        $this->assertSame(100.0, $resposta->viewData('kpis')['em_aberto']);
    }

    /**
     * @spec:AC-XXX O funil de leads também respeita o escopo: só os da própria
     * revenda aparecem no quadro.
     */
    public function test_usuario_de_revenda_so_ve_leads_da_propria_revenda(): void
    {
        $cenario = $this->cenario();
        $usuario = $this->usuarioDaRevenda($cenario['alpha']);

        $resposta = $this->actingAs($usuario)->get(route('leads.index'));

        $resposta->assertOk();
        $nomes = collect($resposta->viewData('colunas'))->flatten()->pluck('nome')->all();
        $this->assertSame(['Lead Alpha'], $nomes);
    }

    /**
     * @spec:AC-XXX As visões globais da matriz (centro de controle, dashboard,
     * comercial, produtos) são bloqueadas para usuário de revenda.
     */
    public function test_usuario_de_revenda_nao_ve_visoes_globais_da_matriz(): void
    {
        $cenario = $this->cenario();
        $usuario = $this->usuarioDaRevenda($cenario['alpha']);

        foreach (['centro-controle', 'dashboard', 'comercial', 'produtos.index', 'sistemas.index'] as $rota) {
            $this->actingAs($usuario)->get(route($rota))->assertForbidden();
        }
    }

    /**
     * @spec:AC-XXX Usuário de revenda não cadastra outra revenda (o portfólio
     * é provisionado pela matriz) e não vê a própria revenda como modificável
     * se o recurso for de outra.
     */
    public function test_usuario_de_revenda_nao_cadastra_revenda_nem_abre_a_alheia(): void
    {
        $cenario = $this->cenario();
        $usuario = $this->usuarioDaRevenda($cenario['alpha']);

        $this->actingAs($usuario)->get(route('revendas.create'))->assertForbidden();
        $this->actingAs($usuario)->get(route('revendas.edit', $cenario['beta']))->assertForbidden();
    }

    /**
     * @spec:AC-XXX Usuário de revenda pode editar a PRÓPRIA revenda — o
     * escopo protege o vizinho, não o próprio dono.
     */
    public function test_usuario_de_revenda_edita_a_propria_revenda(): void
    {
        $cenario = $this->cenario();
        $usuario = $this->usuarioDaRevenda($cenario['alpha']);

        $this->actingAs($usuario)->get(route('revendas.edit', $cenario['alpha']))->assertOk();
    }

    /**
     * @spec:AC-XXX O menu esconde os itens de gestão da matriz para usuário de
     * revenda — o que daria 403 não aparece como link.
     */
    public function test_menu_esconde_itens_de_matriz_para_usuario_de_revenda(): void
    {
        $cenario = $this->cenario();
        $usuario = $this->usuarioDaRevenda($cenario['alpha']);

        $resposta = $this->actingAs($usuario)->get(route('clientes.index'));

        $resposta->assertOk();
        foreach (['centro-controle', 'dashboard', 'comercial', 'produtos.index', 'contas-pagar.index', 'contas-financeiras.index', 'cadastros-auxiliares.index', 'usuarios.index'] as $rota) {
            $resposta->assertDontSee(route($rota), escape: false);
        }
        foreach (['leads.index', 'clientes.index', 'cobrancas.index', 'faturamento.index', 'revendas.index'] as $rota) {
            $resposta->assertSee(route($rota), escape: false);
        }
    }

    /**
     * @spec:AC-XXX A tela de usuários é só do administrador da matriz. O
     * recurso `usuarios` já existia no seeder desde o primeiro dia e só o
     * perfil `admin` o recebe: nenhum outro perfil abre a tela nem salva a
     * grade de permissões de ninguém.
     */
    public function test_so_o_administrador_alcanca_a_tela_de_usuarios(): void
    {
        // Semeado à mão: quem monta o cenário aqui é `semPerfil()`, que pula o
        // seeder de propósito — e sem ele não há perfil nenhum para anexar.
        (new PerfilPermissaoSeeder)->run();

        $admin = Perfil::where('slug', 'admin')->firstOrFail();

        foreach (['financeiro', 'operacao', 'membro'] as $slug) {
            $usuario = User::factory()->semPerfil()->create();
            $usuario->perfis()->attach(Perfil::where('slug', $slug)->value('id'));

            $this->actingAs($usuario)->get(route('usuarios.index'))->assertForbidden();
            $this->actingAs($usuario)->post(route('usuarios.store'), [])->assertForbidden();
            $this->actingAs($usuario)->put(route('perfis.permissoes', $admin), [])->assertForbidden();
        }
    }

    /**
     * @spec:AC-XXX Contas do painel são gestão da matriz: a revenda não
     * cadastra nem desativa ninguém, mesmo com perfil administrativo.
     */
    public function test_usuario_de_revenda_nao_alcanca_a_tela_de_usuarios(): void
    {
        $cenario = $this->cenario();
        $usuario = $this->usuarioDaRevenda($cenario['alpha']);

        $this->actingAs($usuario)->get(route('usuarios.index'))->assertForbidden();
        $this->actingAs($usuario)
            ->put(route('perfis.permissoes', Perfil::where('slug', 'operacao')->firstOrFail()), [])
            ->assertForbidden();
    }

    /**
     * @spec:AC-XXX Usuário de revenda não gera o fechamento do ciclo de
     * faturamento — decisão da matriz.
     */
    public function test_usuario_de_revenda_nao_gera_faturamento(): void
    {
        $cenario = $this->cenario();
        $usuario = $this->usuarioDaRevenda($cenario['alpha']);

        $this->actingAs($usuario)->post(route('faturamento.gerar', ['competencia' => now()->format('Y-m')]))
            ->assertForbidden();
    }
}
