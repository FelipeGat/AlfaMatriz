<?php

namespace App\Services;

use App\Models\Notificacao;
use App\Models\Tarefa;
use App\Models\TarefaComentario;
use App\Models\TarefaEvento;
use App\Models\TarefaRelatorioTeste;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * O motor do fluxo do quadro: só deixa a tarefa se mover entre etapas que o
 * fluxo DO TIPO DELA permite, cobra o que cada transição exige (responsável,
 * motivo, relatório de teste aprovado) e fecha o evento de etapa aberto e abre
 * o próximo a cada mudança — é o tempo por etapa (US-038) sendo registrado.
 *
 * O princípio dos mapas abaixo: **restringir o avanço, liberar o recuo**.
 * Avançar pula trabalho, e é por isso que avançar se guarda. Recuar é admitir
 * o que já aconteceu, e admitir a realidade nunca precisa de permissão — um
 * quadro que recusa a volta não impede o erro, ensina a mentir para ele: o card
 * é arrastado por uma etapa que ninguém fez, só para chegar onde a realidade já
 * está. E como cada etapa é cronometrada, a mentira não fica na tela — ela
 * contamina o número.
 *
 * O mapa manda em quem EXECUTA. Quem faz triagem move livre (US-079): quem
 * organiza o quadro é quem o conserta quando ele e a realidade divergem, e a
 * correção não pode depender de arrastar o card por etapas que ninguém fez.
 * Só o mapa afrouxa — as exigências de chegada valem para todos.
 */
class FluxoTarefaService
{
    /**
     * Para onde cada etapa pode ir, por tipo de tarefa.
     *
     * A tarefa de DESENVOLVIMENTO passa pelo ciclo inteiro e só fecha com teste
     * aprovado. A OPERACIONAL — "entrar em contato com o fabricante", "renovar
     * o certificado" — não é desenvolvida nem testada: ela fecha direto de Em
     * andamento, porque exigir teste de um telefonema só ensinaria a marcar
     * como testado o que não foi.
     *
     * As duas compartilham o começo (a fila de triagem e o direcionamento), a
     * parada (Bloqueada) e o cancelamento. O que muda é o miolo.
     */
    public const FLUXOS = [
        'desenvolvimento' => [
            'aberta' => ['backlog', 'cancelada'],
            'backlog' => ['aberta', 'em_desenvolvimento', 'cancelada'],
            'em_desenvolvimento' => ['em_revisao', 'backlog', 'cancelada'],
            // Reprovar em qualquer portão devolve para Em andamento e carimba
            // de onde a tarefa voltou. Não há coluna intermediária: a antiga
            // Ajustes tinha uma saída só, com o mesmo dono, e por isso nunca
            // respondeu à pergunta que uma coluna existe para responder — quem
            // está segurando a tarefa.
            'em_revisao' => ['em_staging', 'em_desenvolvimento', 'cancelada'],
            'em_staging' => ['pronta_producao', 'em_desenvolvimento', 'cancelada'],
            'pronta_producao' => ['concluida', 'em_staging', 'em_desenvolvimento', 'cancelada'],
            // Reabrir é reprovação de quem usou o que subiu: cobra motivo e
            // carimba o retorno, como os portões (`ehRetornoParaBancada`).
            'concluida' => ['em_desenvolvimento'],
            'cancelada' => ['aberta'],
        ],
        'operacional' => [
            'aberta' => ['backlog', 'cancelada'],
            'backlog' => ['aberta', 'em_desenvolvimento', 'cancelada'],
            'em_desenvolvimento' => ['concluida', 'backlog', 'cancelada'],
            // Etapas do ciclo de desenvolvimento: a tarefa operacional não
            // CHEGA nelas — nenhuma etapa dela oferece esse destino. Elas estão
            // aqui como saída de emergência para o caso de alguém trocar o tipo
            // de uma tarefa que já estava num portão: sem isso, o card ficaria
            // preso numa coluna sem nenhum caminho de volta.
            'em_revisao' => ['em_desenvolvimento', 'concluida', 'cancelada'],
            'em_staging' => ['em_desenvolvimento', 'concluida', 'cancelada'],
            'pronta_producao' => ['em_desenvolvimento', 'concluida', 'cancelada'],
            'concluida' => ['em_desenvolvimento'],
            'cancelada' => ['aberta'],
        ],
    ];

