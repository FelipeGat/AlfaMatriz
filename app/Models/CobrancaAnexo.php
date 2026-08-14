<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CobrancaAnexo extends Model
{
    use Auditavel;

    protected string $recursoAuditoria = 'cobrancas';

    protected $table = 'cobranca_anexos';

    protected $fillable = [
        'cobranca_id', 'tipo', 'nome_original', 'nome_arquivo', 'caminho', 'tamanho',
    ];

    protected $appends = ['tamanho_formatado', 'tipo_formatado', 'url'];

    /**
     * Ver `TarefaAnexo::booted()` — mesma regra e mesmo motivo: a linha que
     * morre leva o arquivo junto, e o cascade do banco não dispara isto (quem
     * cobre a exclusão da cobrança é o `deleting` do próprio `Cobranca`).
     *
     * Aqui o que fica para trás é boleto e nota fiscal, com conta bancária e
     * CNPJ dentro — arquivo que sobra num disco sem nada que aponte para ele é
     * pior do que ocupar espaço.
     */
    protected static function booted(): void
    {
        static::deleting(function (CobrancaAnexo $anexo): void {
            Storage::disk('public')->delete($anexo->caminho);
        });
    }

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
