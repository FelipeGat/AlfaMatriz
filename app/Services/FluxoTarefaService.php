<?php

namespace App\Services;

use App\Models\Notificacao;
use App\Models\Tarefa;
use App\Models\TarefaComentario;
use App\Models\TarefaEvento;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
     * @param  array{motivo?: ?string}  $dados
     */
    public function mover(Tarefa $tarefa, string $novoStatus, array $dados = [], bool $livre = false): Tarefa
    {
        $statusAtual = $tarefa->status;

        $this->assertTransicaoPermitida($tarefa, $novoStatus, $livre);
        $this->assertExigenciasAtendidas($tarefa, $novoStatus, $dados);

        $ehRetorno = $this->ehRetornoDePortao($statusAtual, $novoStatus);

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

            // Ser devolvido é a notícia que menos pode esperar alguém abrir o
            // quadro: a tarefa voltou para a bancada e o trabalho recomeça.
            if ($ehRetorno) {
                Notificacao::avisar($tarefa->responsavel_id, auth()->id(), [
                    'tipo' => 'retorno',
                    'nivel' => 'atencao',
                    'icone' => 'arrow-uturn-left',
                    'titulo' => '"'.$tarefa->titulo.'" voltou para correção',
                    'meta' => Tarefa::RETORNO_POR_ORIGEM[$statusAtual] ?? Tarefa::rotuloDaEtapa($statusAtual),
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

    /** Voltar de um portão para a bancada é reprovação, e não recuo qualquer. */
    private function ehRetornoDePortao(string $statusAtual, string $novoStatus): bool
    {
        return $novoStatus === 'em_desenvolvimento'
            && in_array($statusAtual, Tarefa::PORTOES, true);
    }

    /**
     * Onde as marcas do card ficam depois de a tarefa mudar de etapa.
     *
     * O retorno é a única que NASCE de um movimento; as outras duas só morrem
     * nele. A ordem importa: a mesma passagem que carimba o retorno não pode
     * apagá-lo em seguida, e é por isso que a limpeza é o ramo `else` e não uma
     * linha solta antes.
     *
     * @param  array{motivo?: ?string, versao_producao?: ?string}  $dados
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

        return $tarefa->refresh();
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
        // abrir o PR e adivinhar. Só o retorno DE UM PORTÃO cobra o texto:
        // Backlog → Em andamento é só começar a trabalhar, e não tem motivo a dar.
        if ($this->ehRetornoDePortao($tarefa->status, $novoStatus) && ! $this->motivoPreenchido($dados)) {
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
     * remexida e reconcluída passava pelo portão apoiada no "aprovado" do ciclo
     * anterior — o teste que provava o código de antes valia como prova do
     * código de depois. O mesmo vale para a tarefa devolvida para correção: ela
     * reentra no staging sem herdar o carimbo da volta anterior.
     *
     * O recorte é o EVENTO da etapa atual, não a data dele. Por data, reabrir e
     * reconcluir dentro do mesmo segundo — o caso comum de quem está corrigindo
     * algo pequeno — deixava o relatório antigo passar de novo, porque
     * "criado depois da entrada na etapa" era verdade para os dois.
     *
     * Tarefa sem evento nenhum (nunca se moveu) compara nulo com nulo: também é
     * uma passagem, a primeira, e ela não tem de que se aproveitar.
     */
    private function aprovadaNestaPassagem(Tarefa $tarefa): bool
    {
        $etapaAtual = $tarefa->eventos()->whereNull('saiu_em')->latest('entrou_em')->value('id');

        $ultimo = $tarefa->relatoriosTeste()
            ->where('tarefa_evento_id', $etapaAtual)
            ->latest('id')
            ->first();

        return (bool) $ultimo?->aprovado;
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
