<?php

namespace App\Models;

use App\Models\Concerns\EspelhaSistema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A licença de um cliente DENTRO de um sistema.
 *
 * Sistema sem entidade de licença própria (que guarda a vigência em campos do
 * cliente) manda uma linha derivada, com identificador "cliente:{id}".
 */
class SistemaLicenca extends Model
{
    use EspelhaSistema, HasFactory;

    protected $table = 'sistema_licencas';

    protected $fillable = [
        'sistema_id', 'sistema_cliente_id', 'id_externo', 'status',
        'plano', 'plano_id_externo', 'tipo', 'inicio_em', 'fim_em',
        'bloqueia_acesso', 'liberada_por', 'liberada_em',
        'ausente_em_origem_em', 'payload', 'sincronizado_em',
    ];

    protected $attributes = [
        'status' => 'pendente',
        'bloqueia_acesso' => false,
    ];

    protected function casts(): array
    {
        return [
            'inicio_em' => 'date',
            'fim_em' => 'date',
            'bloqueia_acesso' => 'boolean',
            'liberada_em' => 'datetime',
            'payload' => 'array',
            'ausente_em_origem_em' => 'datetime',
            'sincronizado_em' => 'datetime',
        ];
    }

    public function sistemaCliente(): BelongsTo
    {
        return $this->belongsTo(SistemaCliente::class);
    }

    /**
     * Dias até vencer. Negativo quando já venceu, nulo quando não expira.
     *
     * Calculado, e não guardado: o valor que o sistema mandou envelhece no
     * retrato local, e uma licença "vencendo em 3 dias" gravada há uma semana
     * mentiria na tela.
     */
    public function diasParaVencer(): ?int
    {
        // A saída antecipada não é estilo: com o operador de acesso seguro, o
        // nulo escapa da cadeia mas a aritmética depois dele o transforma em
        // zero — e "sem data de fim" viraria "vence hoje" na tela.
        if ($this->fim_em === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->fim_em->startOfDay(), false);
    }

    public function vencida(): bool
    {
        return $this->fim_em !== null && $this->fim_em->startOfDay()->isPast();
    }

    public function scopeVencendoEm(Builder $consulta, int $dias): Builder
    {
        return $consulta->whereNotNull('fim_em')
            ->whereDate('fim_em', '>=', now()->toDateString())
            ->whereDate('fim_em', '<=', now()->addDays($dias)->toDateString());
    }

    public function scopeVencidas(Builder $consulta): Builder
    {
        return $consulta->whereNotNull('fim_em')->whereDate('fim_em', '<', now()->toDateString());
    }
}
