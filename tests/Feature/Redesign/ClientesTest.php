<?php

namespace Tests\Feature\Redesign;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A tela de Clientes.
 *
 * O que ela precisa responder de relance: quem é da revenda e quem é venda
 * direta, quem está em contrato e quem é avulso, e — o que mais importa — quem
 * está devendo. O estado de pagamento não mora no cliente: sai das cobranças,
 * e é ele que marca a linha.
 */
class ClientesTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create();
    }

    /**
     * @spec:AC-048 A lista de Clientes traz resumo, filtro, tabela e contagem —
     * inclusive o estado de pagamento, que é o que decide a cor da linha.
     */
    public function test_clientes_traz_resumo_tabela_com_pagamento_e_contagem(): void
    {
        $revenda = Revenda::create(['nome' => 'Invest Soluções', 'ativo' => true]);
        $sistema = Sistema::create([
            'nome' => 'AlfaGym', 'slug' => 'alfagym',
            'unidade_cobranca' => 'academia ativa', 'ativo' => true,
        ]);

        $emDia = Cliente::create([
            'nome' => 'Academia Central', 'revenda_id' => $revenda->id, 'ativo' => true,
            'tipo_cliente' => 'CONTRATO', 'valor_mensal' => 540.00, 'dia_vencimento' => 15,
            'cidade' => 'Belo Horizonte', 'uf' => 'MG',
        ]);
        $emDia->sistemas()->attach($sistema->id, ['ativo' => true]);

        $devendo = Cliente::create([
            'nome' => 'Studio Norte', 'ativo' => true,
            'tipo_cliente' => 'AVULSO', 'valor_mensal' => 260.00,
            'cidade' => 'Contagem', 'uf' => 'MG',
        ]);

        $semCobranca = Cliente::create(['nome' => 'Cliente Novo', 'ativo' => false, 'tipo_cliente' => 'AVULSO']);

        // Uma cobrança paga no dia, uma vencida há 6 dias.
        Cobranca::create([
            'descricao' => 'Mensalidade', 'valor' => 540.00, 'cliente_id' => $emDia->id,
            'data_vencimento' => now()->addDays(9)->toDateString(), 'status' => 'pendente', 'tipo' => 'direta',
        ]);
        Cobranca::create([
            'descricao' => 'Serviço avulso', 'valor' => 260.00, 'cliente_id' => $devendo->id,
            'data_vencimento' => now()->subDays(6)->toDateString(), 'status' => 'pendente', 'tipo' => 'avulsa',
        ]);

        $resposta = $this->actingAs($this->operador())->get(route('clientes.index'));
        $resposta->assertOk();

        // ── Resumo
        $kpis = $resposta->viewData('kpis');
        $this->assertSame(3, $kpis['cadastrados']['valor']);
        $this->assertSame('2 ativos · 1 inativos', $kpis['cadastrados']['nota']);
        $this->assertSame(1, $kpis['contrato']['valor']);
        $this->assertSame(1, $kpis['avulsos']['valor'], 'Só os ativos entram na conta de avulsos.');
        $this->assertEqualsWithDelta(540.0, $kpis['ticket']['valor'], 0.01);

        // ── Estado de pagamento: os três casos que a tela distingue.
        $pagamentos = $resposta->viewData('pagamentos');
        $this->assertSame('em_dia', $pagamentos[$emDia->id]['estado']);
        $this->assertSame('atrasado', $pagamentos[$devendo->id]['estado']);
        $this->assertSame(6, $pagamentos[$devendo->id]['dias'], 'A tela mostra há quantos dias o título venceu.');
        $this->assertSame('sem_cobranca', $pagamentos[$semCobranca->id]['estado']);

        $resposta->assertSee('Atrasado 6d', escape: false);
        $resposta->assertSee('Em dia', escape: false);
        $resposta->assertSee('Sem cobrança', escape: false);

        // Venda direta é da casa e aparece nomeada, não como campo vazio.
        $resposta->assertSee('Venda direta', escape: false);
        $resposta->assertSee('Belo Horizonte/MG', escape: false);
        $resposta->assertSee('AlfaGym', escape: false);

        // ── Totais e rodapé
        $totais = $resposta->viewData('totais');
        $this->assertSame(1, $totais['contratos']);
        $this->assertSame(2, $totais['avulsos']);
        $this->assertSame(1, $totais['atrasados']);
        $resposta->assertSee('3 de 3 clientes', escape: false);

        $this->assertStringContainsString(
            '<x-linha-total>',
            file_get_contents(base_path('resources/views/clientes/index.blade.php'))
        );
    }

    /**
     * @spec:AC-048 Os filtros de Clientes recortam por busca, origem, sistema e
     * status, e o recorte vive na query string.
     */
    public function test_os_filtros_de_clientes_recortam_a_lista(): void
    {
        $revenda = Revenda::create(['nome' => 'Invest Soluções', 'ativo' => true]);
        $sistema = Sistema::create([
            'nome' => 'AlfaGym', 'slug' => 'alfagym',
            'unidade_cobranca' => 'academia ativa', 'ativo' => true,
        ]);

        $daRevenda = Cliente::create([
            'nome' => 'Academia Central', 'revenda_id' => $revenda->id, 'ativo' => true,
            'cpf_cnpj' => '11.222.333/0001-44', 'cidade' => 'Belo Horizonte',
        ]);
        $daRevenda->sistemas()->attach($sistema->id, ['ativo' => true]);

        Cliente::create(['nome' => 'Studio Norte', 'ativo' => false, 'cidade' => 'Contagem']);

        $operador = $this->operador();

        // Busca por nome, por documento e por cidade.
        foreach (['Studio' => 'Studio Norte', '11.222.333' => 'Academia Central', 'Contagem' => 'Studio Norte'] as $termo => $esperado) {
            $resposta = $this->actingAs($operador)->get(route('clientes.index', ['busca' => $termo]));
            $this->assertCount(1, $resposta->viewData('clientes'), "A busca por \"{$termo}\" deveria achar um cliente.");
            $this->assertSame($esperado, $resposta->viewData('clientes')->first()->nome);
        }

        // Origem: revenda e venda direta são recortes distintos.
        $resposta = $this->actingAs($operador)->get(route('clientes.index', ['revenda' => $revenda->id]));
        $this->assertCount(1, $resposta->viewData('clientes'));
        $this->assertSame('Academia Central', $resposta->viewData('clientes')->first()->nome);

        $resposta = $this->actingAs($operador)->get(route('clientes.index', ['revenda' => 'direta']));
        $this->assertCount(1, $resposta->viewData('clientes'));
        $this->assertSame('Studio Norte', $resposta->viewData('clientes')->first()->nome);

        // Sistema licenciado.
        $resposta = $this->actingAs($operador)->get(route('clientes.index', ['sistema' => $sistema->id]));
        $this->assertCount(1, $resposta->viewData('clientes'));

        // Status.
        $resposta = $this->actingAs($operador)->get(route('clientes.index', ['status' => 'inativo']));
        $this->assertCount(1, $resposta->viewData('clientes'));
        $this->assertFalse((bool) $resposta->viewData('clientes')->first()->ativo);

        // O recorte volta preenchido, para o link valer.
        $resposta = $this->actingAs($operador)->get(route('clientes.index', ['busca' => 'Studio', 'status' => 'inativo']));
        $this->assertSame('Studio', $resposta->viewData('filtros')['busca']);
        $this->assertSame('inativo', $resposta->viewData('filtros')['status']);
        $resposta->assertSee('1 de 1 clientes', escape: false);
    }
}
