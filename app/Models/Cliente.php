<?php

namespace App\Models;

use App\Concerns\ComOrigemExterna;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends Model
{
    use ComOrigemExterna, SoftDeletes;

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
            ->withPivot(['ativo', 'ativado_em', 'cancelado_em',
                'licenca_status', 'plano', 'licenca_inicio_em',
                'licenca_fim_em', 'bloqueia_acesso', 'licenca_id_externo']);
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
