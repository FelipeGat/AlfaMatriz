<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A meta de vendas de UM vendedor em UMA competência.
 *
 * Só existe onde alguém a definiu — mês sem linha aqui é mês sem meta
 * lançada, não meta zero. É a mesma distinção que `IndicadoresService`
 * já faz entre "não fechou" e "fechou e deu zero" (`competenciaFoiFaturada`).
 */
class MetaComercial extends Model
{
    use Auditavel;

    protected string $recursoAuditoria = 'dashboard_comercial';

    protected $table = 'metas_comerciais';

    protected $fillable = ['vendedor_id', 'competencia', 'valor_meta'];

    protected function casts(): array
    {
        return ['valor_meta' => 'decimal:2'];
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }

    public function descricaoDeAuditoria(): string
    {
        return 'meta de '.($this->vendedor?->name ?? 'vendedor removido').' em '.$this->competencia;
    }
}
