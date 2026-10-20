<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma linha do investimento: valor único ou mensalidade; quantidade decimal (m, CNPJ, un). */
class OrcamentoItem extends Model
{
    /**
     * "Opção" fica fora do total (20/10/2026): faixa de quantidade, item opcional
     * ou "sob consulta" — o cliente escolhe, e somar tudo dava um total que ninguém pediu.
     */
    public const COBRANCAS = ['unico' => 'Valor único', 'mensal' => 'Mensal', 'opcao' => 'Opção (fora do total)'];

    protected $table = 'orcamento_itens';

    protected $fillable = ['orcamento_id', 'ordem', 'descricao', 'quantidade', 'unidade', 'valor_unitario', 'cobranca'];

    protected function casts(): array
    {
        return ['quantidade' => 'float', 'valor_unitario' => 'float', 'ordem' => 'integer'];
    }

    public function orcamento(): BelongsTo
    {
        return $this->belongsTo(Orcamento::class);
    }

    public function subtotal(): float
    {
        return round($this->quantidade * $this->valor_unitario, 2);
    }

    /** 300 → "300"; 12,5 → "12,5". */
    public function quantidadeLegivel(): string
    {
        return rtrim(rtrim(number_format($this->quantidade, 3, ',', '.'), '0'), ',');
    }
}