    /**
     * Os destinos que a tarefa oferece agora — o que o menu "Mover ▾" lista.
     *
     * Existe porque o fluxo deixou de ser um mapa só: quem pergunta "para onde
     * este card pode ir" precisa perguntar sobre O CARD, não sobre o status.
     *
     * @return list<string>
     */
    public static function transicoesDe(Tarefa $tarefa): array
    {
        $fluxo = self::FLUXOS[$tarefa->tipo] ?? self::FLUXOS['desenvolvimento'];

        return $fluxo[$tarefa->status] ?? [];
    }

    /**
     * Os destinos do movimento LIVRE: o quadro inteiro, menos onde o card está.
     *
     * É o que quem faz triagem enxerga (US-079). O mapa educa quem executa;
     * quem organiza precisa exatamente do movimento que o mapa recusa —
     * devolver à coluna certa o card que a realidade já desmentiu. De quebra,
     * é a saída da tarefa parada numa etapa aposentada, que não está em mapa
     * nenhum e para todo mundo mais não tem destino algum.
     *
     * A operacional continua sem entrar nos portões, mesmo livre: eles
     * examinam código, e um telefonema numa coluna chamada "Em revisão · PR"
     * faria a coluna mentir. Livre é sobre a ordem das etapas, não sobre o
     * vocabulário do tipo.
     *
     * @return list<string>
     */
    public static function transicoesLivres(Tarefa $tarefa): array
    {
        $etapas = array_keys(Tarefa::STATUS);

        if ($tarefa->tipo === 'operacional') {
            $etapas = array_diff($etapas, Tarefa::PORTOES);
        }

        return array_values(array_diff($etapas, [$tarefa->status]));
    }

