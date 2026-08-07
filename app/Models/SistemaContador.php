<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O retrato numérico de um sistema numa competência.
 *
 * É o que permite comparar meses: sem guardar por competência, o painel só
 * conseguiria responder "como está hoje", nunca "o que mudou".
 *
 * Não usa o comportamento de retrato comum porque contador não some na origem
 * — ele é recalculado a cada coleta.
 */
class SistemaContador extends Model
{
    use HasFactory;

    protected $table = 'sistema_contadores';

    protected $fillable = [
        'sistema_id', 'competencia', 'unidade_cobranca',
        'clientes_total', 'clientes_ativos', 'clientes_pendentes', 'clientes_bloqueados',
        'unidades_ativas', 'licencas_ativas', 'licencas_vencendo', 'licencas_vencidas',
        'faturado_no_sistema', 'por_revenda', 'coletado_em',
    ];

    protected function casts(): array
    {
        return [
            'clientes_total' => 'integer',
            'clientes_ativos' => 'integer',
            'clientes_pendentes' => 'integer',
            'clientes_bloqueados' => 'integer',
            'unidades_ativas' => 'integer',
            'licencas_ativas' => 'integer',
            'licencas_vencendo' => 'integer',
            'licencas_vencidas' => 'integer',
            'faturado_no_sistema' => 'decimal:2',
            'por_revenda' => 'array',
            'coletado_em' => 'datetime',
        ];
    }

    public function sistema(): BelongsTo
    {
        return $this->belongsTo(Sistema::class);
    }

    /** Quantas unidades o sistema atribui a uma revenda nesta competência. */
    public function unidadesDaRevenda(string $revendaIdExterno): ?int
    {
        foreach ($this->por_revenda ?? [] as $linha) {
            if ((string) ($linha['revenda_id_externo'] ?? '') === $revendaIdExterno) {
                return (int) ($linha['unidades_ativas'] ?? 0);
            }
        }

        return null;
    }
}
