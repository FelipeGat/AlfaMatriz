<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecretarioReuniaoTrecho extends Model
{
    public $timestamps = false;

    protected $table = 'secretario_reuniao_trechos';

    protected $fillable = ['reuniao_id', 'ordem', 'inicio', 'fim', 'texto'];

    protected function casts(): array
    {
        return ['inicio' => 'float', 'fim' => 'float'];
    }

    public static function horario(float $segundos): string
    {
        $s = (int) floor($segundos);

        return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%02d:%02d', intdiv($s, 60), $s % 60);
    }
}
