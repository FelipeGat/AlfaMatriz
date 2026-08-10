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
        'capacidades', 'versao', 'responsavel', 'roadmap',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'token' => 'encrypted',
            'capacidades' => 'array',
        ];
    }

    /**
     * O sistema declara o que sabe fazer pelo contrato da Matriz, em vez de o
     * código deduzir isso do slug. Perguntar pela capacidade é o que permite
     * integrar um sistema novo sem tocar em controller nem em tela.
     */
    public function suporta(string $capacidade): bool
    {
        return in_array($capacidade, $this->capacidades ?? [], true);
    }

    /**
     * Tem tudo o que é preciso para a Matriz conversar com ele. Um sistema
     * cadastrado mas ainda sem endereço ou sem chave não é erro — é o estado
     * normal entre publicar a integração e configurá-la.
     */
    public function integravel(): bool
    {
        return (bool) $this->ativo && filled($this->base_url) && filled($this->token);
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<Sistema>  $query */
    public function scopeComCapacidade($query, string $capacidade)
    {
        return $query->whereJsonContains('capacidades', $capacidade);
    }

    public function clientes(): BelongsToMany
    {
        return $this->belongsToMany(Cliente::class, 'cliente_sistema')
            ->using(ClienteSistema::class)
            ->withPivot(['ativo', 'ativado_em', 'cancelado_em',
                'licenca_status', 'plano', 'licenca_inicio_em',
                'licenca_fim_em', 'bloqueia_acesso', 'licenca_id_externo',
                'status_saas']);
    }

    public function modulos(): HasMany
    {
        return $this->hasMany(Modulo::class);
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
