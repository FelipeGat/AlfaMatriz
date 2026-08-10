<?php

namespace App\Services;

use App\Models\Tarefa;
use App\Models\TarefaEvento;
use Illuminate\Support\Facades\DB;

/**
 * O motor do fluxo do quadro de desenvolvimento: só deixa a tarefa se mover
 * entre etapas que o mapa abaixo permite, cobra o que cada transição exige
 * (responsável, motivo, relatório de teste aprovado) e fecha o evento de
 * etapa aberto e abre o próximo a cada mudança — é o tempo por etapa (US-038)
 * sendo registrado.
 */
class FluxoTarefaService
{
    /** Para onde cada etapa pode ir; cancelada é terminal e não sai de lugar nenhum. */
    public const TRANSICOES_PERMITIDAS = [
        'aberta' => ['backlog', 'cancelada'],
        'backlog' => ['em_desenvolvimento', 'cancelada'],
        'em_desenvolvimento' => ['em_testes', 'cancelada'],
        'em_testes' => ['concluida', 'ajustes_necessarios', 'cancelada'],
        'ajustes_necessarios' => ['em_desenvolvimento', 'cancelada'],
        'concluida' => ['em_desenvolvimento'],
        'cancelada' => [],
    ];

    /**
     * Move a tarefa para o novo status, recusando transições fora do fluxo e
     * cobrando o que cada uma exige, e registra a mudança de etapa.
     *
     * @param  array{motivo?: ?string}  $dados
     */
    public function mover(Tarefa $tarefa, string $novoStatus, array $dados = []): Tarefa
    {
        $statusAtual = $tarefa->status;

        $this->assertTransicaoPermitida($statusAtual, $novoStatus);
        $this->assertExigenciasAtendidas($tarefa, $novoStatus, $dados);

        return DB::transaction(function () use ($tarefa, $statusAtual, $novoStatus, $dados) {
            $agora = now();

            $this->fecharEventoAberto($tarefa, $agora);

            $tarefa->update(['status' => $novoStatus]);

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

    private function assertTransicaoPermitida(string $statusAtual, string $novoStatus): void
    {
        $permitidas = self::TRANSICOES_PERMITIDAS[$statusAtual] ?? [];

        if (! in_array($novoStatus, $permitidas, true)) {
            $origem = Tarefa::STATUS[$statusAtual] ?? $statusAtual;
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

        if ($novoStatus === 'ajustes_necessarios' && ! $this->motivoPreenchido($dados)) {
            throw new \RuntimeException('É preciso descrever o que precisa ser corrigido.');
        }

        if ($novoStatus === 'cancelada' && ! $this->motivoPreenchido($dados)) {
            throw new \RuntimeException('O motivo do cancelamento é obrigatório.');
        }

        if ($novoStatus === 'concluida' && ! $this->ultimoRelatorioAprovado($tarefa)) {
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

    private function ultimoRelatorioAprovado(Tarefa $tarefa): bool
    {
        $ultimo = $tarefa->relatoriosTeste()->latest('id')->first();

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
