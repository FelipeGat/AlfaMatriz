<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PairingCode extends Model
{
    protected $guarded = [];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
