<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClienteEmail extends Model
{
    protected $fillable = ['cliente_id', 'email', 'principal', 'financeiro'];

    protected function casts(): array
    {
        return [
            'principal' => 'boolean',
            'financeiro' => 'boolean',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
