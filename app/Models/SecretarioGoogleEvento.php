<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cache mínimo de um evento do Google: sem descrição nem convidados (spec FR-007). */
class SecretarioGoogleEvento extends Model
{
    protected $table = 'secretario_google_eventos';

    protected $fillable = ['user_id', 'agenda_id', 'google_id', 'titulo', 'inicio', 'fim', 'dia_inteiro', 'link'];

    protected function casts(): array
    {
        return ['inicio' => 'datetime', 'fim' => 'datetime', 'dia_inteiro' => 'boolean'];
    }

    public function agenda(): BelongsTo
    {
        return $this->belongsTo(SecretarioGoogleAgenda::class, 'agenda_id');
    }
}