    /**
     * Move a tarefa para o novo status, recusando transições fora do fluxo e
     * cobrando o que cada uma exige, e registra a mudança de etapa.
     *
     * `$livre` troca o mapa do tipo pelo quadro inteiro (`transicoesLivres`) —
     * o movimento de quem faz triagem (US-079). Só o mapa afrouxa: as
     * exigências de chegada continuam, porque elas não guardam a ordem das
     * etapas — guardam a informação que cada chegada registra, e um
     * cancelamento sem motivo mente igual venha de quem vier.
     *
     * @param  array{motivo?: ?string, versao_producao?: ?string, interlocutor_id?: int|string|null}  $dados
     */
    public function mover(Tarefa $tarefa, string $novoStatus, array $dados = [], bool $livre = false): Tarefa
    {
        $statusAtual = $tarefa->status;

        $this->assertTransicaoPermitida($tarefa, $novoStatus, $livre);
        $this->assertExigenciasAtendidas($tarefa, $novoStatus, $dados);

        $ehRetorno = $this->ehRetorno($statusAtual, $novoStatus);

        return DB::transaction(function () use ($tarefa, $statusAtual, $novoStatus, $dados, $ehRetorno) {
            $agora = now();

            $this->fecharEventoAberto($tarefa, $agora);

            // A posição manual é DENTRO de uma coluna: levá-la junto faria o
            // card chegar na coluna nova já encaixado num lugar que ninguém
            // escolheu para ele ali. Sem posição, ele entra no fim, esperando.
            $atualizacao = ['status' => $novoStatus, 'ordem' => null];

            // Entrar em Aberta solta o responsável, venha de onde vier: Aberta é
            // a fila do que ainda não tem dono (AC-130), e um card com nome
            // dentro dela seria a coluna contradizendo o próprio significado.
            // Valia só para o recuo do Backlog (AC-124); agora vale também para
            // a tarefa que volta de um cancelamento, que é justamente a que mais
            // provavelmente será entregue a outra pessoa.
            if ($novoStatus === 'aberta') {
                $atualizacao['responsavel_id'] = null;
            }

            $tarefa->update($atualizacao);

            // Mudar de etapa destrava. O bloqueio é sempre sobre o trabalho de
            // uma etapa — "esperando o cliente validar" é uma frase sobre a
            // etapa em que foi dita —, e carregá-lo para a etapa seguinte faria
            // o card anunciar um impedimento que não vale mais. Quem moveu, agiu.
            if ($tarefa->bloqueado_em !== null) {
                $tarefa->forceFill(['bloqueado_em' => null, 'bloqueio_motivo' => null])->save();
            }

            $this->reposicionarMarcas($tarefa, $novoStatus, $statusAtual, $dados, $ehRetorno);

            // O apontado fica sabendo que a bola chegou nele — pelo sino, não
            // pelo chip de perguntas: apontar não cobra resposta em texto,
            // cobra o exame. `avisar` cala quando quem moveu apontou a si
            // mesmo.
            $apontado = $dados['interlocutor_id'] ?? null;

            if ($apontado && $this->entradaEmPortaoDeExame($tarefa, $novoStatus, $statusAtual)) {
                Notificacao::avisar((int) $apontado, auth()->id(), [
                    'tipo' => 'apontamento',
                    'nivel' => 'atencao',
                    'icone' => 'eye',
                    'titulo' => '«'.$tarefa->titulo.'» está com você',
                    'meta' => 'Entrou em '.Tarefa::rotuloDaEtapa($novoStatus).' · apontada para você',
                    'rota' => route('tarefas.index'),
                    'tarefa_id' => $tarefa->id,
                ]);
            }

            // Ser devolvido é a notícia que menos pode esperar alguém abrir o
            // quadro: a tarefa voltou para a bancada e o trabalho recomeça.
            if ($ehRetorno) {
                Notificacao::avisar($tarefa->responsavel_id, auth()->id(), [
                    'tipo' => 'retorno',
                    'nivel' => 'atencao',
                    'icone' => 'arrow-uturn-left',
                    // "Correção" é a bancada; a volta para a revisão devolve o
                    // PR ao exame, e anunciá-la como correção mandaria o dev
                    // reabrir um código que ninguém devolveu a ele.
                    'titulo' => '"'.$tarefa->titulo.'"'.($novoStatus === 'em_desenvolvimento'
                        ? ' voltou para correção'
                        : ' voltou para revisão'),
                    'meta' => $tarefa->rotuloDoRetornoVindoDe($statusAtual),
                    'rota' => route('tarefas.index'),
                    'tarefa_id' => $tarefa->id,
                ]);
            }

            TarefaEvento::create([
                'tarefa_id' => $tarefa->id,
                // Quem moveu (AC-301). Nulo quando não há ninguém logado — a
                // rotina que mover tarefa sem sessão registra movimento sem
                // autor, que é o que de fato aconteceu.
                'user_id' => auth()->id(),
                'de_status' => $statusAtual,
                'para_status' => $novoStatus,
                'motivo' => $dados['motivo'] ?? null,
                'entrou_em' => $agora,
            ]);

            return $tarefa->refresh();
        });
    }

    /**
     * Voltar de mais adiante — para a bancada ou para a revisão — é
     * reprovação, e não recuo qualquer.
     *
     * A reabertura entra na mesma regra porque é o exame mais duro dos
     * quatro: quem reprova aqui é quem usou o que subiu. Ela já foi volta
     * livre, e o card aterrissava em Em andamento indistinguível de trabalho
     * novo — quem o recebia não tinha como saber POR QUE a tarefa voltou.
     *
     * A volta para a REVISÃO só existe no movimento livre (US-079), e foi por
     * ele que escapou da regra: o admin devolvia do staging ou da produção
     * para o exame sem dizer por quê, e o card chegava ao revisor
     * indistinguível de um PR novo. A exigência de chegada vale para todos —
     * inclusive na chegada que só quem triaga alcança.
     */
    private function ehRetorno(string $statusAtual, string $novoStatus): bool
    {
        if ($novoStatus === 'em_desenvolvimento') {
            return $statusAtual === 'concluida' || in_array($statusAtual, Tarefa::PORTOES, true);
        }

        // Só as etapas À FRENTE da revisão devolvem para ela. A tarefa
        // resgatada de uma etapa aposentada (`em_testes`) fica de fora: ela
        // não está voltando de exame nenhum, está sendo devolvida ao quadro.
        if ($novoStatus === 'em_revisao') {
            return $statusAtual === 'concluida'
                || in_array($statusAtual, ['em_staging', 'pronta_producao'], true);
        }

        return false;
    }

