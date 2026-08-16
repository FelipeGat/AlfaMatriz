<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Http\Controllers\Controller;
use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\TarefaEvento;
use App\Models\TarefaRelatorioTeste;
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
            'criado_por_id' => $criador->id, 'titulo' => 'Boleto em curso', 'status' => 'em_revisao',
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
            'criado_por_id' => $criador->id, 'titulo' => 'Boleto em curso', 'status' => 'em_revisao',
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

    public function test_busca_encontra_a_tarefa_pelo_numero_do_card(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();
        $this->semearQuadro($criador);

        // Sem dígito nenhum no corpo: o que se está medindo é o número do
        // card, e texto sorteado pela fábrica poderia responder por ele.
        $alvo = Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'titulo' => 'Fila de importação travando',
            'resumo' => 'Sem numeral no corpo',
            'detalhes' => 'Sem numeral no corpo',
            'status' => 'backlog',
        ]);

        // Com e sem o "#" que a tela imprime: quem copia do card leva o
        // símbolo junto, e quem ouviu o número no telefone digita só o número.
        foreach ([(string) $alvo->id, '#'.$alvo->id] as $digitado) {
            $resposta = $this->actingAs($usuario)
                ->get(route('tarefas.index', ['busca' => $digitado]))
                ->assertOk();

            $this->assertSame([$alvo->id], $resposta->viewData('tarefas')->pluck('id')->all(),
                "Buscar por \"{$digitado}\" precisa achar a tarefa {$alvo->id}.");
        }
    }

    public function test_busca_por_numero_continua_varrendo_o_texto(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        $alvo = Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'titulo' => 'Fila de importação travando',
            'resumo' => 'Sem numeral no corpo',
            'detalhes' => 'Sem numeral no corpo',
            'status' => 'backlog',
        ]);

        // O número da tarefa escrito no CORPO de outra: é o caso que denuncia
        // o grupo de OU mal montado — com o `orWhere` do id na frente, o `and`
        // do título gruda nele e a busca por texto passa a valer só dentro da
        // tarefa daquele número.
        $porTexto = Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'titulo' => 'Revisar chamado',
            'detalhes' => 'Depende da tarefa '.$alvo->id,
            'status' => 'backlog',
        ]);

        $encontradas = $this->actingAs($usuario)
            ->get(route('tarefas.index', ['busca' => (string) $alvo->id]))
            ->assertOk()
            ->viewData('tarefas')->pluck('id')->all();

        sort($encontradas);
        $esperadas = [$alvo->id, $porTexto->id];
        sort($esperadas);

        $this->assertSame($esperadas, $encontradas);
    }

    /**
     * Uma tarefa encerrada com texto neutro em todos os campos que a busca já
     * varria — o que os testes de alcance novo medem é o campo satélite, e
     * qualquer termo no corpo responderia por ele.
     */
    private function encerradaNeutra(User $criador, array $sobrescreve = []): Tarefa
    {
        return Tarefa::factory()->create($sobrescreve + [
            'criado_por_id' => $criador->id,
            'titulo' => 'Encerrada sem o termo procurado',
            'resumo' => 'Sem relacao com o termo procurado',
            'detalhes' => 'Sem relacao com o termo procurado',
            'status' => 'concluida',
        ]);
    }

    /**
     * @spec:AC-343 A busca do histórico acha a tarefa pelo item do checklist —
     * o combinado costuma estar escrito lá, não no título.
     */
    public function test_busca_do_historico_varre_o_checklist(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        $alvo = $this->encerradaNeutra($criador, ['titulo' => 'Com checklist']);
        $this->encerradaNeutra($criador, ['titulo' => 'Sem checklist']);

        $alvo->itens()->create(['texto' => 'Conferir o chamado QX-77341']);

        $resposta = $this->actingAs($usuario)
            ->get(route('tarefas.historico', ['busca' => 'QX-77341']))
            ->assertOk();

        $this->assertSame(['Com checklist'], $resposta->viewData('tarefas')->pluck('titulo')->all());
    }

    /**
     * @spec:AC-344 A busca do histórico acha a tarefa pelo motivo registrado
     * na linha do tempo — a devolução explica a tarefa melhor que o título.
     */
    public function test_busca_do_historico_varre_o_motivo_da_linha_do_tempo(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        $alvo = $this->encerradaNeutra($criador, ['titulo' => 'Com devolucao']);
        $this->encerradaNeutra($criador, ['titulo' => 'Sem devolucao']);

        TarefaEvento::create([
            'tarefa_id' => $alvo->id,
            'de_status' => 'em_revisao',
            'para_status' => 'em_desenvolvimento',
            'motivo' => 'Voltou porque o calculo do rateio saiu errado',
            'entrou_em' => '2026-08-10 10:00',
        ]);

        $resposta = $this->actingAs($usuario)
            ->get(route('tarefas.historico', ['busca' => 'rateio']))
            ->assertOk();

        $this->assertSame(['Com devolucao'], $resposta->viewData('tarefas')->pluck('titulo')->all());
    }

    /**
     * @spec:AC-345 A busca acha a tarefa pelo motivo de bloqueio ou de
     * retorno — as marcas vivem na própria tarefa, não na linha do tempo.
     */
    public function test_busca_varre_os_motivos_de_bloqueio_e_de_retorno(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'titulo' => 'Travada esperando terceiro',
            'resumo' => 'Sem relacao com o termo procurado',
            'detalhes' => 'Sem relacao com o termo procurado',
            'status' => 'em_desenvolvimento',
            'bloqueado_em' => now(),
            'bloqueio_motivo' => 'Esperando a homologacao do gateway',
        ]);
        Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'titulo' => 'Devolvida para ajuste',
            'resumo' => 'Sem relacao com o termo procurado',
            'detalhes' => 'Sem relacao com o termo procurado',
            'status' => 'em_desenvolvimento',
            'retorno_de' => 'em_revisao',
            'retorno_motivo' => 'Faltou tratar o arredondamento do desconto',
        ]);

        foreach (['homologacao do gateway' => 'Travada esperando terceiro',
            'arredondamento' => 'Devolvida para ajuste'] as $termo => $titulo) {
            $resposta = $this->actingAs($usuario)
                ->get(route('tarefas.index', ['busca' => $termo]))
                ->assertOk();

            $this->assertSame([$titulo], $resposta->viewData('tarefas')->pluck('titulo')->all(),
                "Buscar por \"{$termo}\" precisa achar \"{$titulo}\".");
        }
    }

    /**
     * @spec:AC-346 A busca do histórico acha a tarefa pelas notas do relatório
     * de teste — é onde ficou escrito o que quebrou e o que se conferiu.
     */
    public function test_busca_do_historico_varre_as_notas_do_relatorio_de_teste(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        $alvo = $this->encerradaNeutra($criador, ['titulo' => 'Com relatorio']);
        $this->encerradaNeutra($criador, ['titulo' => 'Sem relatorio']);

        TarefaRelatorioTeste::create([
            'tarefa_id' => $alvo->id,
            'aprovado' => false,
            'notas' => 'Falhou no cenario de CPF duplicado',
        ]);

        $resposta = $this->actingAs($usuario)
            ->get(route('tarefas.historico', ['busca' => 'CPF duplicado']))
            ->assertOk();

        $this->assertSame(['Com relatorio'], $resposta->viewData('tarefas')->pluck('titulo')->all());
    }

    /**
     * @spec:AC-347 A busca do histórico acha a tarefa pelo nome do anexo —
     * "aquela do contrato" é o nome do arquivo, não o título da tarefa.
     */
    public function test_busca_do_historico_varre_o_nome_do_anexo(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        $alvo = $this->encerradaNeutra($criador, ['titulo' => 'Com anexo']);
        $this->encerradaNeutra($criador, ['titulo' => 'Sem anexo']);

        $alvo->anexos()->create([
            'autor_id' => $criador->id,
            'nome_original' => 'contrato-renovacao.pdf',
            'nome_arquivo' => 'a1b2c3.pdf',
            'mime' => 'application/pdf',
            'caminho' => 'tarefas/a1b2c3.pdf',
            'tamanho' => 1234,
        ]);

        $resposta = $this->actingAs($usuario)
            ->get(route('tarefas.historico', ['busca' => 'contrato-renovacao']))
            ->assertOk();

        $this->assertSame(['Com anexo'], $resposta->viewData('tarefas')->pluck('titulo')->all());
    }

    /**
     * @spec:AC-348 A busca do histórico acha a tarefa pela versão de produção
     * — "o que saiu na 9.4.2?" é uma pergunta que o acervo precisa responder.
     */
    public function test_busca_do_historico_varre_a_versao_de_producao(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        $this->encerradaNeutra($criador, ['titulo' => 'Da versao procurada', 'versao_producao' => 'v9.4.2']);
        $this->encerradaNeutra($criador, ['titulo' => 'De outra versao', 'versao_producao' => 'v9.4.1']);

        $resposta = $this->actingAs($usuario)
            ->get(route('tarefas.historico', ['busca' => '9.4.2']))
            ->assertOk();

        $this->assertSame(['Da versao procurada'], $resposta->viewData('tarefas')->pluck('titulo')->all());
    }

    /**
     * @spec:AC-349 O alcance novo não vaza tarefa encerrada para o quadro: as
     * condições novas moram no mesmo grupo aninhado da busca — soltas, o
     * `orWhere` escaparia do recorte de status.
     */
    public function test_alcance_novo_nao_traz_tarefa_encerrada_ao_quadro(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        $emCurso = Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'titulo' => 'Em curso com o termo',
            'resumo' => 'Sem relacao com o termo procurado',
            'detalhes' => 'Sem relacao com o termo procurado',
            'status' => 'em_desenvolvimento',
        ]);
        $encerrada = $this->encerradaNeutra($criador, ['titulo' => 'Encerrada com o termo']);

        foreach ([$emCurso, $encerrada] as $tarefa) {
            $tarefa->itens()->create(['texto' => 'Conferir o boleto-fantasma']);
        }

        $resposta = $this->actingAs($usuario)
            ->get(route('tarefas.index', ['busca' => 'boleto-fantasma']))
            ->assertOk();

        $this->assertSame(['Em curso com o termo'], $resposta->viewData('tarefas')->pluck('titulo')->all());
    }

    /**
     * @spec:AC-350 O campo de busca anuncia o alcance novo: "qualquer texto ou
     * número da tarefa", em vez de enumerar uma lista de campos incompleta.
     */
    public function test_campo_de_busca_anuncia_o_alcance_novo(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->get(route('tarefas.historico'))
            ->assertOk()
            ->assertSee('Buscar por qualquer texto ou número da tarefa…', false);
    }

    private static function porPagina(): int
    {
        return Controller::POR_PAGINA;
    }
}
