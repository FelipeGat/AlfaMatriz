<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma notificação do AlfaMonitor como chegou (#394): o histórico da tela.
 *
 * Guardada por `event_id` (UNIQUE), o número da notificação no monitor — o
 * mesmo em todas as novas tentativas. É ele, e não a hora, que diz "esta já
 * chegou": o `at` do contrato é a hora do ENVIO e muda a cada tentativa.
 */
class MonitorEvento extends Model
{
    use MassPrunable;

    /**
     * Quanto tempo o histórico guarda. O estado de agora mora no
     * `MonitorAlerta` e não depende dele; 90 dias cobrem "de quanto em quanto
     * isso cai" sem a tabela crescer sem fim com lembrete de 4 em 4 horas.
     */
    public const DIAS_DE_HISTORICO = 90;

    protected $table = 'monitor_eventos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'resolved' => 'boolean',
            'derived' => 'boolean',
            'aplicado' => 'boolean',
            'ocorreu_em' => 'datetime',
            'enviado_em' => 'datetime',
        ];
    }

    public function alerta(): BelongsTo
    {
        return $this->belongsTo(MonitorAlerta::class, 'monitor_alerta_id');
    }

    public function prunable(): Builder
    {
        return static::where('ocorreu_em', '<', now()->subDays(self::DIAS_DE_HISTORICO)->format('Y-m-d H:i:s'));
    }

    /** Como a linha do histórico chama o evento. */
    public function rotulo(): string
    {
        return match (true) {
            $this->derived => 'Lembrete',
            $this->resolved => 'Voltou',
            default => 'Caiu',
        };
    }
}
