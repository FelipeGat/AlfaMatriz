<?php

namespace App\Services\Secretario;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Próxima ocorrência de um item que se repete. Mensal no dia que o mês não
 * tem (31 em fevereiro) cai no último dia — o aluguel do dia 31 é pago no
 * dia 28, e não pulado.
 */
class Recorrencia
{
    public const FREQS = ['diaria', 'semanal', 'mensal'];

    /** A primeira ocorrência estritamente depois de `$depois`, no horário de `$hora` (H:i). */
    public static function proxima(array $r, CarbonInterface $depois, string $hora): ?CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $hora));
        $base = CarbonImmutable::instance($depois);

        switch ($r['freq'] ?? null) {
            case 'diaria':
                $c = $base->setTime($h, $m);

                return $c->greaterThan($base) ? $c : $c->addDay();

            case 'semanal':
                $dias = array_values(array_filter(array_map('intval', $r['dias'] ?? []), fn ($d) => $d >= 1 && $d <= 7));
                if (! $dias) {
                    return null;
                }
                for ($i = 0; $i <= 7; $i++) {
                    $c = $base->addDays($i)->setTime($h, $m);
                    if (in_array($c->dayOfWeekIso, $dias, true) && $c->greaterThan($base)) {
                        return $c;
                    }
                }

                return null;

            case 'mensal':
                $dia = (int) ($r['dia_mes'] ?? 0);
                if ($dia < 1 || $dia > 31) {
                    return null;
                }
                for ($i = 0; $i <= 2; $i++) {
                    $mes = $base->startOfMonth()->addMonthsNoOverflow($i);
                    $c = $mes->setDate($mes->year, $mes->month, min($dia, $mes->daysInMonth))->setTime($h, $m);
                    if ($c->greaterThan($base)) {
                        return $c;
                    }
                }

                return null;
        }

        return null;
    }
}
