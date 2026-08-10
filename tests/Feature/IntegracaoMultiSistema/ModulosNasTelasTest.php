<?php

namespace Tests\Feature\IntegracaoMultiSistema;

use App\Models\Cliente;
use App\Models\ClienteModulo;
use App\Models\Modulo;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Módulos nas telas — só leitura na Fase 1.
 *
 * Quem contrata e cancela é o painel do sistema de origem. A Matriz mostra o
 * retrato porque é ele que compõe a receita: sem essa linha, o MRR do produto
 * aparece menor do que é.
 */
class ModulosNasTelasTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $control;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->control = Sistema::factory()->alfacontrol()->create(['token' => 'chave-control']);

        $revenda = Revenda::create(['nome' => 'Invest', 'cnpj' => '12345678000199', 'ativo' => true]);

        $this->cliente = Cliente::create([
            'nome' => 'Condomínio Central', 'revenda_id' => $revenda->id, 'ativo' => true,
        ]);
        $this->cliente->sistemas()->attach($this->control->id, [
            'ativo' => true, 'status_saas' => 'ativo',
            'licenca_status' => 'ativa', 'licenca_id_externo' => '77',
        ]);

        $financeiro = Modulo::create([
            'sistema_id' => $this->control->id, 'codigo' => 'FINANCEIRO',
            'nome' => 'Financeiro', 'ativo' => true,
        ]);
        $refeitorio = Modulo::create([
            'sistema_id' => $this->control->id, 'codigo' => 'REFEITORIO',
            'nome' => 'Refeitório', 'ativo' => true,
        ]);

        ClienteModulo::create([
            'cliente_id' => $this->cliente->id, 'modulo_id' => $financeiro->id,
            'status' => 'ativo', 'data_inicio' => '2026-01-01', 'valor_mensal' => 49.90,
        ]);
        ClienteModulo::create([
            'cliente_id' => $this->cliente->id, 'modulo_id' => $refeitorio->id,
            'status' => 'ativo', 'data_inicio' => '2026-02-01', 'valor_mensal' => 30.10,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create();
    }

    /**
     * @spec:AC-150 A ficha do cliente mostra os módulos contratados, com valor
     * e origem — e sem oferecer edição, que não existe nesta fase.
     */
    public function test_a_ficha_do_cliente_mostra_os_modulos(): void
    {
        $resposta = $this->actingAs($this->admin())->get(route('clientes.edit', $this->cliente));

        $resposta->assertOk();
        $resposta->assertSee('Módulos contratados', escape: false);
        $resposta->assertSee('Financeiro', escape: false);
        $resposta->assertSee('Refeitório', escape: false);
        $resposta->assertSee('49,90', escape: false);
        // A origem precisa estar declarada: quem lê tem de saber onde mexer.
        $resposta->assertSee('Gerenciados no painel do sistema de origem', escape: false);
    }

    /**
     * @spec:AC-150 Nada de checkbox: prometer uma edição que não existe é pior
     * do que não mostrar.
     */
    public function test_a_ficha_nao_oferece_edicao_de_modulo(): void
    {
        $html = $this->actingAs($this->admin())->get(route('clientes.edit', $this->cliente))->getContent();

        $this->assertStringNotContainsString('name="modulos[]"', $html);
    }

    /**
     * @spec:AC-151 A tela de Produtos soma a receita de módulos do sistema —
     * é receita cobrada à parte da licença.
     */
    public function test_a_tela_de_produtos_soma_a_receita_de_modulos(): void
    {
        $resposta = $this->actingAs($this->admin())->get(route('produtos.index'));

        $resposta->assertOk();
        $resposta->assertSee('Módulos', escape: false);
        // 49,90 + 30,10
        $resposta->assertSee('80,00', escape: false);
    }

    /**
     * @spec:AC-151 Sistema sem módulo nenhum não ganha linha vazia na tela.
     */
    public function test_produto_sem_modulos_nao_mostra_a_linha(): void
    {
        Sistema::factory()->alfagym()->create(['token' => 'chave-gym']);

        $html = $this->actingAs($this->admin())->get(route('produtos.index'))->getContent();

        // Uma ocorrência só — a do AlfaControl, que tem módulos.
        $this->assertSame(1, substr_count($html, 'Módulos <span'));
    }

    /**
     * @spec:AC-152 Contratação encerrada não conta como receita corrente.
     */
    public function test_modulo_encerrado_nao_soma_receita(): void
    {
        ClienteModulo::query()->update(['status' => 'inativo', 'data_fim' => '2026-06-30']);

        $html = $this->actingAs($this->admin())->get(route('produtos.index'))->getContent();

        $this->assertStringNotContainsString('Módulos <span', $html);
    }
}