    /**
     * Esta passagem é uma ENTRADA num portão de exame?
     *
     * Só a tarefa de desenvolvimento entra de verdade — a operacional que
     * encalha num portão (a saída de emergência da troca de tipo) não tem
     * exame acontecendo, e recomeçar a conversa dela seria mexer no que o
     * movimento não tocou.
     */
    private function entradaEmPortaoDeExame(Tarefa $tarefa, string $novoStatus, string $statusAtual): bool
    {
        return $tarefa->tipo === 'desenvolvimento'
            && $novoStatus !== $statusAtual
            && in_array($novoStatus, Tarefa::PORTOES_DE_EXAME, true);
    }

    /**
     * Onde as marcas do card ficam depois de a tarefa mudar de etapa.
     *
     * O retorno é a única que NASCE de um movimento; as outras duas só morrem
     * nele. A ordem importa: a mesma passagem que carimba o retorno não pode
     * apagá-lo em seguida, e é por isso que a limpeza é o ramo `else` e não uma
     * linha solta antes.
     *
     * @param  array{motivo?: ?string, versao_producao?: ?string, interlocutor_id?: int|string|null}  $dados
     */
    private function reposicionarMarcas(
        Tarefa $tarefa,
        string $novoStatus,
        string $statusAtual,
        array $dados,
        bool $ehRetorno,
    ): void {
        $marcas = [];

        if ($ehRetorno) {
            $marcas['retorno_de'] = $statusAtual;
            $marcas['retorno_motivo'] = trim((string) ($dados['motivo'] ?? ''));

            // A conversa que empacou foi resolvida pela própria devolução — é
            // exatamente o que o alerta de terceira rodada sugere fazer. Manter
            // a contagem deixaria o card vermelho para sempre, avisando sobre
            // um impasse que já foi tratado.
            $marcas['rodadas'] = 0;

            // A reabertura devolve o trabalho, não a versão: com ela guardada,
            // a reconclusão passava no portão apoiada na versão do ciclo
            // anterior — o mesmo vazamento que `testeDestaPassagem` fechou
            // para o relatório de teste.
            if ($statusAtual === 'concluida') {
                $marcas['versao_producao'] = null;
            }
        } else {
            // Andar para frente apaga a tarja: ela descreve de onde a tarefa
            // voltou da última vez, e uma tarefa que já saiu da bancada não
            // está mais voltando de lugar nenhum.
            $marcas['retorno_de'] = null;
            $marcas['retorno_motivo'] = null;
        }

        // A pergunta é sobre o trabalho de uma etapa, como o bloqueio: uma
        // dúvida sobre o PR não sobrevive à tarefa sair da revisão. O que
        // sobrevive é `interlocutor_id` — perder com quem se estava falando é
        // justamente o que persistir esse campo existe para evitar.
        if ($novoStatus !== $statusAtual) {
            $marcas['pergunta_de_id'] = null;
            $marcas['pergunta_para_id'] = null;
            $marcas['pergunta_em'] = null;
        }

        // A exceção da sobrevivência: cada portão de exame RECOMEÇA a conversa
        // (Q-037). Quem entra na revisão ou no staging fala com o examinador
        // desta passagem — sem o recomeço, a pergunta do staging apontava para
        // o revisor de ontem, e não havia como apontar o testador. As rodadas
        // zeram junto (ASM-079): o alerta de 3ª rodada é sobre a conversa
        // atual, e herdar a contagem acenderia o aviso de um impasse que já
        // acabou. O apontado, quando houver, já entra como o outro lado.
        if ($this->entradaEmPortaoDeExame($tarefa, $novoStatus, $statusAtual)) {
            $marcas['interlocutor_id'] = $dados['interlocutor_id'] ?? null;
            $marcas['rodadas'] = 0;
        }

        if (isset($dados['versao_producao']) && trim((string) $dados['versao_producao']) !== '') {
            $marcas['versao_producao'] = trim((string) $dados['versao_producao']);
        }

        $tarefa->forceFill($marcas)->save();
    }

