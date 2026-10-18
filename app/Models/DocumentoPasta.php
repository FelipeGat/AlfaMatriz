<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma pasta do OneDrive espelhada pela máquina do dono
 * (docs/specs/documentos-espelhados). Quem vê: o dono e quem ele escolheu —
 * nunca por perfil, administrador inclusive (FR-001).
 */
class DocumentoPasta extends Model
{
    /** Leitura mais velha que isso aparece como desatualizada. */
    public const VELHO_HORAS = 2;

    protected $table = 'documento_pastas';

    protected $fillable = ['user_id', 'nome', 'maquina', 'lido_em', 'arquivos', 'bytes'];

    protected function casts(): array
    {
        return ['lido_em' => 'datetime'];
    }

    public function dono(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function pessoas(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'documento_pasta_acessos', 'pasta_id', 'user_id')->withTimestamps();
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(Documento::class, 'pasta_id');
    }

    /** As pastas que a pessoa pode ver: dela, ou compartilhadas com ela. */
    public function scopeVisiveisPara(Builder $query, User $user): Builder
    {
        return $query->where(fn ($q) => $q->where('user_id', $user->id)
            ->orWhereHas('pessoas', fn ($p) => $p->where('users.id', $user->id)));
    }

    public static function alguma(?User $user): bool
    {
        return $user !== null && static::visiveisPara($user)->exists();
    }

    public function ehDono(?User $user): bool
    {
        return $user !== null && $this->user_id === $user->id;
    }

    public function velha(): bool
    {
        return ! $this->lido_em || $this->lido_em->lessThan(now()->subHours(self::VELHO_HORAS));
    }
}
