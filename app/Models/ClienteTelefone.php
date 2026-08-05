<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClienteTelefone extends Model
{
    protected $fillable = ['cliente_id', 'telefone', 'principal'];

    protected function casts(): array
    {
        return ['principal' => 'boolean'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
