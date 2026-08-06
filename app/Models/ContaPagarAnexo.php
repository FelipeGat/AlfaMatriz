<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContaPagarAnexo extends Model
{
    protected $table = 'conta_pagar_anexos';

    protected $fillable = [
        'conta_pagar_id', 'tipo', 'nome_original', 'nome_arquivo', 'caminho', 'tamanho',
    ];

    protected $appends = ['tamanho_formatado', 'tipo_formatado', 'url'];

    public function contaPagar(): BelongsTo
    {
        return $this->belongsTo(ContaPagar::class);
    }

    public function getTamanhoFormatadoAttribute(): string
    {
        if ($this->tamanho < 1024) {
            return $this->tamanho.' B';
        }

        if ($this->tamanho < 1048576) {
            return round($this->tamanho / 1024, 1).' KB';
        }

        return round($this->tamanho / 1048576, 1).' MB';
    }

    public function getTipoFormatadoAttribute(): string
    {
        return match ($this->tipo) {
            'nf' => 'Nota Fiscal',
            'boleto' => 'Boleto',
            default => $this->tipo,
        };
    }

    public function getUrlAttribute(): string
    {
        return route('contas-pagar.anexos.download', $this->id);
    }
}
