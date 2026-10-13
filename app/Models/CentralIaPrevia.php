<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Um arquivo de prévia visual que o agente mostrou (imagem, PDF, página). */
class CentralIaPrevia extends Model
{
    protected $table = 'central_ia_previas';

    protected $fillable = ['user_id', 'execucao_id', 'chave', 'caminho', 'mime', 'tamanho', 'sha256', 'telegram'];

    public function tipo(): string
    {
        return match (true) {
            str_starts_with($this->mime, 'image/') => 'imagem',
            $this->mime === 'application/pdf' => 'pdf',
            $this->mime === 'text/html' => 'pagina',
            default => 'outro',
        };
    }
}
