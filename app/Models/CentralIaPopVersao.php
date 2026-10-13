<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A versão anterior de um POP, guardada a cada mudança. */
class CentralIaPopVersao extends Model
{
    protected $table = 'central_ia_pop_versoes';

    public const UPDATED_AT = null;

    protected $fillable = ['pop_id', 'versao', 'conteudo', 'autor', 'execucao_id'];
}
