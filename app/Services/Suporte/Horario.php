<?php

namespace App\Services\Suporte;

use Illuminate\Support\Carbon;

/**
 * O horário de atendimento: das 08:00 às 18:00, de segunda a sexta (Felipe, 31/10/2026;
 * até então começava às 07:00). Feriado ainda conta como dia útil: o Matriz não tem
 * cadastro de feriados.
 *
 * Até 31/10/2026 era também o horário do Pedro. Desde então ele atende o cliente novo a
 * qualquer hora ("todas aqui pelo Pedro", spec.md, Clarificações), e isto vale para o lado
 * das pessoas (o retorno no próximo horário, os lembretes da fila, a cobrança dos técnicos)
 * e para o cliente do cadastro, que fora daqui recebe o aviso fixo com o plantão.
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

        return $hora >= self::inicio() && $hora < self::fim();
    }

    /** "das 08:00 às 18:00": o mesmo ajuste em todo texto que fala do horário, para nenhum ficar para trás. */
    public static function faixa(): string
    {
        return 'das '.self::inicio().' às '.self::fim();
    }

    private static function inicio(): string
    {
        return (string) config('services.suporte_pedro.inicio', '08:00');
    }

    private static function fim(): string
    {
        return (string) config('services.suporte_pedro.fim', '18:00');
    }
}