    /**
     * Registra uma pergunta e passa a bola para o outro lado.
     *
     * Numa revisão só há dois lados, então não se escolhe destinatário: quem
     * pergunta é de um lado, e a pergunta vai para o outro.
     *
     * A tarefa NÃO sai da etapa e NÃO sai do WIP — responder é rápido, e fingir
     * que ela saiu de circulação seria mentira. Também não conta como travada:
     * uma dúvida de vinte minutos diluiria o sinal de um bloqueio de seis dias.
     */
    public function perguntar(
        Tarefa $tarefa,
        User $quemPergunta,
        ?string $corpo,
        ?int $paraEscolhido = null,
    ): TarefaComentario {
        if (trim((string) $corpo) === '') {
            throw new \RuntimeException('É preciso escrever a pergunta.');
        }

        if (in_array($tarefa->status, Tarefa::STATUS_TERMINAIS, true)) {
            throw new \RuntimeException('Tarefa encerrada não tem conversa em aberto.');
        }

        // O lado que o quadro sabe sozinho MANDA sobre a escolha: numa revisão
        // só há dois lados, e deixar escolher onde não há escolha abriria a
        // porta para mandar a pergunta a quem não está na conversa.
        $paraId = $tarefa->outroLadoDe($quemPergunta) ?? $paraEscolhido;

        if ($paraId === null) {
            throw new \RuntimeException('Escolha para quem vai a pergunta.');
        }

        if ($paraId === $quemPergunta->id) {
            throw new \RuntimeException('A pergunta precisa ir para outra pessoa.');
        }

        return DB::transaction(function () use ($tarefa, $quemPergunta, $corpo, $paraId) {
            $comentario = $tarefa->comentarios()->create([
                'autor_id' => $quemPergunta->id,
                'corpo' => trim((string) $corpo),
                'pergunta' => true,
            ]);

            $tarefa->forceFill([
                'rodadas' => $tarefa->rodadas + ($this->abreRodadaNova($tarefa, $quemPergunta) ? 1 : 0),
                'interlocutor_id' => $paraId,
                'pergunta_de_id' => $quemPergunta->id,
                'pergunta_para_id' => $paraId,
                'pergunta_em' => now(),
            ])->save();

            // A meta diz a RODADA e o relógio: "perguntou" sozinho não separa a
            // primeira dúvida da terceira, e é a terceira que pede outra ação.
            Notificacao::avisar($paraId, $quemPergunta->id, [
                'tipo' => 'pergunta',
                'nivel' => $tarefa->conversaEmpacada() ? 'critico' : 'atencao',
                'icone' => 'duvida',
                'titulo' => $quemPergunta->name.' perguntou em «'.$tarefa->titulo.'»',
                'meta' => max(1, $tarefa->rodadas).'ª rodada · aguardando sua resposta',
                'rota' => route('tarefas.index', ['esperando' => '1']),
                'tarefa_id' => $tarefa->id,
            ]);

            return $comentario;
        });
    }

    /**
     * A rodada só anda quando a bola estava com quem pergunta.
     *
     * Cinco dúvidas mandadas de uma vez são uma rodada, e insistir sem ter
     * recebido resposta é a MESMA rodada — senão quem cobra retorno inflaria
     * sozinho um contador que existe para medir idas E voltas. Sem pergunta
     * aberta, a bola é de quem fala: ninguém deve nada a ninguém.
     */
    private function abreRodadaNova(Tarefa $tarefa, User $quemPergunta): bool
    {
        if (! $tarefa->temPergunta()) {
            return true;
        }

        return $tarefa->pergunta_para_id === $quemPergunta->id;
    }

