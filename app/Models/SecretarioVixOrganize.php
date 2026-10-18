<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A conexão da conta com o VixOrganize, a fila de projetos da VixCard
 * (docs/specs/secretario-vixorganize). A chave nunca sai cifrada para a tela.
 */
class SecretarioVixOrganize extends Model
{
    /** Retrato mais velho que isso aparece como desatualizado (FR-007). */
    public const VELHO_MINUTOS = 30;

    protected $table = 'secretario_vixorganize';

    protected $fillable = ['user_id', 'url', 'chave', 'autor_email', 'tarefa_id', 'retrato', 'lido_em', 'erro', 'erro_em'];

    protected $hidden = ['chave'];

    protected function casts(): array
    {
        return ['chave' => 'encrypted', 'retrato' => 'array', 'lido_em' => 'datetime', 'erro_em' => 'datetime'];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeDe(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public static function daConta(?User $user): ?self
    {
        return $user ? static::de($user)->first() : null;
    }

    public function velho(): bool
    {
        return ! $this->lido_em || $this->lido_em->lessThan(now()->subMinutes(self::VELHO_MINUTOS));
    }

    /** @return list<array> os projetos do retrato, na ordem da fila */
    public function projetos(): array
    {
        return collect($this->retrato['projetos'] ?? [])->sortBy('rank')->values()->all();
    }

    public function projeto(string $slug): ?array
    {
        return collect($this->retrato['projetos'] ?? [])->firstWhere('slug', $slug);
    }

    /** O link do projeto no próprio VixOrganize. */
    public function linkDoProjeto(string $slug): string
    {
        return rtrim($this->url, '/').'/projetos/'.rawurlencode($slug);
    }
}
