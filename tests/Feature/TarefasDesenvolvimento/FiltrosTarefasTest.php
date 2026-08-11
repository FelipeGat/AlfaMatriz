<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Http\Controllers\Controller;
use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Busca e filtros das duas abas de Tarefas.
 *
 * O quadro cresce sem teto — nada sai dele até encerrar — e o histórico nunca
 * perde uma linha. Sem recorte, achar uma tarefa depende de varrer coluna por
 * coluna ou página por página. O mesmo formulário serve as duas abas, e o
 * recorte vive na query string para ser compartilhável por link.
 */
class FiltrosTarefasTest extends TestCase
{
    use RefreshDatabase;

    /** Tarefas do quadro cobrindo os campos que a busca varre. */
    private function semearQuadro(User $criador, ?Sistema $sistema = null): void
    {
        Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'sistema_id' => $sistema?->id,
            'titulo' => 'Corrigir boleto duplicado',
            'resumo' => 'Cliente relatou cobranca em dobro',
            'detalhes' => 'Chamado 4711',
            'status' => 'em_desenvolvimento',
        ]);

        Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'titulo' => 'Ajustar relatorio de vendas',
            'resumo' => 'Totais por praca',
            'detalhes' => 'Sem relacao com cobranca',
            'status' => 'backlog',
        ]);
    }

    public function test_busca_do_quadro_encontra_pelo_titulo(): void
    {
        $usuario = User::factory()->create();
        $this->semearQuadro(User::factory()->create());

        $resposta = $this->actingAs($usuario)
            ->get(route('tarefas.index', ['busca' => 'boleto']))
            ->assertOk();

        $titulos = $resposta->viewData('tarefas')->pluck('titulo')->all();

        $this->assertSame(['Corrigir boleto duplicado'], $titulos);
    }

    public function test_busca_do_quadro_varre_resumo_e_detalhes(): void
    {
        $usuario = User::factory()->create();
        $this->semearQuadro(User::factory()->create());

        // Quem procura pelo número do chamado escreveu isso nos detalhes, não
        // no título — a busca só serve se alcançar o corpo da tarefa.
        $porDetalhes = $this->actingAs($usuario)
            ->get(route('tarefas.index', ['busca' => '4711']))
            ->assertOk();

        $this->assertSame(
            ['Corrigir boleto duplicado'],
            $porDetalhes->viewData('tarefas')->pluck('titulo')->all()
        );

        $porResumo = $this->actingAs($usuario)
            ->get(route('tarefas.index', ['busca' => 'praca']))
            ->assertOk();

        $this->assertSame(
            ['Ajustar relatorio de vendas'],
            $porResumo->viewData('tarefas')->pluck('titulo')->all()
        );
    }

    public function test_busca_do_quadro_varre_os_comentarios(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();
        $this->semearQuadro($criador);

        // Depois de a tarefa nascer, o assunto continua nos comentários: o
        // segundo chamado do mesmo cliente não está no título nem nos
        // detalhes, e sem isto a busca devolveria tela vazia para quem digita
        // um número que está escrito na tarefa.
        Tarefa::where('titulo', 'Ajustar relatorio de vendas')->first()->comentarios()->create([
            'autor_id' => $criador->id,
            'corpo' => 'Cliente reabriu no chamado 5090.',
        ]);

        $resposta = $this->actingAs($usuario)
            ->get(route('tarefas.index', ['busca' => '5090']))
            ->assertOk();

        $this->assertSame(
            ['Ajustar relatorio de vendas'],
            $resposta->viewData('tarefas')->pluck('titulo')->all()
        );
    }

    public function test_busca_do_historico_varre_os_comentarios(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        $encerrada = Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'titulo' => 'Integracao de estoque',
            'resumo' => 'Sem relacao com o termo procurado',
            'detalhes' => 'Sem relacao com o termo procurado',
            'status' => 'cancelada',
        ]);
        Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'titulo' => 'Outra encerrada',
            'resumo' => 'Sem relacao com o termo procurado',
            'detalhes' => 'Sem relacao com o termo procurado',
            'status' => 'concluida',
        ]);

        $encerrada->comentarios()->create([
            'autor_id' => $criador->id,
            'corpo' => 'Cancelada porque o cliente desistiu do modulo de estoque.',
        ]);

        $resposta = $this->actingAs($usuario)
            ->get(route('tarefas.historico', ['busca' => 'desistiu']))
            ->assertOk();

        $this->assertSame(
            ['Integracao de estoque'],
            $resposta->viewData('tarefas')->pluck('titulo')->all()
        );
    }

    public function test_busca_do_quadro_nao_traz_tarefa_encerrada(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'titulo' => 'Boleto em curso', 'status' => 'em_testes',
        ]);
        Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'titulo' => 'Boleto concluido', 'status' => 'concluida',
        ]);

        $resposta = $this->actingAs($usuario)
            ->get(route('tarefas.index', ['busca' => 'Boleto']))
            ->assertOk();

        // O `orWhere` da busca solto escaparia do recorte de status e traria a
        // concluída de volta ao quadro.
        $this->assertSame(
            ['Boleto em curso'],
            $resposta->viewData('tarefas')->pluck('titulo')->all()
        );
    }

    public function test_contagem_da_coluna_segue_o_recorte(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'titulo' => 'Boleto', 'status' => 'backlog',
        ]);
        Tarefa::factory()->count(3)->create([
            'criado_por_id' => $criador->id, 'titulo' => 'Outra coisa', 'status' => 'backlog',
        ]);

        $resposta = $this->actingAs($usuario)
            ->get(route('tarefas.index', ['busca' => 'Boleto']))
            ->assertOk();

        $backlog = collect($resposta->viewData('etapas'))->firstWhere('chave', 'backlog');

        $this->assertSame(1, $backlog['quantidade'], 'O selo da coluna mede o que está na tela.');
        $this->assertSame(4, $resposta->viewData('totalNoQuadro'), 'O denominador é o quadro inteiro.');
    }

    public function test_filtro_por_sistema_responsavel_e_prioridade(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();
        $sistema = Sistema::factory()->create();
        $responsavel = User::factory()->create();

        $alvo = Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'sistema_id' => $sistema->id,
            'responsavel_id' => $responsavel->id,
            'prioridade' => 'critica',
            'status' => 'backlog',
        ]);

        Tarefa::factory()->count(2)->create([
            'criado_por_id' => $criador->id, 'prioridade' => 'baixa', 'status' => 'backlog',
        ]);

        foreach ([
            ['sistema' => $sistema->id],
            ['responsavel' => $responsavel->id],
            ['prioridade' => 'critica'],
        ] as $filtro) {
            $resposta = $this->actingAs($usuario)->get(route('tarefas.index', $filtro))->assertOk();

            $this->assertSame(
                [$alvo->id],
                $resposta->viewData('tarefas')->pluck('id')->all(),
                'Filtro '.key($filtro).' não recortou a lista.'
            );
        }
    }

    public function test_filtro_acha_o_que_nao_tem_dono(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        $semDono = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'responsavel_id' => null, 'status' => 'aberta',
        ]);
        Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'responsavel_id' => User::factory(), 'status' => 'aberta',
        ]);

        // A coluna Aberta é a fila de triagem: achar o que ainda não tem
        // responsável é a pergunta que se faz ali.
        $resposta = $this->actingAs($usuario)
            ->get(route('tarefas.index', ['responsavel' => 'sem']))
            ->assertOk();

        $this->assertSame([$semDono->id], $resposta->viewData('tarefas')->pluck('id')->all());

        $semSistema = $this->actingAs($usuario)
            ->get(route('tarefas.index', ['sistema' => 'sem']))
            ->assertOk();

        $this->assertCount(2, $semSistema->viewData('tarefas'), 'Nenhuma das duas tem sistema.');
    }

    public function test_valor_invalido_na_url_nao_esvazia_a_tela(): void
    {
        $usuario = User::factory()->create();
        Tarefa::factory()->count(2)->create([
            'criado_por_id' => User::factory(), 'status' => 'backlog',
        ]);

        // Prioridade fora da lista branca vira "sem filtro": a URL é digitável
        // e um valor inventado não pode devolver uma tela vazia sem explicação.
        $resposta = $this->actingAs($usuario)
            ->get(route('tarefas.index', ['prioridade' => 'urgentissima']))
            ->assertOk();

        $this->assertCount(2, $resposta->viewData('tarefas'));
        $this->assertSame('', $resposta->viewData('filtros')['prioridade']);
    }

    public function test_filtro_em_array_na_url_nao_quebra_a_tela(): void
    {
        $usuario = User::factory()->create();

        // `?sistema[]=1` chega como array; o cast direto para string seria um
        // erro fatal numa URL que qualquer um digita.
        $this->actingAs($usuario)
            ->get(route('tarefas.index').'?sistema[]=1&busca[]=x')
            ->assertOk();
    }

    public function test_desfecho_so_aparece_no_historico(): void
    {
        $usuario = User::factory()->create();

        // O quadro não tem status terminal para escolher: o campo ali seria um
        // filtro que zera a tela em qualquer opção.
        $this->actingAs($usuario)->get(route('tarefas.index'))
            ->assertOk()
            ->assertDontSee('name="desfecho"', escape: false);

        $this->actingAs($usuario)->get(route('tarefas.historico'))
            ->assertOk()
            ->assertSee('name="desfecho"', escape: false);
    }

    public function test_historico_filtra_por_desfecho(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        $concluida = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'concluida',
        ]);
        Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'cancelada',
        ]);

        $resposta = $this->actingAs($usuario)
            ->get(route('tarefas.historico', ['desfecho' => 'concluida']))
            ->assertOk();

        $this->assertSame([$concluida->id], $resposta->viewData('tarefas')->pluck('id')->all());
        $this->assertSame(2, $resposta->viewData('totalNoHistorico'));
    }

    public function test_historico_busca_e_nunca_mostra_tarefa_em_curso(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        $encerrada = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'titulo' => 'Boleto encerrado', 'status' => 'cancelada',
        ]);
        Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'titulo' => 'Boleto em curso', 'status' => 'em_testes',
        ]);

        $resposta = $this->actingAs($usuario)
            ->get(route('tarefas.historico', ['busca' => 'Boleto']))
            ->assertOk();

        $this->assertSame([$encerrada->id], $resposta->viewData('tarefas')->pluck('id')->all());
    }

    public function test_paginacao_do_historico_preserva_o_recorte(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        // Mais de uma página dentro do próprio recorte: sem `withQueryString`,
        // o link da página 2 volta a ser o histórico inteiro.
        Tarefa::factory()->count(self::porPagina() + 1)->create([
            'criado_por_id' => $criador->id, 'titulo' => 'Boleto duplicado', 'status' => 'concluida',
        ]);
        Tarefa::factory()->count(3)->create([
            'criado_por_id' => $criador->id, 'titulo' => 'Outro assunto', 'status' => 'cancelada',
        ]);

        $resposta = $this->actingAs($usuario)
            ->get(route('tarefas.historico', ['busca' => 'Boleto']))
            ->assertOk();

        $this->assertSame(self::porPagina() + 1, $resposta->viewData('tarefas')->total());
        $resposta->assertSee('busca=Boleto', escape: false);
    }

    public function test_mover_card_nao_derruba_o_recorte(): void
    {
        $usuario = User::factory()->create();
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => User::factory(), 'titulo' => 'Boleto', 'status' => 'backlog',
        ]);

        $quadroFiltrado = route('tarefas.index', ['busca' => 'Boleto']);

        // Arrastar um card devolvia o quadro cru, e o recorte se perdia a cada
        // movimento — o filtro tinha de ser refeito para cada card.
        $this->actingAs($usuario)
            ->from($quadroFiltrado)
            ->post(route('tarefas.mover', $tarefa), ['status' => 'em_desenvolvimento'])
            ->assertRedirect($quadroFiltrado);
    }

    private static function porPagina(): int
    {
        return Controller::POR_PAGINA;
    }
}