    /**
     * Responde e devolve a bola, apagando o ponteiro.
     *
     * `rodadas` e `interlocutor_id` ficam: eles vivem fora do ponteiro
     * exatamente para sobreviver a esta linha. Guardados dentro dele, toda
     * rodada nova recomeçaria do 1 e o alerta de terceira rodada nunca
     * dispararia.
     */
    public function responder(Tarefa $tarefa, User $quemResponde, ?string $corpo): TarefaComentario
    {
        if (trim((string) $corpo) === '') {
            throw new \RuntimeException('É preciso escrever a resposta.');
        }

        if (! $tarefa->temPergunta()) {
            throw new \RuntimeException('Não há pergunta aberta nesta tarefa.');
        }

        if ($tarefa->pergunta_para_id !== $quemResponde->id) {
            throw new \RuntimeException('Esta pergunta não é para você.');
        }

        return DB::transaction(function () use ($tarefa, $quemResponde, $corpo) {
            $comentario = $tarefa->comentarios()->create([
                'autor_id' => $quemResponde->id,
                'corpo' => trim((string) $corpo),
            ]);

            $perguntou = $tarefa->pergunta_de_id;

            $tarefa->forceFill([
                'interlocutor_id' => $perguntou,
                'pergunta_de_id' => null,
                'pergunta_para_id' => null,
                'pergunta_em' => null,
            ])->save();

            // Quem perguntou não fica olhando o card à espera: a resposta é o
            // evento que o traz de volta.
            Notificacao::avisar($perguntou, $quemResponde->id, [
                'tipo' => 'resposta',
                'nivel' => 'marca',
                'icone' => 'duvida',
                'titulo' => $quemResponde->name.' respondeu em «'.$tarefa->titulo.'»',
                'meta' => 'A bola voltou para você',
                'rota' => route('tarefas.index'),
                'tarefa_id' => $tarefa->id,
            ]);

            return $comentario;
        });
    }

    /**
     * Registra o veredito do teste do staging, sem mover a tarefa.
     *
     * Quem testa não é necessariamente quem move: no processo do time, o
     * testador não é o responsável pela tarefa — e enquanto o carimbo da
     * validação só viajava no movimento para Pronta p/ produção, o teste dele
     * não tinha onde existir. Aqui o relatório nasce assinado e preso ao
     * evento aberto (a passagem atual pelo staging), que é exatamente o que o
     * portão da produção lê depois (`aprovadaNestaPassagem`).
     *
     * A tarefa NÃO sai do lugar: registrar o teste é sobre o trabalho da
     * etapa, como a pergunta e o bloqueio — mover continua sendo gesto de quem
     * move, apoiado no veredito registrado.
     */
    public function registrarTesteDoStaging(
        Tarefa $tarefa,
        User $quemTestou,
        bool $aprovado,
        ?string $notas,
    ): TarefaRelatorioTeste {
        // Só a tarefa de desenvolvimento em Em staging tem staging para
        // testar. Registrar veredito fora daí criaria um relatório solto que a
        // etapa seguinte poderia ler como prova do que ninguém validou.
        if ($tarefa->tipo !== 'desenvolvimento' || $tarefa->status !== 'em_staging') {
            throw new \RuntimeException('Só a tarefa em Em staging tem teste do staging para registrar.');
        }

        // Reprovar sem dizer o quê manda o dev abrir o staging e adivinhar —
        // a mesma regra do retorno de portão. Aprovar dispensa o texto: o
        // veredito é a informação inteira.
        if (! $aprovado && trim((string) $notas) === '') {
            throw new \RuntimeException('É preciso dizer o que reprovou no teste.');
        }

        $relatorio = $tarefa->relatoriosTeste()->create([
            'user_id' => $quemTestou->id,
            'aprovado' => $aprovado,
            'notas' => trim((string) $notas) !== '' ? trim((string) $notas) : null,
        ]);

        // O responsável é quem age sobre o veredito — segue para a fila da
        // produção ou volta para a bancada — e não deveria descobri-lo por
        // acaso. `avisar` já cala quando quem testou é o próprio responsável.
        Notificacao::avisar($tarefa->responsavel_id, $quemTestou->id, [
            'tipo' => 'teste_staging',
            'nivel' => $aprovado ? 'marca' : 'atencao',
            'icone' => $aprovado ? 'check-circle' : 'alert-triangle',
            'titulo' => $quemTestou->name.($aprovado ? ' aprovou' : ' reprovou').' o staging de «'.$tarefa->titulo.'»',
            'meta' => $aprovado ? 'Staging validado · pronta para seguir' : 'Reprovada no teste do staging',
            'rota' => route('tarefas.index'),
            'tarefa_id' => $tarefa->id,
        ]);

        return $relatorio;
    }

