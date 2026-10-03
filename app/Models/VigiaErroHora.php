<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Quantas vezes um erro do vigia apareceu numa hora (#219) — a régua do pico.
 */
class VigiaErroHora extends Model
{
    protected $table = 'vigia_erros_horas';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'hora' => 'datetime',
            'total' => 'integer',
        ];
    }
}
