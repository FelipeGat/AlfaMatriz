<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Sistema extends Model
{
    use HasFactory;

    protected $fillable = [
        'nome', 'slug', 'categoria', 'unidade_cobranca', 'base_url', 'token', 'ativo',
        'versao', 'responsavel', 'roadmap',
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
            ->withPivot(['ativo', 'ativado_em', 'cancelado_em']);
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

    /**
     * MRR estimado hoje: soma o tier aplicável de cada revenda (+ diretos) que
     * usa este sistema, dado o nº de clientes ativos que cada uma tem nele.
     */
    public function mrrEstimado(): float
    {
        $porRevenda = $this->clientes()
            ->where('clientes.ativo', true)
            ->where('cliente_sistema.ativo', true)
            ->get(['clientes.id', 'clientes.revenda_id'])
            ->groupBy('revenda_id');

        $total = 0;
        foreach ($porRevenda as $revendaId => $clientes) {
            $qtd = $clientes->count();
            // `groupBy` devolve a chave como texto, e o cliente de venda
            // direta (sem revenda) vira a chave vazia — que não é null. Sem
            // esta normalização, uma única venda direta derruba o cálculo.
            $tier = $this->tierParaVolume($qtd, $this->chaveDeRevenda($revendaId));
            $total += $tier?->calcularMensalidade($qtd) ?? 0;
        }

        return $total;
    }

    /**
     * Normaliza a chave de agrupamento por revenda: venda direta chega como
     * string vazia (ou null) e precisa virar null de verdade.
     */
    public function chaveDeRevenda(int|string|null $chave): ?int
    {
        return ($chave === null || $chave === '') ? null : (int) $chave;
    }

    public function clientesAtivosCount(): int
    {
        return $this->clientes()
            ->where('clientes.ativo', true)
            ->where('cliente_sistema.ativo', true)
            ->count();
    }

    public function clientesCanceladosCount(): int
    {
        return $this->clientes()
            ->where('cliente_sistema.ativo', false)
            ->count();
    }
}
