<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecretarioGoogleAgenda extends Model
{
    protected $table = 'secretario_google_agendas';

    protected $fillable = ['user_id', 'google_id', 'nome', 'primaria', 'incluida', 'padrao', 'palavras'];

    protected $attributes = ['primaria' => false, 'incluida' => true, 'padrao' => false];

    protected function casts(): array
    {
        return ['primaria' => 'boolean', 'incluida' => 'boolean', 'padrao' => 'boolean'];
    }

    /** @return list<string> as palavras que ligam a agenda a uma empresa/assunto, minúsculas */
    public function listaDePalavras(): array
    {
        return array_values(array_filter(array_map(fn ($p) => mb_strtolower(trim($p)), explode(',', (string) $this->palavras))));
    }
}
