<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CentralIaPreferencia extends Model
{
    protected $table = 'central_ia_preferencias';
    protected $fillable = ['user_id', 'regras'];
    protected $casts = ['regras' => 'array'];
}
