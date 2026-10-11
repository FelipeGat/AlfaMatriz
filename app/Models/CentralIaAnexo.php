<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CentralIaAnexo extends Model
{
    protected $table = 'central_ia_anexos';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    public function publico(): array
    {
        return ['id' => $this->id, 'nome' => $this->nome, 'mime' => $this->mime,
            'tamanho' => (int) $this->tamanho, 'sha256' => $this->sha256,
            'url' => route('central-ia.anexos.baixar', $this->id)];
    }
}