    /**
     * Marca a tarefa como travada, sem tirá-la da etapa.
     *
     * O bloqueio já foi etapa, e como etapa ele APAGAVA onde a tarefa estava —
     * o mapa de transições tinha de reconstruir isso na mão para não devolver à
     * bancada o código que estava em teste. Como marca, não há o que
     * reconstruir: a tarefa não saiu do lugar.
     *
     * Por isso também não nasce evento aqui. `tarefa_eventos` mede permanência
     * em ETAPA, e uma linha de bloqueio ali fecharia o evento da etapa atual —
     * o cronômetro passaria a contar duas passagens onde houve uma só.
     */
    public function bloquear(Tarefa $tarefa, ?string $motivo): Tarefa
    {
        if (trim((string) $motivo) === '') {
            throw new \RuntimeException('É preciso dizer o que está travando a tarefa.');
        }

        if ($tarefa->estaBloqueada()) {
            throw new \RuntimeException('Esta tarefa já está bloqueada.');
        }

        if (in_array($tarefa->status, Tarefa::STATUS_TERMINAIS, true)) {
            throw new \RuntimeException('Tarefa encerrada não tem trabalho para travar.');
        }

        // `forceFill` porque as duas colunas ficam fora do `fillable`: travar é
        // ação com regra própria, e um `update` vindo do formulário de cadastro
        // não deveria ser capaz de marcar tarefa como bloqueada de passagem.
        $tarefa->forceFill([
            'bloqueado_em' => now(),
            'bloqueio_motivo' => trim((string) $motivo),
        ])->save();

        $this->avisarBloqueio($tarefa);

        return $tarefa->refresh();
    }

    /**
     * O bloqueio é notícia para quem pode destravá-lo: o responsável (é o
     * trabalho dele que parou) e quem faz triagem (é quem vai atrás do
     * impedimento ou reorganiza o quadro). A tarja âmbar só aparece para quem
     * ABRE o quadro — e "staging quebrou" não pode esperar alguém abrir.
     *
     * Quem bloqueou não recebe (`avisar` cala para o autor), e o `unique`
     * garante um aviso só para o responsável que também triaga. Sem autor —
     * o bloqueio do portão do deploy roda sem sessão — todo mundo recebe,
     * que é o comportamento certo: ninguém ali agiu.
     */
    private function avisarBloqueio(Tarefa $tarefa): void
    {
        $destinatarios = User::idsDeQuemTriaTarefas()
            ->push($tarefa->responsavel_id)
            ->filter()
            ->unique();

        foreach ($destinatarios as $destinatarioId) {
            Notificacao::avisar($destinatarioId, auth()->id(), [
                'tipo' => 'bloqueio',
                'nivel' => 'atencao',
                'icone' => 'cadeado-fechado',
                'titulo' => '«'.$tarefa->titulo.'» foi bloqueada',
                // O motivo é a informação inteira do aviso, na régua curta da
                // coluna `meta` — o texto completo continua na tarja do card.
                'meta' => Str::limit((string) $tarefa->bloqueio_motivo, 120),
                'rota' => route('tarefas.index'),
                'tarefa_id' => $tarefa->id,
            ]);
        }
    }

    /** Tira a marca de travada. A etapa não muda porque ela nunca mudou. */
    public function destravar(Tarefa $tarefa): Tarefa
    {
        $tarefa->forceFill(['bloqueado_em' => null, 'bloqueio_motivo' => null])->save();

        return $tarefa->refresh();
    }

