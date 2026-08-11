<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContaFixaPagar extends Model
{
    protected $table = 'contas_fixas_pagar';

    protected $fillable = [
        'centro_custo_id', 'conta_id', 'fornecedor_id', 'conta_financeira_id',
        'descricao', 'valor', 'dia_vencimento', 'data_inicio', 'data_fim',
        'forma_pagamento', 'ativo',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'data_inicio' => 'date',
            'data_fim' => 'date',
            'ativo' => 'boolean',
        ];
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class);
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(Conta::class);
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function contaFinanceira(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class);
    }

    public function contasPagar(): HasMany
    {
        return $this->hasMany(ContaPagar::class);
    }

    /**
     * A despesa gera parcela nesta competência (AAAA-MM)?
     *
     * A vigência é avaliada contra o mês inteiro, não contra um dia: uma
     * despesa que começa dia 20 pertence à competência daquele mês desde o
     * dia 1. Medir por dia fazia o cadastro e o fechamento discordarem — a
     * tela não gerava nada, e o último dia do mês gerava.
     */
    public function vigenteNaCompetencia(string $competencia): bool
    {
        if (! $this->ativo) {
            return false;
        }

        $inicioDoMes = Carbon::createFromFormat('Y-m', $competencia)->startOfMonth();

        if ($this->data_inicio->gt($inicioDoMes->copy()->endOfMonth())) {
            return false;
        }

        return ! $this->data_fim || $this->data_fim->gte($inicioDoMes);
    }

    /**
     * Data de vencimento da parcela para uma competência (AAAA-MM), com o dia
     * clampado ao último dia do mês quando o mês for mais curto que dia_vencimento.
     */
    public function dataVencimentoParaCompetencia(string $competencia): Carbon
    {
        $mes = Carbon::createFromFormat('Y-m', $competencia)->startOfMonth();
        $dia = min($this->dia_vencimento, $mes->daysInMonth);

        return $mes->copy()->setDay($dia);
    }
}
