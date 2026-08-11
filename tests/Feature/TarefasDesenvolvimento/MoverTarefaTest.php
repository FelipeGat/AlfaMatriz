<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Tarefa;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Tests\TestCase;

/**
 * Mover o card pela rota `tarefas.mover` — arrastar ou o menu "Mover ▾": o
 * aviso de recusa do motor do fluxo chega como flash de erro e a tarefa não
 * sai do lugar; as transições que pedem texto (ajustes, cancelamento,
 * conclusão com relatório) recebem esse texto no próprio POST (T-064).
 */
class MoverTarefaTest extends TestCase
{
    use RefreshDatabase;

    private function criarTarefa(array $atributos = []): Tarefa
    {
        $criador = User::factory()->create();

        return Tarefa::create(array_merge([
            'titulo' => 'Tarefa de teste',
            'criado_por_id' => $criador->id,
        ], $atributos));
    }

    /**
     * @spec:AC-085 Movimento fora do fluxo é recusado: Backlog não vai direto para Concluída.
     */
    public function test_movimento_fora_do_fluxo_e_recusado(): void
    {
        $usuario = User::factory()->create();
        $responsavel = User::factory()->create();
        $tarefa = $this->criarTarefa(['responsavel_id' => $responsavel->id]);
        $this->assertSame('backlog', $tarefa->status);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'concluida',
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertStringContainsString('Transição inválida', session('erro'));
        $this->assertSame('backlog', $tarefa->fresh()->status);
    }