    private function assertTransicaoPermitida(Tarefa $tarefa, string $novoStatus, bool $livre): void
    {
        $permitidas = $livre ? self::transicoesLivres($tarefa) : self::transicoesDe($tarefa);

        if (! in_array($novoStatus, $permitidas, true)) {
            // `rotuloDaEtapa` e não `STATUS`: a tarefa parada numa etapa
            // aposentada é justamente a que mais recebe esta recusa, e dizer
            // "não é possível mover de em_testes" devolveria a chave crua a
            // quem só queria saber por que o card não anda.
            $origem = Tarefa::rotuloDaEtapa($tarefa->status);
            $destino = Tarefa::rotuloDaEtapa($novoStatus);

            throw new \RuntimeException("Transição inválida: não é possível mover de {$origem} para {$destino}.");
        }
    }

    /**
     * @param  array{motivo?: ?string}  $dados
     */
    private function assertExigenciasAtendidas(Tarefa $tarefa, string $novoStatus, array $dados): void
    {
        if ($novoStatus === 'backlog' && ! $tarefa->responsavel_id) {
            throw new \RuntimeException('É preciso direcionar a tarefa para alguém antes de mover para o Backlog.');
        }

        // Reprovar sem dizer o que reprovou manda a pessoa que recebe o card
        // abrir o PR e adivinhar. Só o retorno vindo de mais adiante cobra o
        // texto: Backlog → Em andamento é só começar a trabalhar, e não tem
        // motivo a dar.
        if ($this->ehRetorno($tarefa->status, $novoStatus) && ! $this->motivoPreenchido($dados)) {
            throw new \RuntimeException('É preciso dizer o que precisa ser corrigido.');
        }

        if ($novoStatus === 'cancelada' && ! $this->motivoPreenchido($dados)) {
            throw new \RuntimeException('O motivo do cancelamento é obrigatório.');
        }

        // O portão do teste mudou de lugar junto com as etapas: ele guardava a
        // saída do antigo Em testes, e agora guarda a entrada na fila da
        // produção. É aqui que o dev afirma ter validado o staging, e é essa
        // nota que o admin lê antes de subir a tag — depois deste ponto não há
        // mais quem confira.
        if ($novoStatus === 'pronta_producao'
            && $tarefa->tipo === 'desenvolvimento'
            && ! $this->aprovadaNestaPassagem($tarefa)) {
            throw new \RuntimeException('Só é possível liberar para produção depois de validar o staging.');
        }

        // Concluída passou a significar EM PRODUÇÃO, e a versão é o que liga a
        // tarefa à tag que subiu — sem ela, "concluída" volta a ser uma
        // afirmação que ninguém consegue conferir depois.
        if ($novoStatus === 'concluida'
            && $tarefa->tipo === 'desenvolvimento'
            && trim((string) ($dados['versao_producao'] ?? $tarefa->versao_producao ?? '')) === '') {
            throw new \RuntimeException('É preciso registrar a versão que subiu para produção.');
        }
    }

    /**
     * @param  array{motivo?: ?string}  $dados
     */
    private function motivoPreenchido(array $dados): bool
    {
        return trim((string) ($dados['motivo'] ?? '')) !== '';
    }

    /**
     * A validação desta passagem pelo staging foi aprovada?
     *
     * "Desta passagem" é a correção de um vazamento: a checagem lia o último
     * relatório da tarefa INTEIRA, então uma tarefa concluída, reaberta,
     * remexida e reconcluída passava pelo portão apoiada no "aprovado" do
     * ciclo anterior. O recorte vive no modelo (`testeDestaPassagem`), porque
     * a tarja do teste na tela faz a mesma pergunta — aqui só se lê o
     * veredito.
     */
    private function aprovadaNestaPassagem(Tarefa $tarefa): bool
    {
        return (bool) $tarefa->testeDestaPassagem()?->aprovado;
    }

    private function fecharEventoAberto(Tarefa $tarefa, \DateTimeInterface $agora): void
    {
        $aberto = $tarefa->eventos()->whereNull('saiu_em')->latest('entrou_em')->first();

        if (! $aberto) {
            return;
        }

        $aberto->update([
            'saiu_em' => $agora,
            'duracao_segundos' => $aberto->entrou_em->diffInSeconds($agora),
        ]);
    }
}
