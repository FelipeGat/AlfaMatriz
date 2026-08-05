<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Sistema extends Model
{
    protected $fillable = [
        'nome', 'slug', 'categoria', 'unidade_cobranca', 'base_url', 'token', 'ativo',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'token' => 'encrypted',
        ];
    }

    public function clientes(): BelongsToMany
    {
        return $this->belongsToMany(Cliente::class, 'cliente_sistema')
            ->withPivot(['ativo', 'ativado_em']);
    }

    public function precosAtacado(): HasMany
    {
        return $this->hasMany(PrecoAtacado::class);
    }

    /**
     * Tiers/planos de atacado vigentes para uma revenda (ou o padrão, se $revendaId for null),
     * já ordenados do mais barato para o mais caro.
     */
    public function tiersVigentes(?int $revendaId = null): Collection
    {
        $vigentes = $this->precosAtacado()
            ->where(function ($q) use ($revendaId) {
                $q->where('revenda_id', $revendaId)->orWhereNull('revenda_id');
            })
            ->whereDate('vigencia_inicio', '<=', now())
            ->where(function ($q) {
                $q->whereNull('vigencia_fim')->orWhereDate('vigencia_fim', '>=', now());
            })
            ->orderBy('ordem')
            ->get();

        // Se a revenda tem tiers próprios, eles sobrepõem os padrão (revenda_id = null) para o mesmo "nome".
        if ($revendaId) {
            $vigentes = $vigentes->unique(fn ($tier) => $tier->revenda_id ? $tier->nome : 'padrao-'.$tier->nome)
                ->sortBy('ordem')
                ->values();
        }

        return $vigentes;
    }

    public function precoVigente(?int $revendaId = null): ?PrecoAtacado
    {
        return $this->tiersVigentes($revendaId)->first();
    }

    /**
     * Encontra o tier mais barato que comporta a quantidade de unidades ativas informada.
     */
    public function tierParaVolume(int $unidadesAtivas, ?int $revendaId = null): ?PrecoAtacado
    {
        return $this->tiersVigentes($revendaId)
            ->first(fn (PrecoAtacado $tier) => $tier->comportaUnidades($unidadesAtivas));
    }
}