    /**
     * @spec:AC-086 Direcionar para o Backlog exige responsável.
     */
    public function test_direcionar_para_backlog_exige_responsavel(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();
        $this->assertSame('aberta', $tarefa->status);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'backlog',
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertStringContainsString('direcionar a tarefa para alguém', session('erro'));
        $this->assertSame('aberta', $tarefa->fresh()->status);

        $responsavel = User::factory()->create();
        $tarefa->update(['responsavel_id' => $responsavel->id]);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'backlog',
        ]);

        $resposta->assertSessionHasNoErrors();
        $resposta->assertSessionMissing('erro');
        $this->assertSame('backlog', $tarefa->fresh()->status);
    }

    /**
     * @spec:AC-087 Devolver para ajustes exige dizer o que corrigir.
     */
    public function test_devolver_para_ajustes_exige_motivo(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa(['status' => 'em_testes']);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'ajustes_necessarios',
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertStringContainsString('descrever o que precisa ser corrigido', session('erro'));
        $this->assertSame('em_testes', $tarefa->fresh()->status);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'ajustes_necessarios',
            'motivo' => 'Falhou no cenário de CPF duplicado.',
        ]);

        $resposta->assertSessionMissing('erro');
        $this->assertSame('ajustes_necessarios', $tarefa->fresh()->status);
    }

    /**
     * @spec:AC-088 Cancelar exige motivo.
     */
    public function test_cancelar_exige_motivo(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa(['status' => 'em_desenvolvimento']);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'cancelada',
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertStringContainsString('motivo do cancelamento', session('erro'));
        $this->assertSame('em_desenvolvimento', $tarefa->fresh()->status);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'cancelada',
            'motivo' => 'Escopo descontinuado.',
        ]);

        $resposta->assertSessionMissing('erro');
        $this->assertSame('cancelada', $tarefa->fresh()->status);
    }

    /**
     * @spec:AC-089 Concluir exige relatório de teste aprovado; registrar um aprovado no próprio movimento libera a conclusão.
     */
    public function test_concluir_exige_relatorio_de_teste_aprovado(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa(['status' => 'em_testes']);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'concluida',
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertStringContainsString('relatório de teste aprovado', session('erro'));
        $this->assertSame('em_testes', $tarefa->fresh()->status);

        // Registrar um relatório reprovado no próprio movimento continua recusando.
        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'concluida',
            'relatorio_notas' => 'Falhou no cenário de CPF duplicado.',
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertStringContainsString('relatório de teste aprovado', session('erro'));
        $this->assertSame('em_testes', $tarefa->fresh()->status);
        $this->assertDatabaseHas('tarefa_relatorios_teste', [
            'tarefa_id' => $tarefa->id,
            'aprovado' => false,
            'notas' => 'Falhou no cenário de CPF duplicado.',
        ]);

        // Registrar um relatório aprovado no mesmo movimento libera a conclusão.
        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'concluida',
            'relatorio_notas' => 'Tudo certo no reteste.',
            'relatorio_aprovado' => '1',
        ]);

        $resposta->assertSessionMissing('erro');
        $this->assertSame('concluida', $tarefa->fresh()->status);
        $this->assertDatabaseHas('tarefa_relatorios_teste', [
            'tarefa_id' => $tarefa->id,
            'aprovado' => true,
            'notas' => 'Tudo certo no reteste.',
        ]);
    }

    /**
     * @spec:AC-090 Tarefa concluída pode ser reaberta para desenvolvimento; cancelada não sai de lugar nenhum.
     */
    public function test_tarefa_concluida_pode_ser_reaberta_e_cancelada_nao_tem_saida(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa(['status' => 'concluida']);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_desenvolvimento',
        ]);

        $resposta->assertSessionMissing('erro');
        $this->assertSame('em_desenvolvimento', $tarefa->fresh()->status);

        $cancelada = $this->criarTarefa(['status' => 'cancelada']);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $cancelada), [
            'status' => 'em_desenvolvimento',
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertStringContainsString('Transição inválida', session('erro'));
        $this->assertSame('cancelada', $cancelada->fresh()->status);
    }

    /**
     * @spec:AC-122 O menu "Mover" do card oferece exatamente os destinos que o fluxo
     * permite — e o card em Cancelada, que não tem saída, não oferece menu nenhum.
     *
     * A causa raiz do bug original: `@json` dentro do atributo `x-data` fecha o
     * atributo HTML na primeira aspa dupla do JSON. O Alpine não avalia nada, o
     * `x-for` do select nunca roda e o menu abre VAZIO — sem erro, sem sintoma no
     * servidor, com a rota respondendo normalmente. Por isso a asserção é sobre o
     * atributo INTEIRO e íntegro (`Js::from`, que escapa as aspas como \u0022):
     * truncado, ele não bate.
     */
    public function test_menu_mover_oferece_so_os_destinos_permitidos(): void
    {
        $usuario = User::factory()->create();
        $this->criarTarefa(['titulo' => 'Tarefa em testes', 'status' => 'em_testes']);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // Em testes ganhou a volta seca para Em andamento, que antes só existia
        // declarando uma reprovação que não houve (AC-183). Bloquear saiu da
        // lista: travar deixou de ser etapa e virou ação própria (AC-190).
        $esperado = 'x-data="{ transicoesDoCard: '
            .Js::from(['concluida', 'ajustes_necessarios', 'em_desenvolvimento', 'cancelada']).' }"';

        $this->assertStringContainsString($esperado, $html,
            'O menu do card em Em testes precisa trazer os destinos permitidos, com o atributo x-data íntegro.');
    }

    /**
     * @spec:AC-187 Durante o arrasto, a coluna que não aceita o card apaga: o card
     * entrega os próprios destinos ao ser pego, e cada coluna se pergunta se está
     * neles. Antes o quadro deixava soltar em qualquer lugar e só depois respondia
     * "transição inválida" — o caminho parecia existir até o fim.
     */
    public function test_o_quadro_apaga_a_coluna_que_nao_aceita_o_card_arrastado(): void
    {
        $usuario = User::factory()->create();

        // Operacional em Em andamento: ela NUNCA passa por testes, e era
        // exatamente esse arrasto que terminava em "transição inválida".
        $this->criarTarefa([
            'titulo' => 'Falar com o fabricante', 'tipo' => 'operacional',
            'status' => 'em_desenvolvimento',
        ]);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // O card entrega ao quadro, ao ser pego: os próprios destinos, o tipo
        // (que decide se concluir pede relatório) e se já está travado.
        // O `preg_replace` tira a indentação do atributo multilinha — a
        // asserção é sobre o que o card informa, não sobre como o Blade quebrou
        // a linha.
        $numaLinha = preg_replace('/\s+/', ' ', $html);

        $this->assertStringContainsString(
            'pegar( '.Tarefa::first()->id.', '.Js::from(['concluida', 'backlog', 'cancelada'])->toHtml()
                .", 'operacional', false )",
            $numaLinha,
            'O card precisa levar destinos, tipo e situação de bloqueio para o quadro no dragstart.'
        );

        // E cada coluna do quadro se pergunta se aceita o que está na mão.
        foreach (['aberta', 'backlog', 'em_desenvolvimento', 'em_testes', 'ajustes_necessarios'] as $etapa) {
            $this->assertStringContainsString("aceita('".$etapa."')", $html,
                "A coluna {$etapa} precisa consultar se aceita o card arrastado.");
        }
    }

    /**
     * @spec:AC-188 Soltar numa etapa que pede texto abre o painel de motivo, que nomeia
     * a ação e diz por que o texto está sendo pedido. Antes o arrasto morria em
     * silêncio — não movia e não dizia nada, o que se lê como sistema quebrado.
     */
    public function test_soltar_onde_pede_texto_abre_o_painel_de_motivo(): void
    {
        $usuario = User::factory()->create();
        $this->criarTarefa(['status' => 'em_testes']);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // As etapas e faixas que pedem texto se declaram no solto...
        $this->assertStringContainsString("soltar('ajustes_necessarios', true)", $html);
        $this->assertStringContainsString("soltar('bloqueio', true)", $html);

        // ...e o quadro abre o painel em vez de engolir o gesto.
        $this->assertStringContainsString('this.abrirPendente(tarefa, status)', $html);

        // O painel nomeia a ação e o resultado — "Confirmar" é o que se aperta
        // sem ler —, e diz por que está pedindo o texto.
        $this->assertStringContainsString('Devolvendo para ajustes', $html);
        $this->assertStringContainsString('Devolver para ajustes', $html);
        $this->assertStringContainsString('Bloquear tarefa', $html);
        $this->assertStringContainsString('Quem for corrigir precisa saber o que falhou', $html);

        // A coluna de destino segue realçada enquanto o motivo não vem.
        $this->assertStringContainsString("pendente?.destino === 'ajustes_necessarios'", $html);
    }

    /**
     * @spec:AC-189 A faixa "Concluir" recebe o card arrastado e pede a confirmação:
     * Concluída não tem coluna (AC-096) e isso está certo, mas o preço era a ação mais
     * importante do fluxo ser a única sem gesto, escondida dentro de um dropdown.
     */
    public function test_a_faixa_de_concluir_recebe_o_card_e_pede_confirmacao(): void
    {
        $usuario = User::factory()->create();
        $this->criarTarefa(['status' => 'em_testes']);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // Recebe o solto, e sempre confirmando: encerrar tira o card da vista,
        // e um arrasto torto não deveria ser capaz disso sozinho.
        $this->assertStringContainsString("soltar('concluida', true)", $html);
        $this->assertStringContainsString("aceita('concluida')", $html);

        // E continua sem ser coluna: nenhuma etapa terminal entra no quadro.
        $etapas = $this->actingAs($usuario)->get(route('tarefas.index'))->viewData('etapas');
        $this->assertNotContains('concluida', array_column($etapas, 'chave'));
    }

    /**
     * @spec:AC-191 A tarja do card diz há quanto tempo a tarefa está travada e por quê,
     * com o destravar ao lado. O motivo ocupa a largura inteira e quebra em duas linhas:
     * truncado, o "porquê" só existiria no tooltip — e ele viajar junto da etapa era o
     * argumento inteiro de tirar o bloqueio da coluna.
     */
    public function test_a_tarja_do_card_carrega_o_motivo_e_o_destravar(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa(['status' => 'em_testes']);

        app(FluxoTarefaService::class)
            ->bloquear($tarefa, 'Revenda não respondeu o e-mail de confirmação.');

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // O card continua na coluna Em testes, com a tarja dentro dele.
        $colunas = $this->actingAs($usuario)->get(route('tarefas.index'))->viewData('colunas');
        $this->assertContains($tarefa->id, $colunas['em_testes']->pluck('id')->all());

        $this->assertStringContainsString('Bloqueada agora', $html);
        $this->assertStringContainsString('Revenda não respondeu o e-mail de confirmação.', $html);

        // O destravar mora na tarja: quem lê o motivo é quem acabou de
        // descobrir que ele não vale mais.
        $this->assertStringContainsString(route('tarefas.bloquear', $tarefa), $html);
        $this->assertStringContainsString('Destravar tarefa', $html);
    }

    /**
     * @spec:AC-192 A faixa Bloquear recebe o card arrastado e conta as travadas do
     * recorte. Bloquear é o que sobrou da coluna: a tarefa não sai da etapa, então não
     * há para onde arrastá-la — mas o gesto continua existindo e precisa de destino.
     */
    public function test_a_faixa_de_bloquear_recebe_o_card_e_conta_as_travadas(): void
    {
        $usuario = User::factory()->create();
        $fluxo = app(FluxoTarefaService::class);

        $fluxo->bloquear($this->criarTarefa(['status' => 'em_testes']), 'Esperando o cliente.');
        $fluxo->bloquear($this->criarTarefa(['status' => 'em_desenvolvimento']), 'Falta acesso ao servidor.');
        $this->criarTarefa(['status' => 'backlog', 'responsavel_id' => User::factory()->create()->id]);

        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk();

        $this->assertSame(2, $resposta->viewData('totalBloqueadas'),
            'O contador da faixa mede as travadas do recorte, como os das colunas.');

        $html = $resposta->getContent();
        $this->assertStringContainsString("soltar('bloqueio', true)", $html);
        $this->assertStringContainsString("aceita('bloqueio')", $html);
    }

    /**
     * @spec:AC-122 Tarefa cancelada não aparece no quadro, então não há card nem menu
     * para ela ali — o caminho de volta dela mora no histórico (AC-184).
     */
    public function test_card_cancelado_nao_oferece_menu_mover(): void
    {
        $usuario = User::factory()->create();
        $this->criarTarefa(['titulo' => 'Tarefa cancelada', 'status' => 'cancelada']);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // Só `transicoesDoCard`: o texto "Mover ▾" também aparece na ajuda fixa
        // do cabeçalho do quadro (index.blade.php), então procurá-lo aqui
        // acusaria falha sem que houvesse menu nenhum no card.
        $this->assertStringNotContainsString('transicoesDoCard', $html);
    }
}
