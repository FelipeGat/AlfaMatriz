<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Um POP das IAs: como operar um sistema, aprendido e mantido por elas. */
class CentralIaPop extends Model
{
    protected $table = 'central_ia_pops';

    protected $fillable = ['user_id', 'sistema', 'titulo', 'conteudo', 'versao', 'autor', 'execucao_id'];

    public function versoes(): HasMany
    {
        return $this->hasMany(CentralIaPopVersao::class, 'pop_id');
    }
}
