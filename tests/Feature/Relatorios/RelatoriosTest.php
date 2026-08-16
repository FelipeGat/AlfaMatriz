<?php

namespace Tests\Feature\Relatorios;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\ContaPagar;
use App\Models\Lead;
use App\Models\Revenda;
use App\Models\Tarefa;
use App\Models\TarefaEvento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A aba de Relatórios: uma tela, quatro seções (`?secao=`), navegando por
 * competência como o Painel Financeiro. A porta é o recurso `relatorios`
 * (seeder + migração `2026_08_16_120000_permissao_de_relatorios.php`), e a
 * tela é da matriz — revenda não entra nem com permissão de sobra.
 */
class RelatoriosTest extends TestCase
{
    use RefreshDatabase;

    public function test_cada_secao_abre_e_mostra_seus_paineis(): void
    {
        $this->actingAs(User::factory()->create());

        $paineisPorSecao = [
            'comercial' => [
                'Avanço do funil', 'Vendas por vendedor', 'Perdas por motivo',
                'Em aberto por temperatura', 'Em aberto por origem', 'Maiores negócios em aberto',
            ],
            'financeiro' => [
                'Entradas x saídas', 'A receber em aberto', 'A pagar em aberto', 'Despesa por centro de custo',
                'Receita da competência por tipo', 'A receber por faixa de vencimento', 'Maiores títulos vencidos',
            ],
            'desenvolvimento' => [
                'O quadro agora', 'Concluídas por sistema', 'Concluídas por responsável',
                'Permanência média por etapa', 'Devolvidas de portão', 'Concluídas na competência',
            ],
            'sistema' => [
                'Base instalada por sistema', 'Usuários por perfil', 'Auditoria por recurso',
                'Clientes ativos por UF', 'Auditoria por ação', 'Últimas ações registradas',
            ],
        ];

        foreach ($paineisPorSecao as $secao => $paineis) {
            $resposta = $this->get(route('relatorios.index', ['secao' => $secao]));

            $resposta->assertOk();
            foreach ($paineis as $painel) {
                $resposta->assertSee($painel);
            }
        }
    }

    public function test_secao_desconhecida_cai_na_comercial(): void
    {
        $this->actingAs(User::factory()->create());

        $resposta = $this->get(route('relatorios.index', ['secao' => 'marketing']));

        $resposta->assertOk();
        $resposta->assertSee('Avanço do funil');
    }

    public function test_sem_o_recurso_a_tela_recusa(): void
    {
        $this->actingAs(User::factory()->semPerfil()->create());

        $this->get(route('relatorios.index'))->assertForbidden();
    }

    /**
     * Mesmo com perfil admin, escopo de revenda tranca a tela: os números são
     * da casa inteira — a mesma régua de `bloquearVisaoDaMatriz()` nos painéis.
     */
    public function test_usuario_de_revenda_nao_entra(): void
    {
        $revenda = Revenda::create(['nome' => 'Alpha Rev', 'ativo' => true]);
        $this->actingAs(User::factory()->create(['revenda_id' => $revenda->id]));

        $this->get(route('relatorios.index'))->assertForbidden();
    }

    /**
     * O menu só oferece porta que abre: quem lê `relatorios` vê o item; o
     * perfil membro (só tarefas) não vê. As duas pontas na MESMA tela — a de
     * manutenção, que qualquer perfil da matriz abre — para a diferença ser
     * só o menu.
     */
    public function test_o_menu_oferece_a_porta_so_para_quem_le_relatorios(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('manutencao.index'))->assertSee('Relatórios');

