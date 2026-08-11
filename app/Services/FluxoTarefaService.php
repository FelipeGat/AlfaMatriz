<?php

namespace App\Services;

use App\Models\Tarefa;
use App\Models\TarefaEvento;
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
            'em_desenvolvimento' => ['em_testes', 'bloqueada', 'backlog', 'cancelada'],
            // Destrava de volta para onde parou: quem bloqueou esperando o
            // cliente validar estava em Em testes, e devolver essa tarefa para
            // Em andamento diria que o código voltou para a bancada — não
            // voltou, ele só ficou esperando.
            'bloqueada' => ['em_desenvolvimento', 'em_testes', 'backlog', 'cancelada'],
            'em_testes' => ['concluida', 'ajustes_necessarios', 'em_desenvolvimento', 'bloqueada', 'cancelada'],
            'ajustes_necessarios' => ['em_desenvolvimento', 'bloqueada', 'cancelada'],
            'concluida' => ['em_desenvolvimento'],
            'cancelada' => ['aberta'],
        ],
        'operacional' => [
            'aberta' => ['backlog', 'cancelada'],
            'backlog' => ['aberta', 'em_desenvolvimento', 'cancelada'],
            'em_desenvolvimento' => ['concluida', 'bloqueada', 'backlog', 'cancelada'],
            'bloqueada' => ['em_desenvolvimento', 'backlog', 'cancelada'],
            // Etapas do ciclo de desenvolvimento: a tarefa operacional não
            // CHEGA nelas — nenhuma etapa dela oferece esse destino. Elas estão
            // aqui como saída de emergência para o caso de alguém trocar o tipo
            // de uma tarefa que já estava em teste: sem isso, o card ficaria
            // preso numa coluna sem nenhum caminho de volta.
            'em_testes' => ['em_desenvolvimento', 'concluida', 'cancelada'],
            'ajustes_necessarios' => ['em_desenvolvimento', 'cancelada'],
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
     * Move a tarefa para o novo status, recusando transições fora do fluxo e
     * cobrando o que cada uma exige, e registra a mudança de etapa.
     *
     * @param  array{motivo?: ?string}  $dados
     */
    public function mover(Tarefa $tarefa, string $novoStatus, array $dados = []): Tarefa
    {
        $statusAtual = $tarefa->status;

        $this->assertTransicaoPermitida($tarefa, $novoStatus);
        $this->assertExigenciasAtendidas($tarefa, $novoStatus, $dados);

        return DB::transaction(function () use ($tarefa, $statusAtual, $novoStatus, $dados) {
            $agora = now();

            $this->fecharEventoAberto($tarefa, $agora);

            $atualizacao = ['status' => $novoStatus];

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

            TarefaEvento::create([
                'tarefa_id' => $tarefa->id,
                'de_status' => $statusAtual,
                'para_status' => $novoStatus,
                'motivo' => $dados['motivo'] ?? null,
                'entrou_em' => $agora,
            ]);

            return $tarefa->refresh();
        });
    }

    private function assertTransicaoPermitida(Tarefa $tarefa, string $novoStatus): void
    {
        if (! in_array($novoStatus, self::transicoesDe($tarefa), true)) {
            $origem = Tarefa::STATUS[$tarefa->status] ?? $tarefa->status;
            $destino = Tarefa::STATUS[$novoStatus] ?? $novoStatus;

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

        // Parar sem dizer por quê seria trocar um card apodrecendo em Em
        // andamento por um card apodrecendo em Bloqueada. O motivo é o que
        // permite a alguém destravar a tarefa depois — e é ele que responde a
        // pergunta que a etapa existe para fazer: esperando o quê, e de quem.
        if ($novoStatus === 'bloqueada' && ! $this->motivoPreenchido($dados)) {
            throw new \RuntimeException('É preciso dizer o que está travando a tarefa.');
        }

        if ($novoStatus === 'ajustes_necessarios' && ! $this->motivoPreenchido($dados)) {
            throw new \RuntimeException('É preciso descrever o que precisa ser corrigido.');
        }

        if ($novoStatus === 'cancelada' && ! $this->motivoPreenchido($dados)) {
            throw new \RuntimeException('O motivo do cancelamento é obrigatório.');
        }

        if ($novoStatus === 'concluida'
            && $tarefa->tipo === 'desenvolvimento'
            && ! $this->aprovadaNestaPassagem($tarefa)) {
            throw new \RuntimeException('Só é possível concluir depois de um relatório de teste aprovado.');
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
     * O teste desta passagem por Em testes foi aprovado?
     *
     * "Desta passagem" é a correção de um vazamento: a checagem lia o último
     * relatório da tarefa INTEIRA, então uma tarefa concluída, reaberta,
     * remexida e reconcluída passava pelo portão apoiada no "aprovado" do ciclo
     * anterior — o teste que provava o código de antes valia como prova do
     * código de depois. O mesmo valia para a tarefa que voltou de Ajustes
     * necessários: ela reentrava em Em testes já aprovada.
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
