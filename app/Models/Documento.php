<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um arquivo espelhado; a cópia mora no disco `central_ia`, em `documentos/`. */
class Documento extends Model
{
    public const DISCO = 'central_ia';

    /** Abrem no navegador (FR-003); o resto só baixa. */
    public const INLINE = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'mp4', 'webm', 'mov', 'mp3', 'm4a', 'ogg', 'wav'];

    public const BLOQUEADAS = ['exe', 'bat', 'cmd', 'ps1', 'sh', 'php', 'js', 'msi', 'dll', 'com', 'scr', 'vbs', 'jar'];

    public const MAX_BYTES = 200 * 1024 * 1024;

    protected $table = 'documentos';

    protected $fillable = ['pasta_id', 'caminho', 'caminho_hash', 'subpasta', 'nome', 'extensao', 'mime', 'tamanho', 'sha256', 'modificado_em', 'arquivo'];

    protected function casts(): array
    {
        return ['modificado_em' => 'datetime', 'tamanho' => 'integer'];
    }

    public function pasta(): BelongsTo
    {
        return $this->belongsTo(DocumentoPasta::class, 'pasta_id');
    }

    public function abreNoNavegador(): bool
    {
        return in_array($this->extensao, self::INLINE, true);
    }

    public function tipo(): string
    {
        return match (true) {
            $this->extensao === 'pdf' => 'PDF',
            in_array($this->extensao, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) => 'Imagem',
            in_array($this->extensao, ['mp4', 'webm', 'mov'], true) => 'Vídeo',
            in_array($this->extensao, ['mp3', 'm4a', 'ogg', 'wav'], true) => 'Áudio',
            in_array($this->extensao, ['xlsx', 'xls', 'csv'], true) => 'Planilha',
            in_array($this->extensao, ['docx', 'doc'], true) => 'Documento',
            in_array($this->extensao, ['pptx', 'ppt'], true) => 'Apresentação',
            default => strtoupper($this->extensao ?: 'arquivo'),
        };
    }

    public function tamanhoLegivel(): string
    {
        $b = $this->tamanho;

        return $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.').' MB' : max(1, (int) round($b / 1024)).' KB';
    }
}
