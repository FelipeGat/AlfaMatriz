<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CobrancaAnexo extends Model
{
    use Auditavel;

    protected string $recursoAuditoria = 'cobrancas';

    protected $table = 'cobranca_anexos';

    protected $fillable = [
        'cobranca_id', 'tipo', 'nome_original', 'nome_arquivo', 'caminho', 'tamanho',
    ];

    protected $appends = ['tamanho_formatado', 'tipo_formatado', 'url'];

    public function cobranca(): BelongsTo
    {
        return $this->belongsTo(Cobranca::class);
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
        return route('cobrancas.anexos.download', $this->id);
    }

    /**
     * O anexo se apresenta pelo nome que tinha ao ser enviado — `nome_arquivo`
     * é o identificador aleatório do disco, que não diz nada a ninguém.
     */
    public function descricaoDeAuditoria(): string
    {
        return $this->nome_original;
    }
}
