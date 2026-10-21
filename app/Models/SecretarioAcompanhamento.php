<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Uma situação que pede acompanhamento (docs/specs/secretario-acompanhamento).
 * A chave é estável (`tarefa_vencida:item:12`) e única por conta: a mesma
 * situação é sempre a mesma linha, e é isso que segura a duplicata.
 */
class SecretarioAcompanhamento extends Model
{
    public const ATIVO = 'ativo';
    public const RESOLVIDO = 'resolvido';
    public const DISPENSADO = 'dispensado';

    public const PRIORIDADES = ['alta', 'normal', 'baixa'];

    protected $table = 'secretario_acompanhamentos';

    protected $attributes = ['situacao' => self::ATIVO, 'avisos' => 0];

    protected $fillable = ['user_id', 'regra', 'chave', 'prioridade', 'texto', 'link', 'referencia_em', 'situacao', 'adiado_ate',
        'avisos', 'avisado_em', 'resolvido_em'];

    protected function casts(): array
    {
        return ['referencia_em' => 'datetime', 'adiado_ate' => 'datetime', 'avisado_em' => 'datetime', 'resolvido_em' => 'datetime'];
    }

    public function scopeDe(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /** Os que aparecem: ativos, alta antes de normal antes de baixa, os mais antigos primeiro. */
    public function scopeAtivos(Builder $query): Builder
    {
        return $query->where('situacao', self::ATIVO)
            ->orderByRaw("case prioridade when 'alta' then 0 when 'normal' then 1 else 2 end")
            ->orderByRaw('referencia_em is null')->orderBy('referencia_em')->orderBy('id');
    }

    public function adiado(): bool
    {
        return $this->adiado_ate !== null && $this->adiado_ate->greaterThan(now());
    }

    public function marca(): string
    {
        return ['alta' => '🔴', 'normal' => '🟡', 'baixa' => '⚪'][$this->prioridade] ?? '•';
    }
}
