<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Sistema extends Model
{
    use Auditavel, HasFactory;

    protected string $recursoAuditoria = 'sistemas';

    /**
     * O que a linha É.
     *
     * `produto` é o que a Alfa vende: tem cliente, tier de atacado, MRR e entra
     * no fechamento. `interno` é o que a Alfa USA para trabalhar — a própria
     * Matriz, a infra, o site. Ele existe para a tarefa ter onde apontar, e por
     * isso não aparece em nenhuma tela que fale de dinheiro.
     *
     * A distinção não é a mesma de `ativo`. Produto desativado é produto que
     * saiu do catálogo: ele vale zero, mas o histórico dele é comercial.
     * Sistema interno nunca foi comercial — somá-lo a zero no ticket médio
     * seria dividir a receita por uma população maior do que a que a gerou.
     */
    public const NATUREZAS = [
        'produto' => 'Produto',
        'interno' => 'Sistema interno',
    ];

    protected $fillable = [
        'nome', 'slug', 'natureza', 'categoria', 'unidade_cobranca', 'base_url', 'token', 'ativo',
        'capacidades', 'versao', 'responsavel', 'data_cadastro',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'token' => 'encrypted',
            'capacidades' => 'array',
            'data_cadastro' => 'date',
            // Fora do `$fillable`, como o bloqueio da tarefa: a queda de
            // sincronização é marca com regra própria (o sincronizador a
            // escreve na transição), e o formulário de cadastro não deveria
            // conseguir derrubar ou levantar um sistema de passagem.
            'sincronizacao_caiu_em' => 'datetime',
        ];
    }

    /**
     * O dia em que o produto entrou no catálogo — mesma regra de
     * `Cliente::data_entrada`, pelo mesmo motivo.
     */
    public function getDataEntradaAttribute(): ?\Illuminate\Support\Carbon
    {
        return $this->data_cadastro ?? $this->created_at;
    }

    /** A mesma regra em SQL, para filtro e ordenação. */
    public static function expressaoDeEntrada(): string
    {
        return 'COALESCE(sistemas.data_cadastro, DATE(sistemas.created_at))';
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

    /**
     * O catálogo comercializado — a população de toda tela que fala de receita.
     *
     * É escopo, e não um `where` repetido em cada controller, porque a pergunta
     * é uma só e aparece em uma dúzia de lugares: esquecida em UM deles, um
     * sistema interno entra numa conta de dinheiro valendo zero e puxa para
     * baixo o ticket médio, a participação e o preço médio de todo mundo — sem
     * que nada tenha mudado de preço.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Sistema>  $query
     */
    public function scopeProdutos($query)
    {
        return $query->where('natureza', 'produto');
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<Sistema>  $query */
    public function scopeInternos($query)
    {
        return $query->where('natureza', 'interno');
    }

    public function ehInterno(): bool
    {
        return $this->natureza === 'interno';
    }

    /**
     * Pode virar produto, ou deixar de ser?
     *
     * Só enquanto ninguém depende da resposta comercial. Um sistema com cliente
     * vinculado ou com tier configurado já está no fechamento; transformá-lo em
     * interno o tiraria da fatura em silêncio, e a revenda descobriria pelo
     * boleto que não veio.
     */
    public function podeTrocarDeNatureza(): bool
    {
        return ! $this->clientes()->exists() && ! $this->precosAtacado()->exists();
    }

    public function clientes(): BelongsToMany
    {
        return $this->belongsToMany(Cliente::class, 'cliente_sistema')
            ->using(ClienteSistema::class)
            ->withPivot(['ativo', 'ativado_em', 'cancelado_em',
                'licenca_status', 'plano', 'licenca_valor', 'licenca_inicio_em',
                'licenca_fim_em', 'bloqueia_acesso', 'licenca_id_externo',
                'status_saas', 'uso_unidades', 'uso_metricas', 'uso_medido_em']);
    }

    public function modulos(): HasMany
    {
        return $this->hasMany(Modulo::class);
    }

    /**
     * O trabalho aberto contra este sistema.
     *
     * É a única métrica que um sistema interno tem — ele não tem cliente, nem
     * MRR, nem churn. A lista de internos se sustenta nela: sem esse número,
     * seriam nomes sem nenhum sinal de vida.
     */
    public function tarefas(): HasMany
    {
        return $this->hasMany(Tarefa::class);
    }

    public function precosAtacado(): HasMany
    {
        return $this->hasMany(PrecoAtacado::class);
    }

    /**
     * Os clientes que este sistema realmente cobra: cliente ativo com vínculo
     * ativo — a população que o tier conta e que os módulos seguem.
     *
     * Da relação carregada quando ela veio junto, do banco quando não. Sem
     * isso, quem varre o catálogo pergunta os clientes de cada sistema duas
     * vezes: uma para a licença e outra para os módulos.
     *
     * @return \Illuminate\Support\Collection<int, Cliente>
     */
    public function clientesComVinculoAtivo(): \Illuminate\Support\Collection
    {
        if ($this->relationLoaded('clientes')) {
            return $this->clientes
                ->filter(fn (Cliente $cliente) => $cliente->ativo && $cliente->pivot->ativo)
                ->values();
        }

        return $this->clientes()
            ->where('clientes.ativo', true)
            ->where('cliente_sistema.ativo', true)
            ->get(['clientes.id', 'clientes.revenda_id']);
    }

    /**
     * Tiers/planos de atacado vigentes para uma revenda (ou o padrão, se $revendaId for null),
     * já ordenados do mais barato para o mais caro.
     */
    public function tiersVigentes(?int $revendaId = null): Collection
    {
        // Da relação já carregada quando ela veio junto, do banco quando não.
        //
        // Quem varre o catálogo inteiro — o ranking do Comercial e a tela de
        // Produtos — pergunta os tiers de cada sistema, e uma vez por revenda
        // que usa cada sistema. Eram dezenas de consultas idênticas em recorte,
        // e a lista de tiers de um produto cabe folgada na memória.
        $vigentes = $this->relationLoaded('precosAtacado')
            ? $this->precosAtacado
                ->filter(fn (PrecoAtacado $tier) => $tier->revenda_id === $revendaId || $tier->revenda_id === null)
                // `whereDate` compara só a DATA, e o cast do modelo entrega
                // Carbon no início do dia: comparar contra `today()` é a mesma
                // pergunta que o banco respondia.
                ->filter(fn (PrecoAtacado $tier) => $tier->vigencia_inicio === null
                    || $tier->vigencia_inicio->lessThanOrEqualTo(today()))
                ->filter(fn (PrecoAtacado $tier) => $tier->vigencia_fim === null
                    || $tier->vigencia_fim->greaterThanOrEqualTo(today()))
                ->sortBy('ordem')
                ->values()
            : $this->precosAtacado()
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
     * O recorrente de MÓDULOS deste sistema na competência.
     *
     * Módulo é cobrado à parte da licença e entra na fatura como segunda
     * parcela da mesma linha. Some só de cliente ativo com vínculo ativo — a
     * mesma população que o tier conta.
     */
    public function mrrModulos(?string $competencia = null): float
    {
        // Sistema desativado não gera receita: o fechamento pula ele
        // (`FaturamentoService` só varre `ativo`), então nada dele é cobrado.
        // Sem esta guarda, o painel somava no MRR um dinheiro que a fatura
        // nunca ia produzir.
        if (! $this->ativo) {
            return 0.0;
        }

        $clienteIds = $this->clientesComVinculoAtivo()->pluck('id');
        $competencia ??= now()->format('Y-m');

        // Com os módulos e as contratações já carregados, a soma sai da memória
        // — é o caminho de quem varre o catálogo inteiro. A regra de vigência é
        // a MESMA de `ClienteModulo::vigentesNaCompetencia()`, e precisa
        // continuar sendo: é ela que a fatura aplica, e as duas divergirem faz
        // a prévia mentir sobre o que vai ser cobrado.
        if ($this->relationLoaded('modulos') && $this->modulos->every->relationLoaded('contratacoes')) {
            $inicioDoMes = \Illuminate\Support\Carbon::createFromFormat('Y-m', $competencia)->startOfMonth();

            return (float) $this->modulos
                ->flatMap->contratacoes
                ->filter(fn (ClienteModulo $c) => $c->status === 'ativo')
                ->filter(fn (ClienteModulo $c) => $clienteIds->contains($c->cliente_id))
                ->filter(fn (ClienteModulo $c) => $c->vigenteEm($inicioDoMes))
                ->sum('valor_mensal');
        }

        return (float) ClienteModulo::vigentesNaCompetencia(
            $this->id,
            $clienteIds,
            $competencia
        )->sum('valor_mensal');
    }

    /**
     * MRR de LICENÇA estimado hoje: soma o tier aplicável de cada revenda (+
     * diretos) que usa este sistema, dado o nº de clientes ativos que cada uma
     * tem nele.
     *
     * NÃO inclui módulos — quem quer o recorrente inteiro soma `mrrModulos()`.
     * Ficam separados porque as duas telas que mostram o número também mostram
     * a parcela de módulos à parte, e um método que junta as duas escondia
     * justamente a divisão que elas precisam exibir.
     */
    public function mrrEstimado(): float
    {
        return (float) $this->mrrPorRevenda()->sum();
    }

    /**
     * O MRR de licença aberto por revenda. A chave é o id da revenda; a venda
     * direta chega do `groupBy` como chave vazia, que `chaveDeRevenda()`
     * normaliza para o tier padrão.
     *
     * É a origem tanto do total quanto da abertura no painel de detalhe da
     * tela de Sistemas. Enquanto cada um fazia o próprio laço, os dois podiam
     * discordar — e a cópia do painel sequer tinha a guarda de produto
     * desativado, então ele mostraria receita de um sistema que o resto do
     * sistema já dá como zero.
     *
     * Produto desativado devolve coleção vazia: ele fica fora do fechamento,
     * logo não vale receita nenhuma.
     *
     * @return \Illuminate\Support\Collection<int|string, float>
     */
    public function mrrPorRevenda(): \Illuminate\Support\Collection
    {
        if (! $this->ativo) {
            return collect();
        }

        return $this->clientesComVinculoAtivo()
            ->groupBy('revenda_id')
            ->map(function ($clientes, $revendaId) {
                $qtd = $clientes->count();
                $tier = $this->tierParaVolume($qtd, $this->chaveDeRevenda($revendaId));

                return (float) ($tier?->calcularMensalidade($qtd) ?? 0);
            });
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
