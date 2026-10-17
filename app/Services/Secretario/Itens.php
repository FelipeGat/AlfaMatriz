<?php

namespace App\Services\Secretario;

use App\Models\SecretarioAviso;
use App\Models\SecretarioItem;
use App\Models\User;
use App\Services\CentralIa\Eventos;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * As ações rápidas (Concluir, +15 min, +1 h, Amanhã, Cancelar), iguais no
 * Telegram e na tela. Trava a linha do item: concluir enquanto o despacho
 * entrega não deixa o item concluído ser avisado de novo (FR-027).
 */
class Itens
{
    public const ACOES = ['concluir', 'adiar_15', 'adiar_60', 'amanha', 'cancelar', 'cancelar_esta', 'cancelar_todas'];

    public function __construct(private Despachante $despachante) {}

    /**
     * @return array{item: SecretarioItem, encerrado: bool, mensagem: string, curto: string}
     */
    public function agir(User $user, SecretarioItem $item, string $acao, ?SecretarioAviso $aviso = null): array
    {
        abort_unless(in_array($acao, self::ACOES, true), 422);

        return DB::transaction(function () use ($user, $item, $acao, $aviso) {
            $i = SecretarioItem::de($user)->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($i->encerrado()) {
                $msg = $i->status === SecretarioItem::CONCLUIDO ? 'Já estava concluído.' : 'Já estava cancelado.';

                return ['item' => $i, 'encerrado' => true, 'mensagem' => $msg, 'curto' => $msg];
            }
            $agora = CarbonImmutable::now()->startOfMinute();
            $recorrente = (bool) $i->recorrencia;

            switch ($acao) {
                case 'concluir':
                    if ($recorrente) {
                        // A ocorrência foi feita; o item continua para as próximas.
                        $this->avancar($i, $agora);
                        $r = ['✓ Feito. Próxima: '.Datas::porExtenso($i->quando, $i->so_data).'.', 'Feito.'];
                    } else {
                        $i->update(['status' => SecretarioItem::CONCLUIDO, 'concluido_em' => now()]);
                        $this->despachante->programar($i);
                        $r = ['✓ Concluído às '.now()->format('H:i').'.', 'Concluído.'];
                    }
                    break;

                case 'adiar_15':
                case 'adiar_60':
                    $para = $agora->addMinutes($acao === 'adiar_15' ? 15 : 60);
                    if ($recorrente || $i->tipo === 'compromisso') {
                        // Adia o AVISO, não o compromisso nem a série: a
                        // reunião continua na hora dela; só lembra de novo.
                        $this->despachante->avisoExtra($i, $para);
                    } else {
                        $i->update(['quando' => $para, 'so_data' => false]);
                        $this->despachante->programar($i);
                    }
                    $r = ['⏰ Adiado para '.$para->format('H:i').'.', 'Adiado para '.$para->format('H:i').'.'];
                    break;

                case 'amanha':
                    $base = $i->quando ? CarbonImmutable::instance($i->quando) : $agora;
                    $novo = $base->lessThan($agora) ? $agora->addDay()->setTime($base->hour, $base->minute) : $base->addDay();
                    if ($recorrente) {
                        $this->despachante->avisoExtra($i, $novo);
                    } else {
                        $i->update(['quando' => $novo]);
                        $this->despachante->programar($i);
                    }
                    $r = ['⏰ Fica para '.Datas::porExtenso($novo, $i->so_data).'.', 'Amanhã, '.$novo->format('H:i').'.'];
                    break;

                case 'cancelar_esta':
                    if ($recorrente) {
                        $this->avancar($i, $agora);
                        $r = ['Esta ocorrência foi cancelada. Próxima: '.Datas::porExtenso($i->quando, $i->so_data).'.', 'Cancelada esta.'];
                        break;
                    }
                    // Sem recorrência, "só esta" é o item inteiro.
                case 'cancelar':
                case 'cancelar_todas':
                    $i->update(['status' => SecretarioItem::CANCELADO, 'cancelado_em' => now()]);
                    $this->despachante->programar($i);
                    $r = ['✕ Cancelado.', 'Cancelado.'];
                    break;
            }

            // Rastro sem o título (FR-005).
            Eventos::registrar($user, 'secretario_'.$acao, "Item #{$i->id}: {$acao}".($aviso ? " (aviso #{$aviso->id})" : '').'.', ['origem' => $aviso ? 'telegram' : 'web']);

            return ['item' => $i->fresh(), 'encerrado' => false, 'mensagem' => $r[0], 'curto' => $r[1]];
        });
    }

    /** Novo "quando" (reagendar): só data e hora mudam; a série continua. */
    public function reagendar(User $user, SecretarioItem $item, array $quando): array
    {
        return DB::transaction(function () use ($user, $item, $quando) {
            $i = SecretarioItem::de($user)->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($i->encerrado()) {
                return ['item' => $i, 'encerrado' => true, 'mensagem' => 'Esse item já está encerrado.'];
            }
            $i->update(['quando' => $quando['quando'], 'so_data' => $quando['so_data']]);
            if ($i->recorrencia && ! $quando['so_data']) {
                $i->update(['recorrencia' => ['hora' => CarbonImmutable::parse($quando['quando'])->format('H:i')] + $i->recorrencia]);
            }
            $this->despachante->programar($i);
            Eventos::registrar($user, 'secretario_reagendar', "Item #{$i->id}: reagendado.", ['origem' => 'web']);

            return ['item' => $i->fresh(), 'encerrado' => false, 'mensagem' => '🗓 Reagendado para '.Datas::porExtenso($i->quando, $i->so_data).'.'];
        });
    }

    private function avancar(SecretarioItem $i, CarbonImmutable $agora): void
    {
        $hora = $i->recorrencia['hora'] ?? $i->quando?->format('H:i') ?? '08:00';
        // A partir da ocorrência atual, se ela ainda está no futuro: concluir
        // antes da hora pula para a seguinte, e não para a mesma.
        $desde = $i->quando && $i->quando->greaterThan($agora) ? CarbonImmutable::instance($i->quando) : $agora;
        $proxima = Recorrencia::proxima($i->recorrencia, $desde, $hora);
        if ($proxima) {
            $i->update(['quando' => $proxima]);
            $this->despachante->programar($i);
        }
    }
}
