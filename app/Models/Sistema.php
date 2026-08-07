<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
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
        'sincronizado_em', 'falhas_consecutivas', 'importado_em', 'cadastro_na_matriz_desde',
    ];

    /**
     * O banco já tem o padrão zero, mas ele só vale depois de reler o registro.
     * Sem o padrão aqui, o contador chega nulo na instância recém-criada — e o
     * serviço de sincronização soma em cima dele.
     */
    protected $attributes = [
        'falhas_consecutivas' => 0,
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'token' => 'encrypted',
            'sincronizado_em' => 'datetime',
            'importado_em' => 'datetime',
            'cadastro_na_matriz_desde' => 'datetime',
            'falhas_consecutivas' => 'integer',
        ];
    }

    /**
     * Por que este sistema não pode ser sincronizado agora — ou null se pode.
     *
     * Devolve um motivo nomeado, e não um booleano, porque a tela precisa
     * dizer O QUE falta. "Não foi possível sincronizar" manda a pessoa
     * adivinhar entre quatro causas diferentes.
     */
    public function motivoIntegracaoIndisponivel(): ?string
    {
        if (! $this->ativo) {
            return 'sistema_inativo';
        }

        // Só os produtos vendidos como serviço entram na integração. É este
        // filtro que mantém o Gestor (categoria crm) de fora.
        if ($this->categoria !== 'saas') {
            return 'fora_do_escopo';
        }

        if (blank($this->base_url)) {
            return 'sem_endereco';
        }

        // A leitura da chave precisa vir protegida: o valor é cifrado, e se a
        // chave da aplicação for trocada no servidor TODAS as chaves de
        // integração ficam ilegíveis de uma vez. Sem isto, a tela do painel
        // quebraria com uma exceção de decifragem em vez de dizer o que houve.
        if ($this->chaveIlegivel()) {
            return 'chave_ilegivel';
        }

        if (blank($this->chaveDeIntegracao())) {
            return 'sem_chave';
        }

        return null;
    }

    /** A chave em claro, ou null quando não há chave ou ela não decifra. */
    public function chaveDeIntegracao(): ?string
    {
        try {
            return $this->token;
        } catch (DecryptException) {
            return null;
        }
    }

    /** Existe algo guardado como chave, mas não é possível lê-lo. */
    public function chaveIlegivel(): bool
    {
        if (blank($this->getRawOriginal('token'))) {
            return false;
        }

        try {
            $this->token;

            return false;
        } catch (DecryptException) {
            return true;
        }
    }

    public function integracaoConfigurada(): bool
    {
        return $this->motivoIntegracaoIndisponivel() === null;
    }

    /** A matriz já é dona do cadastro deste sistema? */
    public function cadastroNaMatriz(): bool
    {
        return $this->cadastro_na_matriz_desde !== null;
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
