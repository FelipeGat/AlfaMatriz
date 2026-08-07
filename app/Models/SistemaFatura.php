<?php

namespace App\Models;

use App\Models\Concerns\EspelhaSistema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O que um sistema cobra de um cliente pela licença, numa competência.
 *
 * NÃO é o financeiro interno do produto (mensalidade de aluno, conta a receber
 * de condômino): esse fica fora do contrato de propósito.
 */
class SistemaFatura extends Model
{
    use EspelhaSistema, HasFactory;

    protected $table = 'sistema_faturas';

    protected $fillable = [
        'sistema_id', 'sistema_cliente_id', 'sistema_revenda_id', 'id_externo',
        'competencia', 'valor', 'moeda', 'status', 'vencimento_em', 'pago_em',
        'dias_em_atraso', 'unidades_cobradas', 'plano', 'licenca_id_externo', 'origem',
        'ausente_em_origem_em', 'payload', 'sincronizado_em',
    ];

    protected $attributes = [
        'valor' => 0,
        'moeda' => 'BRL',
        'status' => 'aberto',
        'dias_em_atraso' => 0,
        'unidades_cobradas' => 0,
        'origem' => 'titulo',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'dias_em_atraso' => 'integer',
            'unidades_cobradas' => 'integer',
            'vencimento_em' => 'date',
            'pago_em' => 'date',
            'payload' => 'array',
            'ausente_em_origem_em' => 'datetime',
            'sincronizado_em' => 'datetime',
        ];
    }

    public function sistemaCliente(): BelongsTo
    {
        return $this->belongsTo(SistemaCliente::class);
    }

    public function sistemaRevenda(): BelongsTo
    {
        return $this->belongsTo(SistemaRevenda::class);
    }

    /**
     * A linha foi inferida da licença porque o sistema não tem título próprio.
     *
     * A tela de divergências ignora estas na comparação de valor: acusar
     * diferença contra um número que o próprio sistema não considera oficial
     * seria falso alarme, e falso alarme faz a tela inteira ser ignorada.
     */
    public function ehDerivada(): bool
    {
        return $this->origem === 'derivado';
    }

    public function scopeDaCompetencia(Builder $consulta, string $competencia): Builder
    {
        return $consulta->where('competencia', $competencia);
    }

    public function scopeEmAtraso(Builder $consulta): Builder
    {
        return $consulta->whereIn('status', ['aberto', 'vencido'])->where('dias_em_atraso', '>', 0);
    }
}