        $this->actingAs(User::factory()->membro()->create());
        $this->get(route('manutencao.index'))->assertDontSee('Relatórios');
    }

    public function test_a_competencia_navegada_da_o_contexto_e_a_malformada_cai_no_mes_corrente(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('relatorios.index', ['competencia' => '2026-05']))
            ->assertSee('competência 05/2026');

        $this->get(route('relatorios.index', ['competencia' => 'banana']))
            ->assertSee('competência '.now()->format('m/Y'));
    }

    public function test_a_secao_comercial_conta_o_que_a_competencia_moveu(): void
    {
        Lead::create([
            'nome' => 'Em proposta', 'estagio' => 'proposta', 'tipo_interesse' => 'saas',
            'valor_estimado' => 1000.00, 'estagio_atualizado_em' => now(),
        ]);
        $vendedora = User::factory()->create(['name' => 'Vera Vendedora']);
        Lead::create([
            'nome' => 'Fechado no mês', 'estagio' => 'cliente_ativo', 'tipo_interesse' => 'saas',
            'valor_estimado' => 500.00, 'estagio_atualizado_em' => now(),
            'vendedor_id' => $vendedora->id,
        ]);
        Lead::create([
            'nome' => 'Perdido no mês', 'estagio' => 'perdido', 'tipo_interesse' => 'saas',
            'valor_estimado' => 300.00, 'estagio_atualizado_em' => now(),
            'motivo_perda' => 'preco',
        ]);

        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('relatorios.index', ['secao' => 'comercial']));

        $resposta->assertOk();
        // O pipeline soma só o que está em aberto; fechado e perdido ficam de fora.
        $resposta->assertSee('R$ 1.000,00 em pipeline');
        // O fechado da competência aparece com o vendedor e com o motivo da perda rotulado.
        $resposta->assertSee('Vera Vendedora');
        $resposta->assertSee('Preço');
    }

    /**
     * Concluída se conta pelo EVENTO de chegada em `concluida` — e o ciclo é
     * da largada (`iniciada_em`) até esse evento.
     */
    public function test_a_secao_desenvolvimento_conta_conclusoes_pelo_evento(): void
    {
        $concluida = Tarefa::factory()->create([
            'status' => 'concluida',
            'iniciada_em' => now()->subDays(10),
        ]);
        TarefaEvento::create([
            'tarefa_id' => $concluida->id,
            'de_status' => 'pronta_producao', 'para_status' => 'concluida',
            'entrou_em' => now(),
        ]);
        Tarefa::factory()->create(['status' => 'em_desenvolvimento']);

        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('relatorios.index', ['secao' => 'desenvolvimento']));

        $resposta->assertOk();
        $resposta->assertSee('ciclo médio de 10 dia(s)');
        // O quadro lista as seis etapas em curso mesmo vazias — etapa ausente
        // se leria como etapa que não existe.
        foreach (['Aberta', 'Backlog', 'Em andamento', 'Em revisão', 'Em staging', 'Pronta p/ produção'] as $rotulo) {
            $resposta->assertSee($rotulo);
        }
    }

    public function test_a_secao_financeiro_soma_o_em_aberto_acumulado(): void
    {
        // Vencida ontem: entra no total E no vencido.
        Cobranca::create([
            'descricao' => 'Mensalidade atrasada', 'valor' => 100.00,
            'data_vencimento' => now()->subDay()->toDateString(),
            'status' => 'pendente', 'tipo' => 'direta',
        ]);
        ContaPagar::create([
            'descricao' => 'Aluguel', 'valor' => 3200.00,
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'status' => 'em_aberto',
        ]);

        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('relatorios.index', ['secao' => 'financeiro']));

        $resposta->assertOk();
        $resposta->assertSee('R$ 100,00');
        $resposta->assertSee('R$ 3.200,00');
    }

    /**
     * A régua é a de `Lead::temperatura()`: quente < 7 dias parados no
     * estágio, frio de 15 em diante — e a mesa de maiores negócios mostra os
     * dois com a idade de cada um.
     */
    public function test_a_temperatura_dos_leads_em_aberto_aparece_na_secao_comercial(): void
    {
        Lead::create([
            'nome' => 'Negócio fresco', 'estagio' => 'proposta', 'tipo_interesse' => 'saas',
            'valor_estimado' => 1000.00, 'estagio_atualizado_em' => now(),
        ]);
        Lead::create([
            'nome' => 'Negócio esquecido', 'estagio' => 'proposta', 'tipo_interesse' => 'saas',
            'valor_estimado' => 2000.00, 'estagio_atualizado_em' => now()->subDays(20),
        ]);

        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('relatorios.index', ['secao' => 'comercial']));

        $resposta->assertOk();
        $temperaturas = collect($resposta->viewData('temperaturas'))->keyBy('chave');
        $this->assertSame(1, $temperaturas['quente']['quantidade']);
        $this->assertSame(1, $temperaturas['frio']['quantidade']);
        $resposta->assertSee('Negócio esquecido');
    }

    /** As mesmas quatro faixas da tela de Receitas, repartindo o mesmo em-aberto. */
    public function test_o_aging_do_a_receber_reparte_pelas_faixas_da_tela_de_receitas(): void
    {
        Cobranca::create([
            'descricao' => 'Atraso médio', 'valor' => 150.00,
            'data_vencimento' => now()->subDays(20)->toDateString(),
            'status' => 'pendente', 'tipo' => 'direta',
        ]);

        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('relatorios.index', ['secao' => 'financeiro']));

        $resposta->assertOk();
        $faixas = $resposta->viewData('faixasAReceber');
        $this->assertEqualsWithDelta(150.0, $faixas['16_30']['valor'], 0.01);
        $resposta->assertSee('Atraso médio');
    }

    /** Reprovação em portão devolvendo à bancada é retrabalho — e o mês conta. */
    public function test_a_devolucao_de_portao_conta_como_retrabalho_da_competencia(): void
    {
        $tarefa = Tarefa::factory()->create(['status' => 'em_desenvolvimento']);
        TarefaEvento::create([
            'tarefa_id' => $tarefa->id,
            'de_status' => 'em_revisao', 'para_status' => 'em_desenvolvimento',
            'entrou_em' => now(),
        ]);
        // A estadia fechada na revisão dá a permanência média da etapa.
        TarefaEvento::create([
            'tarefa_id' => $tarefa->id,
            'de_status' => 'em_desenvolvimento', 'para_status' => 'em_revisao',
            'entrou_em' => now()->subDays(2), 'saiu_em' => now(),
            'duracao_segundos' => 2 * 86400,
        ]);

        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('relatorios.index', ['secao' => 'desenvolvimento']));

        $resposta->assertOk();
        $this->assertSame(1, $resposta->viewData('devolvidasQtd'));
        $permanencia = collect($resposta->viewData('tempoPorEtapa'))->firstWhere('status', 'em_revisao');
        $this->assertEqualsWithDelta(2.0, $permanencia['dias'], 0.01);
    }

    /**
     * O agrupamento por UF acontece na coluna crua + PHP, não numa expressão
     * SQL: o ONLY_FULL_GROUP_BY do MySQL do staging derrubava a seção inteira
     * (erro 1055) com o GROUP BY por expressão. NULL e vazio são o MESMO
     * "Sem UF" — dois buckets seria o ranking mentindo sobre o buraco.
     */
    public function test_clientes_sem_uf_viram_um_bucket_so_no_ranking_de_ufs(): void
    {
        Cliente::create(['nome' => 'Sem UF nula', 'ativo' => true, 'uf' => null]);
        Cliente::create(['nome' => 'Sem UF vazia', 'ativo' => true, 'uf' => '']);
        Cliente::create(['nome' => 'Mineiro', 'ativo' => true, 'uf' => 'MG']);

        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('relatorios.index', ['secao' => 'sistema']));

        $resposta->assertOk();
        $ufs = collect($resposta->viewData('rankingUfs')['itens'])->pluck('valor', 'nome');
        $this->assertEquals(2.0, $ufs['Sem UF']);
        $this->assertEquals(1.0, $ufs['MG']);
        $this->assertCount(2, $ufs);
    }

    public function test_a_secao_sistema_lista_os_perfis_com_contas_ativas(): void
    {
        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('relatorios.index', ['secao' => 'sistema']));

        $resposta->assertOk();
        // O admin criado pela factory é um vínculo do perfil Administrador.
        $resposta->assertSee('Administrador');
    }
}
