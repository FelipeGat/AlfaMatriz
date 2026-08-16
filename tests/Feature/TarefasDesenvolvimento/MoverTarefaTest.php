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
     * @spec:AC-085 Movimento fora do fluxo é recusado: Backlog não vai direto para
     * Concluída. O mapa manda em quem executa — por isso o ator é um membro com a
     * tarefa em mãos; o movimento livre de quem triaga é o AC-281
     * (`MovimentoLivreTest`).
     */
    public function test_movimento_fora_do_fluxo_e_recusado(): void
    {
        $usuario = User::factory()->membro()->create();
        $tarefa = $this->criarTarefa(['responsavel_id' => $usuario->id]);
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
     * @spec:AC-087 Devolver de um portão para a bancada exige dizer o que corrigir.
     */
    public function test_devolver_para_a_bancada_exige_motivo(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa(['status' => 'em_revisao']);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_desenvolvimento',
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertStringContainsString('o que precisa ser corrigido', session('erro'));
        $this->assertSame('em_revisao', $tarefa->fresh()->status);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_desenvolvimento',
            'motivo' => 'Falhou no cenário de CPF duplicado.',
        ]);

        $resposta->assertSessionMissing('erro');
        $this->assertSame('em_desenvolvimento', $tarefa->fresh()->status);
        $this->assertSame('em_revisao', $tarefa->fresh()->retorno_de);
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
     * @spec:AC-089 Liberar para a produção exige a validação do staging; carimbá-la no
     * próprio movimento libera a passagem.
     */
    public function test_liberar_para_producao_exige_validacao_do_staging(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa(['status' => 'em_staging']);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'pronta_producao',
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertStringContainsString('validar o staging', session('erro'));
        $this->assertSame('em_staging', $tarefa->fresh()->status);

        // Desmarcar o "Validado no staging" no próprio movimento continua recusando.
        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'pronta_producao',
            'relatorio_aprovado' => '0',
            'relatorio_notas' => 'Falhou no cenário de CPF duplicado.',
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertStringContainsString('validar o staging', session('erro'));
        $this->assertSame('em_staging', $tarefa->fresh()->status);
        $this->assertDatabaseHas('tarefa_relatorios_teste', [
            'tarefa_id' => $tarefa->id,
            'aprovado' => false,
            'notas' => 'Falhou no cenário de CPF duplicado.',
        ]);

        // O carimbo é o que importa; a nota é opcional.
        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'pronta_producao',
            'relatorio_aprovado' => '1',
        ]);

        $resposta->assertSessionMissing('erro');
        $this->assertSame('pronta_producao', $tarefa->fresh()->status);
        $this->assertDatabaseHas('tarefa_relatorios_teste', [
            'tarefa_id' => $tarefa->id,
            'aprovado' => true,
        ]);
    }

    /**
     * @spec:AC-187 Concluir pede a versão que subiu — é ela que responde "desde
     * quando o cliente tem isso".
     */
    public function test_concluir_pede_a_versao_que_subiu(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa(['status' => 'pronta_producao']);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'concluida',
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertStringContainsString('versão que subiu', session('erro'));
        $this->assertSame('pronta_producao', $tarefa->fresh()->status);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'concluida',
            'versao_producao' => 'v1.4.2',
        ]);

        $resposta->assertSessionMissing('erro');
        $this->assertSame('concluida', $tarefa->fresh()->status);
        $this->assertSame('v1.4.2', $tarefa->fresh()->versao_producao);
    }

    /**
     * @spec:AC-090 Tarefa concluída pode ser reaberta para desenvolvimento; cancelada
     * não sai de lugar nenhum — para quem executa. Quem triaga tira a cancelada de
     * onde quiser (AC-281), então quem fixa esta recusa é um membro dono da tarefa.
     */
    public function test_tarefa_concluida_pode_ser_reaberta_e_cancelada_nao_tem_saida(): void
    {
        $usuario = User::factory()->membro()->create();
        $tarefa = $this->criarTarefa(['status' => 'concluida', 'responsavel_id' => $usuario->id]);

        // Sem motivo a reabertura é recusada: reabrir é reprovar o que está
        // em produção, e o card chegava à bancada sem ninguém saber por quê.
        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_desenvolvimento',
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertSame('concluida', $tarefa->fresh()->status);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_desenvolvimento',
            'motivo' => 'Cliente reportou o erro de novo.',
        ]);

        $resposta->assertSessionMissing('erro');
        $this->assertSame('em_desenvolvimento', $tarefa->fresh()->status);
        $this->assertSame('concluida', $tarefa->fresh()->retorno_de);

        $cancelada = $this->criarTarefa(['status' => 'cancelada', 'responsavel_id' => $usuario->id]);

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
        // Membro com a tarefa em mãos: é para quem executa que o menu é a
        // lista do fluxo. Quem triaga vê o quadro inteiro (AC-283).
        $usuario = User::factory()->membro()->create();
        $this->criarTarefa(['titulo' => 'Tarefa em revisão', 'status' => 'em_revisao', 'responsavel_id' => $usuario->id]);
        $naBancada = $this->criarTarefa(['titulo' => 'Tarefa na bancada', 'status' => 'em_desenvolvimento', 'responsavel_id' => $usuario->id]);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // Da revisão a tarefa avança para o staging ou volta para a bancada —
        // a volta é a própria reprovação, e leva o motivo (AC-087).
        //
        // O menu é uma LISTA DE BOTÕES, um por destino: o select escondia os
        // três até ser aberto, e "Confirmar" no pé era o que se aperta sem ler.
        // Os três destinos abrem painel — a devolução e o cancelamento cobram
        // motivo, e a entrada num portão de exame oferece o "quem revisa /
        // quem testa" (US-087), anunciado como "apontar quem" e não como
        // "pede motivo": apontar é escolha opcional, não texto cobrado.
        $id = Tarefa::firstWhere('titulo', 'Tarefa em revisão')->id;
        $card = $this->trechoDoCard($html, $id);

        $this->assertStringContainsString("abrirPendente({$id}, 'em_desenvolvimento'", $card);
        $this->assertStringContainsString("abrirPendente({$id}, 'cancelada'", $card);
        $this->assertStringContainsString("abrirPendente({$id}, 'em_staging'", $card);
        $this->assertStringContainsString('apontar quem', $card);

        $this->assertStringContainsString('Mover para', $card);
        $this->assertStringContainsString('Em staging', $card);

        // Destino que não pede nada continua movendo NA HORA: o status viaja
        // no botão que enviou, e o de_status da guarda de concorrência no
        // formulário único do menu. É a guarda contra o bug original, em que
        // o clique sem receita não fazia NADA, em silêncio.
        $cardNaBancada = $this->trechoDoCard($html, $naBancada->id);

        $this->assertMatchesRegularExpression(
            '/name="de_status" value="em_desenvolvimento">.*<button type="submit" name="status" value="backlog"/su',
            $cardNaBancada,
            'Destino sem painel move na hora: o status viaja no botão que enviou, '
                .'e o de_status da guarda de concorrência no formulário único do menu.'
        );

        // A volta para a bancada vindo de um portão é reprovação: o menu avisa
        // que ela cobra texto ANTES do clique.
        $this->assertStringContainsString('pede motivo', $card);

        // Bloquear fecha a lista e não entra nela: travar não é mover.
        $this->assertStringContainsString('Marcar como bloqueada', $card);

        // E o formulário de bloqueio que ficava sempre aberto no menu saiu — o
        // texto agora é pedido pelo painel, como o de todas as outras.
        $this->assertStringNotContainsString('Bloquear: esperando quem', $card);
        $this->assertStringNotContainsString('Confirmar', $card);
    }

    /**
     * @spec:AC-211 Vindo de um portão, o destino Em andamento se chama "Devolver para
     * correção" — nunca pelo nome da etapa.
     *
     * "Em andamento" não descreve o que o clique faz ali: a tarefa não está avançando
     * para a bancada, está sendo REPROVADA e devolvida. Um menu que chama as duas
     * coisas pelo mesmo nome esconde a única que tem consequência, e o card do outro
     * lado amanhece com uma tarja que ninguém acha que pediu.
     */
    public function test_o_menu_chama_a_devolucao_pelo_que_ela_e(): void
    {
        $usuario = User::factory()->create();
        $responsavel = User::factory()->create();

        foreach (['em_revisao', 'em_staging', 'pronta_producao'] as $portao) {
            $tarefa = $this->criarTarefa(['status' => $portao, 'titulo' => 'No portão '.$portao]);

            $card = $this->trechoDoCard(
                $this->actingAs($usuario)->get(route('tarefas.index'))->getContent(), $tarefa->id
            );

            $this->assertStringContainsString('Devolver para correção', $card,
                "De {$portao} a volta é reprovação, e o menu precisa dizer isso.");
            $this->assertStringNotContainsString('>Em andamento<', $card);

            $tarefa->delete();
        }

        // Do Backlog é literalmente começar a trabalhar: aí o nome da etapa é
        // o nome certo, e "Devolver para correção" seria mentira.
        $doBacklog = $this->criarTarefa([
            'status' => 'backlog', 'responsavel_id' => $responsavel->id, 'titulo' => 'Na fila',
        ]);

        $card = $this->trechoDoCard(
            $this->actingAs($usuario)->get(route('tarefas.index'))->getContent(), $doBacklog->id
        );

        $this->assertStringContainsString('Em andamento', $card);
        $this->assertStringNotContainsString('Devolver para correção', $card);
    }

    /**
     * @spec:AC-187 Durante o arrasto, a coluna que não aceita o card apaga: o card
     * entrega os próprios destinos ao ser pego, e cada coluna se pergunta se está
     * neles. Antes o quadro deixava soltar em qualquer lugar e só depois respondia
     * "transição inválida" — o caminho parecia existir até o fim.
     */
    public function test_o_quadro_apaga_a_coluna_que_nao_aceita_o_card_arrastado(): void
    {
        // Membro dono do card: os destinos que ele entrega no `pegar` são a
        // lista do fluxo. Para quem triaga a lista é o quadro inteiro (AC-283).
        $usuario = User::factory()->membro()->create();

        // Operacional em Em andamento: ela NUNCA passa por testes, e era
        // exatamente esse arrasto que terminava em "transição inválida".
        $this->criarTarefa([
            'titulo' => 'Falar com o fabricante', 'tipo' => 'operacional',
            'status' => 'em_desenvolvimento', 'responsavel_id' => $usuario->id,
        ]);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // O card entrega ao quadro, ao ser pego: os próprios destinos, o tipo
        // (que decide se concluir pede relatório) e se já está travado.
        // O `preg_replace` tira a indentação do atributo multilinha — a
        // asserção é sobre o que o card informa, não sobre como o Blade quebrou
        // a linha.
        $numaLinha = preg_replace('/\s+/', ' ', $html);

        $this->assertStringContainsString(
            'pegar( $el, '.Tarefa::first()->id.', '.Js::from(['concluida', 'backlog', 'cancelada'])->toHtml()
                .", 'operacional', false, 'em_desenvolvimento' )",
            $numaLinha,
            'O card precisa levar destinos, tipo e situação de bloqueio para o quadro no dragstart.'
        );

        // E cada coluna do quadro se pergunta se aceita o que está na mão.
        foreach (['aberta', 'backlog', 'em_desenvolvimento', 'em_revisao', 'em_staging', 'pronta_producao'] as $etapa) {
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
        $this->criarTarefa(['status' => 'em_revisao']);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // As etapas e faixas que pedem texto se declaram no solto. Em andamento
        // pergunta em vez de afirmar: se ele pede texto depende do portão de
        // onde o card veio, e a coluna renderizada não sabe disso.
        $this->assertStringContainsString("soltar('em_desenvolvimento', pedeTexto('em_desenvolvimento'))", $html);

        // Bloquear não é mais destino de arrasto: ele virou botão do card, e o
        // painel abre pelo `abrirPendente` em vez de por uma faixa de solto.
        $this->assertStringNotContainsString("soltar('bloqueio', true)", $html);

        // ...e o quadro abre o painel em vez de engolir o gesto.
        $this->assertStringContainsString('this.abrirPendente(tarefa, status)', $html);

        // O painel nomeia a ação e o resultado — "Confirmar" é o que se aperta
        // sem ler —, e diz por que está pedindo o texto.
        // O painel nomeia a ação em duas partes — verbo e destino em negrito
        // na cor dele —, como o protótipo: "Devolvendo para **Em andamento**".
        $this->assertStringContainsString("verbo: 'Devolvendo para', label: 'Em andamento'", $html);
        $this->assertStringContainsString('Devolver para correção', $html);
        $this->assertStringContainsString('Bloquear tarefa', $html);
        $this->assertStringContainsString('Diga o que precisa ser corrigido no PR', $html);

        // E a devolução muda de texto conforme o portão: falhar no staging não
        // é reprovar um PR, porque lá o código já está na main.
        $this->assertStringContainsString('o código JÁ está na main', $html);

        // A coluna de destino segue realçada enquanto o motivo não vem.
        $this->assertStringContainsString("pendente?.destino === 'em_desenvolvimento'", $html);
    }

    /**
     * @spec:AC-189 Concluir tem GESTO e não vive escondido num dropdown.
     *
     * O critério nasceu contra a ação mais importante do fluxo ser a única sem
     * caminho direto. A faixa vertical de solto resolvia isso gastando 132px da
     * largura do quadro; o desenho final resolve com um botão no próprio card —
     * mesma queixa, preço menor. O botão só aparece onde o fluxo permite: fixo,
     * ele ficaria morto na maioria dos cards, e botão que quase nunca funciona
     * ensina a não clicar em nenhum.
     */
    public function test_concluir_e_botao_do_card_e_so_onde_o_fluxo_permite(): void
    {
        // Membro dono dos cards: para quem triaga o fluxo permite concluir de
        // qualquer lugar (AC-281), e o botão apareceria nos dois.
        $usuario = User::factory()->membro()->create();
        $pronta = $this->criarTarefa(['status' => 'pronta_producao', 'titulo' => 'Já validada', 'responsavel_id' => $usuario->id]);
        $emRevisao = $this->criarTarefa(['status' => 'em_revisao', 'titulo' => 'Ainda em leitura', 'responsavel_id' => $usuario->id]);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        $this->assertStringContainsString(
            "abrirPendente({$pronta->id}, 'concluida'",
            $this->trechoDoCard($html, $pronta->id),
            'De Pronta p/ produção o fluxo permite concluir: o botão precisa estar no card.'
        );
        $this->assertStringNotContainsString(
            "abrirPendente({$emRevisao->id}, 'concluida'",
            $this->trechoDoCard($html, $emRevisao->id),
            'De Em revisão não se conclui: o botão não pode aparecer só para recusar depois.'
        );

        // As faixas verticais saíram junto: elas custavam largura numa tela de
        // seis colunas, e o que faziam bem virou chip de cabeçalho.
        $this->assertStringNotContainsString("soltar('concluida', true)", $html);

        // E Concluída continua sem ser coluna: nenhuma etapa terminal no quadro.
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
        $tarefa = $this->criarTarefa(['status' => 'em_revisao']);

        app(FluxoTarefaService::class)
            ->bloquear($tarefa, 'Revenda não respondeu o e-mail de confirmação.');

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // O card continua na coluna Em revisão, com a tarja dentro dele.
        $colunas = $this->actingAs($usuario)->get(route('tarefas.index'))->viewData('colunas');
        $this->assertContains($tarefa->id, $colunas['em_revisao']->pluck('id')->all());

        $this->assertStringContainsString('Bloqueada agora', $html);
        $this->assertStringContainsString('Revenda não respondeu o e-mail de confirmação.', $html);

        // O destravar mora na tarja: quem lê o motivo é quem acabou de
        // descobrir que ele não vale mais.
        $this->assertStringContainsString(route('tarefas.bloquear', $tarefa), $html);
        $this->assertStringContainsString('Destravar tarefa', $html);
    }

    /**
     * @spec:AC-190 Bloquear é SEMPRE válido e mora no card.
     *
     * Travar não é mover: não depende de para onde a tarefa pode ir, então o
     * botão não some conforme a etapa. A faixa vertical que recebia esse gesto
     * saiu — o que ela fazia bem, contar as travadas e servir de porta para
     * elas, virou chip do cabeçalho.
     */
    public function test_bloquear_e_botao_do_card_e_a_contagem_virou_chip(): void
    {
        $usuario = User::factory()->create();
        $solta = $this->criarTarefa(['status' => 'em_revisao', 'titulo' => 'Solta']);
        $travada = $this->criarTarefa(['status' => 'em_revisao', 'titulo' => 'Travada']);

        app(FluxoTarefaService::class)->bloquear($travada, 'Esperando a credencial.');

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // Na solta, o botão ABRE o painel: travar exige o motivo, e um POST
        // direto daqui seria recusado com uma frase que ninguém pediu.
        $this->assertStringContainsString(
            "abrirPendente({$solta->id}, 'bloqueio'", $this->trechoDoCard($html, $solta->id)
        );

        // Na travada, o mesmo lugar vira destravar — que não pede texto.
        $this->assertStringContainsString(
            route('tarefas.bloquear', $travada), $this->trechoDoCard($html, $travada->id)
        );
        $this->assertStringContainsString('Destravar tarefa', $this->trechoDoCard($html, $travada->id));

        // A faixa vertical saiu, e a contagem virou chip clicável.
        $this->assertStringNotContainsString("soltar('bloqueio', true)", $html);
        $this->assertStringContainsString('1 travadas', $html);
        $this->assertStringContainsString('situacao=travadas', $html);
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

    /** O HTML de um card só, do `data-tarefa` dele até o fim do `<article>`. */
    private function trechoDoCard(string $html, int $tarefaId): string
    {
        $inicio = strpos($html, 'data-tarefa="'.$tarefaId.'"');
        $this->assertNotFalse($inicio, "A tarefa {$tarefaId} não apareceu no quadro.");

        return substr($html, $inicio, strpos($html, '</article>', $inicio) - $inicio);
    }
}
