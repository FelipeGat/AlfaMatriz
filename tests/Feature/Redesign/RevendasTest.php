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
 * A tela de Revendas — a primeira das telas de tabela.
 *
 * O critério cobre as três telas de cadastro operacional (Revendas, Clientes,
 * Produtos); aqui se prova a gramática comum: resumo no topo, filtro que vive
 * na query string, tabela com dado do banco e rodapé declarando o recorte.
 */
class RevendasTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create();
    }

    private function cenario(): array
    {
        $sistema = Sistema::create([
            'nome' => 'AlfaGym', 'slug' => 'alfagym',
            'unidade_cobranca' => 'academia ativa', 'ativo' => true,
        ]);

        $grande = Revenda::create(['nome' => 'Invest Soluções', 'cnpj' => '12.345.678/0001-90', 'contato_nome' => 'Marina', 'ativo' => true]);
        $pequena = Revenda::create(['nome' => 'Orbe Soft', 'ativo' => false]);

        // 3 clientes na grande (um deles com sistema), 1 na pequena.
        foreach (range(1, 3) as $i) {
            $cliente = Cliente::create(['nome' => "Academia {$i}", 'revenda_id' => $grande->id, 'ativo' => true]);
            $cliente->sistemas()->attach($sistema->id, ['ativo' => true]);
        }
        Cliente::create(['nome' => 'Cliente Orbe', 'revenda_id' => $pequena->id, 'ativo' => true]);

        // MRR do mês e do mês anterior, para a variação existir.
        Cobranca::create([
            'descricao' => 'Atacado Invest', 'valor' => 8940.00, 'revenda_id' => $grande->id,
            'data_vencimento' => now()->toDateString(), 'status' => 'pendente',
            'tipo' => 'locacao_sistema', 'competencia' => now()->format('Y-m'),
        ]);
        Cobranca::create([
            'descricao' => 'Atacado Invest (mês passado)', 'valor' => 8000.00, 'revenda_id' => $grande->id,
            'data_vencimento' => now()->subMonth()->toDateString(), 'status' => 'pago',
            'tipo' => 'locacao_sistema', 'competencia' => now()->subMonth()->format('Y-m'),
        ]);

        // Uma cobrança vencida e não paga: é o que marca a linha em âmbar.
        Cobranca::create([
            'descricao' => 'Atrasada', 'valor' => 500.00, 'revenda_id' => $grande->id,
            'data_vencimento' => now()->subDays(9)->toDateString(), 'status' => 'pendente',
            'tipo' => 'avulsa',
        ]);

        return [$grande, $pequena];
    }

    /**
     * @spec:AC-048 Revendas, Clientes e Produtos trazem resumo, filtro, tabela
     * e contagem — os quatro números no topo, os filtros de recorte, a tabela
     * com dado do banco e o rodapé dizendo quantos de quantos estão à vista.
     */
    public function test_revendas_traz_resumo_filtro_tabela_e_contagem(): void
    {
        [$grande, $pequena] = $this->cenario();

        $resposta = $this->actingAs($this->operador())->get(route('revendas.index'));
        $resposta->assertOk();

        // ── Resumo: quatro números, e cada um diz de onde saiu.
        $kpis = $resposta->viewData('kpis');
        $this->assertSame(1, $kpis['ativas']['valor'], 'Só uma das duas revendas está ativa.');
        $this->assertSame('de 2 cadastradas', $kpis['ativas']['nota']);
        $this->assertSame(4, $kpis['clientes']['valor'], 'Quatro clientes ativos vêm de revenda.');
        $this->assertEqualsWithDelta(8940.0, $kpis['mrr']['valor'], 0.01);

        // ── Tabela: o dado é o do banco, não um enfeite.
        $linhas = $resposta->viewData('linhas');
        $invest = $linhas->firstWhere('revenda.id', $grande->id);

        $this->assertSame(3, $invest['clientes']);
        $this->assertEqualsWithDelta(8940.0, $invest['mrr'], 0.01);
        $this->assertSame(['AlfaGym'], $invest['sistemas']);
        $this->assertSame(1, $invest['emAtraso'], 'A cobrança vencida precisa marcar a linha.');

        // 8000 → 8940 é +11,8%.
        $this->assertStringContainsString('11,8%', $invest['delta']);

        // ── Totais somados das linhas.
        $totais = $resposta->viewData('totais');
        $this->assertSame(4, $totais['clientes']);
        $this->assertEqualsWithDelta(8940.0, $totais['mrr'], 0.01);
        $this->assertSame(1, $totais['sistemas']);

        // ── Rodapé declara o recorte, e a linha de total usa o componente que
        // impede a faixa de ganhar duas alturas.
        $resposta->assertSee('2 de 2 revendas', escape: false);
        $resposta->assertSee('Invest Soluções', escape: false);
        $resposta->assertSee('12.345.678/0001-90', escape: false);
        // A faixa de total precisa vir do componente — é ele que aplica o
        // `nowrap` em todas as células. (No HTML a classe sai escapada, então
        // quem responde essa pergunta é a fonte da tela.)
        $this->assertStringContainsString(
            '<x-linha-total>',
            file_get_contents(base_path('resources/views/revendas/index.blade.php')),
            'A linha de totais precisa vir de <x-linha-total>, senão os rótulos em caixa alta quebram.'
        );
    }

    /**
     * @spec:AC-048 O filtro recorta a lista e vive na query string, para o
     * recorte poder ser compartilhado por link.
     */
    public function test_o_filtro_recorta_a_lista_e_sobrevive_no_link(): void
    {
        [$grande, $pequena] = $this->cenario();
        $operador = $this->operador();

        // Busca por nome.
        $resposta = $this->actingAs($operador)->get(route('revendas.index', ['q' => 'Orbe']));
        $resposta->assertOk();
        $this->assertCount(1, $resposta->viewData('linhas'));
        $this->assertSame('Orbe Soft', $resposta->viewData('linhas')->first()['revenda']->nome);

        // Busca por CNPJ encontra a outra.
        $resposta = $this->actingAs($operador)->get(route('revendas.index', ['q' => '12.345.678']));
        $this->assertSame('Invest Soluções', $resposta->viewData('linhas')->first()['revenda']->nome);

        // Recorte por status.
        $resposta = $this->actingAs($operador)->get(route('revendas.index', ['status' => 'inativo']));
        $this->assertCount(1, $resposta->viewData('linhas'));
        $this->assertFalse((bool) $resposta->viewData('linhas')->first()['revenda']->ativo);

        // Ordenação: por clientes a maior base vem primeiro.
        $resposta = $this->actingAs($operador)->get(route('revendas.index', ['ordem' => 'clientes']));
        $this->assertSame('Invest Soluções', $resposta->viewData('linhas')->first()['revenda']->nome);

        // Por nome, a ordem é alfabética.
        $resposta = $this->actingAs($operador)->get(route('revendas.index', ['ordem' => 'nome']));
        $this->assertSame('Invest Soluções', $resposta->viewData('linhas')->first()['revenda']->nome);

        // O recorte volta preenchido no formulário — é o que faz o link valer.
        $resposta = $this->actingAs($operador)->get(route('revendas.index', ['q' => 'Orbe', 'status' => 'inativo']));
        $resposta->assertSee('value="Orbe"', escape: false);
        $this->assertSame('inativo', $resposta->viewData('filtros')['status']);
        $resposta->assertSee('1 de 2 revendas', escape: false);
    }
}
