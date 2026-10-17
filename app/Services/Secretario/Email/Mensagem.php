<?php

namespace App\Services\Secretario\Email;

use Carbon\CarbonImmutable;

/**
 * Lê o que o ClienteImap trouxe: cabeçalhos decodificados (=?utf-8?…?=) e o
 * começo do texto legível (multipart, quoted-printable, base64, charset).
 * O texto serve à classificação e ao pedido à IA, e NUNCA é guardado.
 */
class Mensagem
{
    public function __construct(
        public readonly int $uid,
        public readonly ?string $messageId,
        public readonly ?string $respondeA,
        public readonly ?string $deNome,
        public readonly string $deEmail,
        public readonly string $assunto,
        public readonly CarbonImmutable $recebidoEm,
        public readonly string $texto,
        /** Convite de reunião (text/calendar), lido do VEVENT; null se não é convite. */
        public readonly ?array $convite = null,
    ) {}

    public static function de(array $bruto): self
    {
        $h = self::cabecalhos($bruto['cabecalho']);
        [$nome, $email] = self::endereco($h['from'] ?? '');
        $data = self::data($bruto['data_interna'] ?? null) ?? self::data($h['date'] ?? null) ?? CarbonImmutable::now();

        return new self(
            (int) $bruto['uid'],
            isset($h['message-id']) ? mb_substr(trim($h['message-id']), 0, 255) : null,
            isset($h['in-reply-to']) ? mb_substr(trim(strtok($h['in-reply-to'], ' ')), 0, 255) : null,
            $nome ? mb_substr($nome, 0, 190) : null,
            mb_substr($email ?: '(sem remetente)', 0, 190),
            mb_substr(trim($h['subject'] ?? '') ?: '(sem assunto)', 0, 255),
            $data->setTimezone(config('app.timezone')),
            ...self::analisar($h, (string) $bruto['texto']),
        );
    }

