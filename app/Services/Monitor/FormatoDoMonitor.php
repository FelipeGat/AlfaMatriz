<?php

namespace App\Services\Monitor;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Como as telas do Monitor escrevem os números da API (#394).
 *
 * O monitor manda tudo CRU de propósito — bytes em bytes, % com duas casas,
 * segundos —, a pedido do Matriz: a formatação é daqui, no padrão daqui
 * (vírgula decimal, ponto de milhar). Num lugar só para as sete telas não
 * escreverem "2,6 GB" de sete jeitos.
 */
final class FormatoDoMonitor
{
    public static function pct(mixed $valor, int $casas = 1): string
    {
        if (! is_numeric($valor)) {
            return '—';
        }

        return number_format((float) $valor, $casas, ',', '.').'%';
    }

    /** Disponibilidade: 99,93% é diferente de 99,9%, por isso duas casas. */
    public static function noAr(mixed $valor): string
    {
        return self::pct($valor, 2);
    }

    public static function inteiro(mixed $valor): string
    {
        return is_numeric($valor) ? number_format((float) $valor, 0, ',', '.') : '—';
    }

    public static function ms(mixed $valor): string
    {
        return is_numeric($valor) ? self::inteiro($valor).' ms' : '—';
    }

    /** Base 1024, como o `df` e o painel da Hostinger mostram. */
    public static function bytes(mixed $valor): string
    {
        if (! is_numeric($valor)) {
            return '—';
        }

        $valor = (float) $valor;
        $unidades = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($valor >= 1024 && $i < count($unidades) - 1) {
            $valor /= 1024;
            $i++;
        }

        return number_format($valor, $i >= 3 ? 1 : 0, ',', '.').' '.$unidades[$i];
    }

    /** Duração de queda: "4 min", "3 h 23 min", "2 d 5 h". */
    public static function duracao(mixed $segundos): string
    {
        if (! is_numeric($segundos)) {
            return '—';
        }

        $s = (int) $segundos;

        if ($s < 60) {
            return $s.' s';
        }

        $min = intdiv($s, 60);

        if ($min < 60) {
            return $min.' min';
        }

        $h = intdiv($min, 60);

        if ($h < 24) {
            return $h.' h'.($min % 60 ? ' '.($min % 60).' min' : '');
        }

        return intdiv($h, 24).' d'.($h % 24 ? ' '.($h % 24).' h' : '');
    }

    /**
     * O id do cliente para o link da página dele: `<fonte>:<cliente_id>`, a
     * identidade estável. O "auto…" do monitor pode mudar numa atualização
     * dele, e só serve de último recurso (resposta de antes do `acesso`).
     *
     * @param  array<string, mixed>|null  $ref  algo com `acesso: [{fonte, cliente_id}]` e/ou `id`/`cliente`
     */
    public static function idDoCliente(?array $ref): ?string
    {
        $acesso = $ref['acesso'][0] ?? null;

        if (isset($acesso['fonte'], $acesso['cliente_id'])) {
            return $acesso['fonte'].':'.$acesso['cliente_id'];
        }

        return $ref['cliente'] ?? $ref['id'] ?? null;
    }

    /** Hora ISO do monitor no fuso do painel; nulo ou ilegível vira nulo. */
    public static function hora(mixed $iso): ?Carbon
    {
        if (! is_string($iso) || $iso === '') {
            return null;
        }

        try {
            return Carbon::parse($iso)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * O tom do `x-badge` para a gravidade do monitor. O monitor fala em
     * `crit`/`warn`/`ok` na API e `critical`/`warning` no relatório; os dois
     * entram.
     */
    public static function tom(?string $sev): string
    {
        return match ($sev) {
            'crit', 'critical', 'down', 'offline' => 'critico',
            'warn', 'warning', 'lento', 'parcial' => 'atencao',
            'ok', 'up', 'online' => 'bom',
            default => 'neutro',
        };
    }

    public static function rotuloDaGravidade(?string $sev): string
    {
        return match ($sev) {
            'crit', 'critical' => 'Crítico',
            'warn', 'warning' => 'Atenção',
            'ok' => 'Ok',
            'info' => 'Info',
            default => (string) $sev,
        };
    }
}
