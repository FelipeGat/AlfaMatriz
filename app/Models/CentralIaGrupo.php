<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Grupo de projetos no cockpit de uma pessoa (VixCard, Grupo Soluções…). */
class CentralIaGrupo extends Model
{
    protected $table = 'central_ia_grupos';

    protected $fillable = ['user_id', 'nome', 'ordem'];
}