    /** @return array<string, string> nomes em minúsculo, valores decodificados */
    public static function cabecalhos(string $bloco): array
    {
        $bloco = preg_replace("/\r?\n[ \t]+/", ' ', $bloco); // linhas dobradas
        $saida = [];
        foreach (preg_split("/\r?\n/", (string) $bloco) as $linha) {
            if (! preg_match('/^([A-Za-z-]+):\s*(.*)$/', $linha, $m)) {
                continue;
            }
            // Só decodifica o que vem como =?charset?…?=: cabeçalho em UTF-8 cru
            // (comum em servidores modernos) o iconv estragaria.
            $valor = str_contains($m[2], '=?') ? @iconv_mime_decode($m[2], ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : $m[2];
            $saida[mb_strtolower($m[1])] = mb_scrub($valor !== false ? $valor : $m[2], 'UTF-8');
        }

        return $saida;
    }

    /** @return array{0: ?string, 1: string} */
    public static function endereco(string $valor): array
    {
        if (preg_match('/^\s*"?([^"<]*)"?\s*<([^>]+)>/', $valor, $m)) {
            return [trim($m[1]) ?: null, mb_strtolower(trim($m[2]))];
        }

        return [null, mb_strtolower(trim($valor))];
    }

    private static function data(?string $v): ?CarbonImmutable
    {
        if (! $v) {
            return null;
        }
        try {
            return CarbonImmutable::parse(preg_replace('/\s*\([^)]*\)\s*$/', '', $v));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * O começo do texto que um humano leria. Abre as partes em qualquer
     * profundidade (o Outlook manda multipart/mixed com multipart/alternative
     * dentro) e prefere texto, depois HTML; convite de reunião (text/calendar)
     * vira um resumo legível em vez do código iCalendar cru.
     */
    public static function textoLegivel(array $h, string $corpo): string
    {
        return self::analisar($h, $corpo)[0];
    }

    /** @return array{0: string, 1: ?array} o texto legível e o convite (se houver) */
    public static function analisar(array $h, string $corpo): array
    {
        $achado = ['plain' => null, 'html' => null, 'calendar' => null];
        self::partes($h['content-type'] ?? 'text/plain', $h['content-transfer-encoding'] ?? '', $corpo, $achado, 0);
        $convite = $achado['calendar'] !== null ? self::conviteDados($achado['calendar']) : null;
        $resumo = $convite ? self::resumoDoConvite($convite) : '';
        foreach (['plain', 'html'] as $tipo) {
            if ($achado[$tipo] !== null && trim($achado[$tipo]) !== '') {
                return [$achado[$tipo].($resumo !== '' ? "\n\n".$resumo : ''), $convite];
            }
        }

        return [$resumo, $convite];
    }

    /** @param array{plain: ?string, html: ?string, calendar: ?string} $achado */
    private static function partes(string $tipoBruto, string $cte, string $corpo, array &$achado, int $nivel): void
    {
        $tipo = mb_strtolower($tipoBruto);
        if ($nivel < 5 && str_starts_with($tipo, 'multipart/') && preg_match('/boundary="?([^";]+)"?/i', $tipoBruto, $b)) {
            foreach (explode('--'.$b[1], $corpo) as $parte) {
                [$cabeca, $miolo] = array_pad(preg_split("/\r?\n\r?\n/", ltrim($parte, "\r\n"), 2), 2, '');
                $hp = self::cabecalhos($cabeca);
                if (isset($hp['content-type'])) {
                    self::partes($hp['content-type'], $hp['content-transfer-encoding'] ?? '', $miolo, $achado, $nivel + 1);
                }
            }

            return;
        }
        $chave = match (true) {
            str_starts_with($tipo, 'text/calendar') || str_starts_with($tipo, 'application/ics') => 'calendar',
            str_starts_with($tipo, 'text/html') => 'html',
            str_starts_with($tipo, 'text/plain') || $nivel === 0 && ! str_contains($tipo, '/') => 'plain',
            default => null,
        };
        if ($chave && $achado[$chave] === null) {
            $achado[$chave] = $chave === 'calendar' ? self::decodificarCru($corpo, $cte) : self::decodificar($corpo, $cte, $tipo);
        }
    }

    /**
     * Os dados do VEVENT (RFC 5545), sem inventar o que não veio. Hora com
     * TZID do Windows ("E. South America Standard Time") é tratada como hora
     * local; com "Z", como UTC.
     *
     * @return array{metodo: string, titulo: ?string, inicio: ?CarbonImmutable, fim: ?CarbonImmutable, dia_inteiro: bool, local: ?string, organizador: ?string, participantes: list<string>, descricao: ?string}|null
     */
    public static function conviteDados(string $ics): ?array
    {
        // Linhas dobradas: a continuação começa com espaço ou tab.
        $ics = preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $ics));
        if (! preg_match('/BEGIN:VEVENT/i', $ics) && ! preg_match('/^SUMMARY/mi', $ics)) {
            return null;
        }
        $calendario = $ics;
        // O Outlook põe um VTIMEZONE antes do evento, com DTSTART:16010101T000000:
        // as propriedades do evento vêm só do bloco VEVENT.
        if (preg_match('/^BEGIN:VEVENT\s*$(.*?)^END:VEVENT\s*$/msi', $ics, $ev)) {
            $ics = $ev[1];
        }
        $prop = fn (string $nome, ?string $em = null): ?array => preg_match('/^'.$nome.'((?:;[^:\n]*)?):(.*)$/mi', $em ?? $ics, $m) ? [$m[1], trim($m[2])] : null;
        $texto = fn (?array $p) => $p ? str_replace(['\\n', '\\N', '\\,', '\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $p[1]) : null;
        $quando = function (?array $p): ?CarbonImmutable {
            if (! $p) {
                return null;
            }
            // TZID com nome IANA vale; o do Windows ("E. South America Standard Time") cai no do sistema.
            $tz = config('app.timezone');
            if (preg_match('/TZID="?([^";:]+)"?/i', $p[0], $z) && in_array(trim($z[1]), \DateTimeZone::listIdentifiers(), true)) {
                $tz = trim($z[1]);
            }
            try {
                $v = $p[1];
                if (preg_match('/^\d{8}$/', $v)) {
                    return CarbonImmutable::createFromFormat('Ymd', $v, $tz)->startOfDay();
                }

                return str_ends_with($v, 'Z')
                    ? CarbonImmutable::createFromFormat('Ymd\THis\Z', $v, 'UTC')->setTimezone(config('app.timezone'))
                    : CarbonImmutable::createFromFormat('Ymd\THis', substr($v, 0, 15), $tz)->setTimezone(config('app.timezone'));
            } catch (\Throwable) {
                return null;
            }
        };
        $quem = function (?array $p): ?string {
            if (! $p) {
                return null;
            }
            $email = preg_replace('/^mailto:/i', '', $p[1]);

            return preg_match('/CN="?([^";:]+)"?/i', $p[0], $c) && trim($c[1]) !== $email ? trim($c[1]).' <'.$email.'>' : $email;
        };
        preg_match_all('/^ATTENDEE((?:;[^:\n]*)?):(.*)$/mi', $ics, $ps, PREG_SET_ORDER);
        $inicio = $prop('DTSTART');

        return [
            'metodo' => mb_strtoupper((string) ($prop('METHOD', $calendario)[1] ?? 'REQUEST')),
            'titulo' => $texto($prop('SUMMARY')) ?: null,
            'inicio' => $quando($inicio),
            'fim' => $quando($prop('DTEND')),
            'dia_inteiro' => $inicio !== null && strlen($inicio[1]) === 8,
            'local' => $texto($prop('LOCATION')) ?: null,
            'organizador' => $quem($prop('ORGANIZER')),
            'participantes' => array_values(array_filter(array_map(fn ($m) => $quem([$m[1], trim($m[2])]), $ps))),
            'descricao' => trim((string) $texto($prop('DESCRIPTION'))) ?: null,
        ];
    }

    /** O convite em texto legível ("📅 Convite de reunião: …"). */
    public static function resumoDoConvite(array $c): string
    {
        $linhas = [($c['metodo'] === 'CANCEL' ? '❌ Reunião cancelada' : '📅 Convite de reunião').': '.($c['titulo'] ?? '(sem título)')];
        if ($c['inicio']) {
            $linhas[] = 'Quando: '.\App\Services\Secretario\Datas::porExtenso($c['inicio'], $c['dia_inteiro'])
                .($c['fim'] && ! $c['dia_inteiro'] ? ' até '.$c['fim']->format('H:i') : '');
        }
        foreach (['local' => 'Onde', 'organizador' => 'Organizador'] as $k => $rot) {
            if ($c[$k]) {
                $linhas[] = $rot.': '.$c[$k];
            }
        }
        if ($c['participantes']) {
            $linhas[] = 'Participantes: '.implode(', ', array_slice($c['participantes'], 0, 15)).(count($c['participantes']) > 15 ? ' e mais '.(count($c['participantes']) - 15) : '');
        }
        if ($c['descricao']) {
            $linhas[] = "\n".$c['descricao'];
        }

        return implode("\n", $linhas);
    }

    private static function decodificarCru(string $texto, string $cte): string
    {
        return match (mb_strtolower(trim($cte))) {
            'base64' => (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $texto)),
            'quoted-printable' => quoted_printable_decode($texto),
            default => $texto,
        };
    }

    private static function decodificar(string $texto, string $cte, string $tipo): string
    {
        $cte = mb_strtolower(trim($cte));
        $texto = match ($cte) {
            'base64' => (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $texto)),
            'quoted-printable' => quoted_printable_decode($texto),
            default => $texto,
        };
        if (preg_match('/charset="?([A-Za-z0-9_-]+)"?/i', $tipo, $c) && ! in_array(mb_strtolower($c[1]), ['utf-8', 'utf8', 'us-ascii'], true)) {
            $convertido = @mb_convert_encoding($texto, 'UTF-8', $c[1]);
            $texto = $convertido !== false ? $convertido : $texto;
        }
        if (str_contains($tipo, 'html')) {
            $texto = html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div)[^>]*>/i', "\n", $texto)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $texto = mb_scrub($texto, 'UTF-8');

        return trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $texto))));
    }
}
