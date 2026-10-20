<?php

namespace App\Services\Suporte;

use Illuminate\Support\Carbon;

/**
 * Quando o Pedro atende (Felipe, 20/10/2026): das 07:00 às 18:00, de segunda a
 * sexta. Fora disso a mensagem fica guardada e ele atende na abertura seguinte.
 * Feriado ainda conta como dia útil: o Matriz não tem cadastro de feriados.
 */
class Horario
{
    public static function aberto(?Carbon $quando = null): bool
    {
        $q = ($quando ?? now())->copy()->setTimezone(config('app.timezone'));
        if ($q->isWeekend()) {
            return false;
        }
        $hora = $q->format('H:i');

        return $hora >= (string) config('services.suporte_pedro.inicio', '07:00') && $hora < (string) config('services.suporte_pedro.fim', '18:00');
    }
}
