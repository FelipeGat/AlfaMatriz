<?php

namespace App\Models;

use App\Concerns\Auditavel;
use App\Concerns\ComOrigemExterna;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends Model
{
    use Auditavel, ComOrigemExterna, SoftDeletes;

    protected string $recursoAuditoria = 'clientes';

    protected $fillable = [
        'revenda_id', 'nome', 'nome_fantasia', 'razao_social', 'cpf_cnpj',
        'tipo_pessoa', 'cidade', 'uf', 'ativo',
        'cep', 'logradouro', 'numero', 'bairro', 'complemento', 'latitude', 'longitude',
        'inscricao_estadual', 'inscricao_municipal', 'nota_fiscal',
        'tipo_cliente', 'valor_mensal', 'dia_vencimento', 'forma_pagamento_recebimento',
        'data_cadastro', 'observacoes',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'nota_fiscal' => 'boolean',
            'valor_mensal' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'data_cadastro' => 'date',
        ];
    }

    /**
     * UF é código de duas letras, não texto livre: normalizar é parte do dado.
     *
     * O formulário tinha a classe `uppercase` do Tailwind, que é só
     * text-transform — mostrava "ES" e gravava "es". Aqui pega todo caminho de
     * escrita, inclusive o sincronizador, que traz UF de sistema de fora.
     */
    protected function uf(): Attribute
    {
        return Attribute::set(
            fn (?string $valor) => $valor === null ? null : mb_strtoupper(trim($valor))
        );
    }

    public function revenda(): BelongsTo
    {
        return $this->belongsTo(Revenda::class);
    }

    public function origensExternas(): MorphMany
    {
        return $this->morphMany(OrigemExterna::class, 'entidade');
    }

    public function sistemas(): BelongsToMany
    {
        return $this->belongsToMany(Sistema::class, 'cliente_sistema')
            ->using(ClienteSistema::class)
            ->withPivot(['ativo', 'ativado_em', 'cancelado_em',
                'licenca_status', 'plano', 'licenca_valor', 'licenca_inicio_em',
                'licenca_fim_em', 'bloqueia_acesso', 'licenca_id_externo',
                'status_saas', 'uso_unidades', 'uso_metricas', 'uso_medido_em']);
    }

    /** Módulos que este cliente tem contratados, em qualquer sistema. */
    public function modulosContratados(): HasMany
    {
        return $this->hasMany(ClienteModulo::class);
    }

    /** As licenças de sistema que este cliente tem contratadas — o que o fechamento mensal cobra por ele. */
    public function contratos(): HasMany
    {
        return $this->hasMany(ClienteContrato::class);
    }

    public function cobrancas(): HasMany
    {
        return $this->hasMany(Cobranca::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(ClienteEmail::class);
    }

    public function telefones(): HasMany
    {
        return $this->hasMany(ClienteTelefone::class);
    }

    public function isDireto(): bool
    {
        return is_null($this->revenda_id);
    }

    /**
     * O dia em que o cliente entrou na base.
     *
     * `created_at` é quando a LINHA nasceu. A base veio de importação, então
     * ele marca o dia da migração para todo mundo de uma vez — usá-lo como
     * data de entrada faz a base inteira parecer ter chegado no mesmo dia, e
     * qualquer contagem de "novos no mês" devolve o cadastro inteiro.
     *
     * `data_cadastro` é a data que o formulário coleta. Onde ela falta, o
     * `created_at` é o melhor palpite que existe.
     *
     * Quem precisa FILTRAR por isso no banco usa a expressão equivalente em
     * SQL — acessor não vai para o WHERE.
     */
    public function getDataEntradaAttribute(): ?\Illuminate\Support\Carbon
    {
        return $this->data_cadastro ?? $this->created_at;
    }

    /** A mesma regra do acessor, em SQL, para filtro e ordenação. */
    public static function expressaoDeEntrada(): string
    {
        return 'COALESCE(clientes.data_cadastro, DATE(clientes.created_at))';
    }

    public function isContratoMensal(): bool
    {
        return $this->ativo && $this->tipo_cliente === 'CONTRATO' && $this->valor_mensal > 0 && $this->dia_vencimento > 0;
    }

    public function isAvulso(): bool
    {
        return $this->tipo_cliente === 'AVULSO';
    }

    public function emailPrincipal(): ?ClienteEmail
    {
        return $this->emails->firstWhere('principal', true) ?? $this->emails->first();
    }

    public function emailsFinanceiros()
    {
        $financeiros = $this->emails->where('financeiro', true);

        return $financeiros->isNotEmpty() ? $financeiros : collect([$this->emailPrincipal()])->filter();
    }

    public function telefonePrincipal(): ?ClienteTelefone
    {
        return $this->telefones->firstWhere('principal', true) ?? $this->telefones->first();
    }

    public function getNomeExibicaoAttribute(): string
    {
        if ($this->tipo_pessoa === 'PF') {
            return $this->nome;
        }

        return $this->nome ?: $this->razao_social;
    }

    public function getCpfCnpjFormatadoAttribute(): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $this->cpf_cnpj);

        if (strlen($digitos) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $digitos);
        }

        if (strlen($digitos) === 14) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $digitos);
        }

        return $this->cpf_cnpj;
    }

    /**
     * Cap de dia 28 pra não estourar em fevereiro (mesma regra do Gestor.Alfa).
     */
    public function getDiaVencimentoSeguro(): ?int
    {
        return $this->dia_vencimento ? min($this->dia_vencimento, 28) : null;
    }
}
