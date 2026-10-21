<?php

namespace App\Services\Secretario;

use Carbon\CarbonInterface;

/**
 * Data e recorrência por extenso, em português. A proposta mostra a data
 * escrita ("sáb, 18/10 às 09:00") porque é ela que deixa a pessoa ver o
 * engano antes de confirmar — "amanhã" dito depois da meia-noite, "às 7".
 */
class Datas
{
    public const SEMANA = [0 => 'dom', 1 => 'seg', 2 => 'ter', 3 => 'qua', 4 => 'qui', 5 => 'sex', 6 => 'sáb'];

    /** Dias ISO (1 = segunda … 7 = domingo), como a recorrência guarda. */
    public const DIAS_ISO = [1 => 'segunda', 2 => 'terça', 3 => 'quarta', 4 => 'quinta', 5 => 'sexta', 6 => 'sábado', 7 => 'domingo'];

    public static function porExtenso(CarbonInterface $quando, bool $soData = false, ?CarbonInterface $agora = null): string
    {
        $agora ??= now();
        $dia = self::SEMANA[$quando->dayOfWeek].', '.$quando->format('d/m');
        if ($quando->year !== $agora->year) {
            $dia .= '/'.$quando->year;
        }
        $relativo = match (true) {
            $quando->isSameDay($agora) => 'hoje',
            $quando->isSameDay($agora->copy()->addDay()) => 'amanhã',
            default => null,
        };
        $texto = $relativo ? "{$relativo} ({$dia})" : $dia;

        return $soData ? $texto : $texto.' às '.$quando->format('H:i');
    }

    public static function recorrencia(array $r, ?CarbonInterface $quando): string
    {
        $hora = $quando ? ', '.$quando->format('H:i') : '';

        return match ($r['freq'] ?? null) {
            'diaria' => 'todo dia'.$hora,
            'semanal' => 'toda '.self::juntar(array_map(fn ($d) => self::DIAS_ISO[$d] ?? '?', $r['dias'] ?? [])).$hora,
            'mensal' => 'todo dia '.($r['dia_mes'] ?? '?').$hora,
            default => '',
        };
    }

    private static function juntar(array $itens): string
    {
        return count($itens) > 1 ? implode(', ', array_slice($itens, 0, -1)).' e '.end($itens) : (string) ($itens[0] ?? '');
    }
}
