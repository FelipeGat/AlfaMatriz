<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Um arquivo anexado ao lead — print de e-mail, de WhatsApp, proposta em PDF.
 *
 * Mesmo padrão de `TarefaAnexo`/`CobrancaAnexo`: nome aleatório no disco
 * `public` (sobrevive à publicação azul/verde), extensão deduzida do
 * CONTEÚDO na validação, nunca do nome que veio do navegador.
 */
class LeadAnexo extends Model
{
    use Auditavel;

    protected string $recursoAuditoria = 'leads';

    protected $table = 'lead_anexos';

    public const EXTENSOES_VALIDADAS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv', 'xls', 'xlsx'];

    public const ACEITE_DO_SELETOR = '.jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.csv,.xls,.xlsx';

    protected $fillable = ['lead_id', 'autor_id', 'nome_original', 'nome_arquivo', 'mime', 'caminho', 'tamanho'];

    protected $appends = ['tamanho_formatado', 'url', 'autor_nome', 'eh_imagem'];

    protected $hidden = ['caminho', 'nome_arquivo'];

    protected static function booted(): void
    {
        static::deleting(function (LeadAnexo $anexo): void {
            Storage::disk('public')->delete($anexo->caminho);
        });
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
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

    public function getEhImagemAttribute(): bool
    {
        return str_starts_with($this->mime ?? '', 'image/');
    }

    public function getUrlAttribute(): string
    {
        return route('leads.anexos.ver', $this->id);
    }

    public function getAutorNomeAttribute(): string
    {
        return $this->autor?->name ?? 'Autor removido';
    }

    public function descricaoDeAuditoria(): string
    {
        return $this->nome_original;
    }
}
