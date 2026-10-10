<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Projeto fixado no cockpit de uma pessoa. Os demais aparecem quando têm
 * tarefa em curso; estes, sempre.
 */
class CentralIaProjeto extends Model
{
    protected $table = 'central_ia_projetos';

    protected $fillable = ['user_id', 'projeto', 'grupo_id', 'ordem'];
}
