<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'primeiro_acesso',
        'ativo',
        'revenda_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'primeiro_acesso' => 'boolean',
            'ativo' => 'boolean',
        ];
    }

    public function perfis(): BelongsToMany
    {
        return $this->belongsToMany(Perfil::class, 'perfil_user');
    }

    public function revenda(): BelongsTo
    {
        return $this->belongsTo(Revenda::class);
    }

    /**
     * Usuário da matriz com acesso irrestrito (revenda_id null) ou de uma
     * revenda específica (só enxerga o próprio portfólio).
     */
    public function temEscopoDeRevenda(): bool
    {
        return $this->revenda_id !== null;
    }

    /**
     * Aplica o filtro por revenda numa query quando o usuário tem escopo
     * restrito. `$campo` aponta a coluna da revenda na query (default:
     * a própria tabela de revendas).
     */
    public function escopoDeRevenda($query, string $campo = 'revenda_id')
    {
        if ($this->temEscopoDeRevenda()) {
            $query->where($campo, $this->revenda_id);
        }

        return $query;
    }

    public function canPermissao(string $recurso, string $acao): bool
    {
        return $this->perfis()
            ->whereHas('permissoes', function ($q) use ($recurso, $acao) {
                $q->where('recurso', $recurso)->where("perfil_permissao.{$acao}", true);
            })
            ->exists();
    }
}
