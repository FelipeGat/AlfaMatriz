<?php

namespace App\Services\Secretario;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Lê "me lembra amanhã às 9h de ligar para o Alexandre" SEM IA (research D7).
 *
 * Por que regras e não IA: o lembrete precisa funcionar com a máquina do dono
 * desligada (spec SC-006) e sem gastar uma execução por frase. O vocabulário é
 * pequeno e fechado; o que as regras não entendem vai à IA dele, e mesmo
 * assim volta como proposta a confirmar.
 *
 * Nunca inventa: sem "quando" num lembrete, devolve `falta`. Nome de pessoa
 * fica como foi dito — não é ligado a cadastro nenhum.
 */
class Interpretador
{
    public const NAO_E = 'nao_e';
    public const FALTA = 'falta';
    public const PROPOSTA = 'proposta';

    private const DIAS = [
        'segunda' => 1, 'terca' => 2, 'quarta' => 3, 'quinta' => 4, 'sexta' => 5, 'sabado' => 6, 'domingo' => 7,
    ];

    private const NUMEROS = [
        'um' => 1, 'uma' => 1, 'dois' => 2, 'duas' => 2, 'tres' => 3, 'quatro' => 4, 'cinco' => 5, 'seis' => 6,
        'sete' => 7, 'oito' => 8, 'nove' => 9, 'dez' => 10, 'quinze' => 15, 'vinte' => 20, 'trinta' => 30,
        'quarenta' => 40, 'quarenta e cinco' => 45,
    ];

    private const COMPROMISSO = '/\b(reuniao|reunioes|call|visita|almoco|jantar|consulta|encontro|apresentacao|audiencia)\b/u';

    /** "Matriz, …" e as formas de pedir ao Secretário um lembrete. */
    private const GATILHO_LEMBRETE = '/\b(me\s+lembr(?:a|e|ar)|lembr(?:a|e)-?me|lembra\s+de\s+me|me\s+avis(?:a|e)|cria\s+um\s+lembrete|crie\s+um\s+lembrete|lembrete)\b/u';
    private const GATILHO_ANOTAR = '/^(?:anot(?:a|e|ar)|registr(?:a|e))\b|\banot(?:a|e)\s+(?:a[ií]\s+)?(?:que|pra|para)\b/u';
    private const GATILHO_PRECISO = '/^(?:eu\s+)?(?:preciso|tenho\s+que|tenho\s+de)\b/u';
    /** "Agenda uma reunião…": só vale com o Google conectado (Etapa 2); sem ele, segue para a Central. */
    private const GATILHO_AGENDAR = '/^(?:agend(?:a|e|ar)|marc(?:a|e|ar)|marque)\b/u';

    /**
     * @param  bool  $explicito  o texto veio do campo do próprio Secretário (Meu Dia): não precisa de gatilho
     * @return array{resultado: string, dados?: array, falta?: string, avisos?: list<string>}
     */
    public function interpretar(string $texto, CarbonImmutable $agora, bool $explicito = false, bool $agendar = false): array
    {
        $original = trim(preg_replace('/\s+/u', ' ', $texto));
        if ($original === '' || mb_strlen($original) > 500) {
            return ['resultado' => self::NAO_E];
        }
        // Minúsculo e sem acento para casar; o título sai do texto original.
        $norm = $this->normalizar($original);
        $norm = preg_replace('/^(matriz|secretario)[,:!]?\s+/u', '', $norm);
        if (str_starts_with($norm, '/lembrete')) {
            $norm = 'me lembra '.trim(substr($norm, 9));
        }

        // "das 14h às 15h30" / "por 30 min": o fim do evento. Sai da frase antes
        // de ler a hora, senão "às 15h30" seria lido como o início.
        $fimDito = null;
        $duracao = null;
        if ($agendar && preg_match(self::GATILHO_AGENDAR, $norm)) {
            if (preg_match('/\bd[ae]s?\s+(\d{1,2})(?:h|:)?(\d{2})?\s+(?:as|ate\s+as|ate|a)\s+(\d{1,2})(?:h|:)?(\d{2})?(?:h)?\b/u', $norm, $m)) {
                $fimDito = sprintf('%02d:%02d', (int) $m[3], (int) ($m[4] ?? 0));
                $norm = str_replace($m[0], sprintf('as %02d:%02d', (int) $m[1], (int) ($m[2] ?? 0)), $norm);
            } elseif (preg_match('/\b(?:por|durante|de)\s+(\d{1,3}|uma|meia)\s*(minutos?|min|horas?|h)\b(?:\s+e\s+meia)?/u', $norm, $m)) {
                $n = $m[1] === 'uma' ? 1 : ($m[1] === 'meia' ? 0.5 : (int) $m[1]);
                $duracao = (int) round(str_starts_with($m[2], 'h') ? $n * 60 + (str_ends_with($m[0], 'e meia') ? 30 : 0) : $n);
                $norm = trim(str_replace($m[0], ' ', $norm));
            }
        }

        $tipo = match (true) {
            $agendar && (bool) preg_match(self::GATILHO_AGENDAR, $norm) => 'evento_google',
            (bool) preg_match(self::GATILHO_LEMBRETE, $norm) => 'lembrete',
            (bool) preg_match(self::GATILHO_ANOTAR, $norm) => 'tarefa',
            (bool) preg_match(self::GATILHO_PRECISO, $norm) => 'tarefa_se_data',
            $explicito => 'livre',
            default => null,
        };
        if ($tipo === null) {
            return ['resultado' => self::NAO_E];
        }

        $avisos = [];
        $removidos = [];
        $tempo = $this->tempo($norm, $agora, $removidos, $avisos);

        if ($tipo === 'tarefa_se_data') {
            // "Preciso…" sem data é conversa, não registro: segue para a Central.
            if (! $tempo['tem_data'] && ! $tempo['relativo'] && ! $tempo['recorrencia']) {
                return ['resultado' => self::NAO_E];
            }
            $tipo = 'tarefa';
        }
        if ($tipo === 'livre') {
            $tipo = ($tempo['hora'] !== null && preg_match(self::COMPROMISSO, $norm)) ? 'compromisso'
                : (($tempo['hora'] !== null || $tempo['relativo']) ? 'lembrete' : 'tarefa');
        }

        $titulo = $this->titulo($original, $norm, $removidos);
        if ($tipo === 'evento_google') {
            $titulo = Str::ucfirst(preg_replace('/^(?:uma?|o|a)\s+/iu', '', $titulo));
        }

        $dados = [
            'tipo' => $tipo,
            'titulo' => $titulo,
            'quando' => $tempo['quando']?->format('Y-m-d H:i:s'),
            'so_data' => $tempo['quando'] !== null && $tempo['hora'] === null && ! $tempo['relativo'],
            'recorrencia' => $tempo['recorrencia'],
            'inequivoco' => $tempo['inequivoco'],
        ];
        if ($tipo === 'evento_google') {
            $dados['fim'] = null;
            if ($tempo['quando'] && $tempo['hora'] !== null) {
                $fim = $fimDito ? $tempo['quando']->setTimeFromTimeString($fimDito) : $tempo['quando']->addMinutes($duracao ?? 60);
                $dados['fim'] = ($fim->greaterThan($tempo['quando']) ? $fim : $tempo['quando']->addMinutes(60))->format('Y-m-d H:i:s');
            }
            $dados['so_data'] = false;
        }

        if ($titulo === '') {
            return ['resultado' => self::FALTA, 'falta' => 'titulo', 'dados' => $dados, 'avisos' => $avisos];
        }
        // Lembrete sem quando: perguntar, nunca chutar (FR-009). Evento precisa de hora.
        if ((in_array($tipo, ['lembrete', 'compromisso'], true) && $tempo['quando'] === null)
            || ($tipo === 'evento_google' && $tempo['hora'] === null && ! $tempo['relativo'])) {
            return ['resultado' => self::FALTA, 'falta' => 'quando', 'dados' => $dados, 'avisos' => $avisos];
        }
        if ($tempo['recorrencia'] && $tempo['hora'] === null) {
            return ['resultado' => self::FALTA, 'falta' => 'quando', 'dados' => $dados, 'avisos' => $avisos];
        }

        return ['resultado' => self::PROPOSTA, 'dados' => $dados, 'avisos' => $avisos];
    }

    /**
     * Só o "quando" de uma frase ("quinta às 15h", "daqui a 1 hora") — para
     * reagendar e para responder à pergunta "quando?".
     */
    public function soQuando(string $texto, CarbonImmutable $agora): ?array
    {
        $removidos = [];
        $avisos = [];
        $tempo = $this->tempo($this->normalizar($texto), $agora, $removidos, $avisos);
        if ($tempo['quando'] === null) {
            return null;
        }

        return [
            'quando' => $tempo['quando']->format('Y-m-d H:i:s'),
            'so_data' => $tempo['hora'] === null && ! $tempo['relativo'],
            'recorrencia' => $tempo['recorrencia'],
            'inequivoco' => $tempo['inequivoco'],
            'avisos' => $avisos,
        ];
    }

    private function normalizar(string $texto): string
    {
        $t = mb_strtolower($texto);
        $t = strtr($t, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);

        return trim(preg_replace('/\s+/u', ' ', $t));
    }

    /**
     * @param  list<string>  $removidos  trechos de tempo achados (saem do título)
     * @param  list<string>  $avisos  o que a proposta precisa dizer (hora ajustada, data no ano seguinte)
     * @return array{quando: ?CarbonImmutable, hora: ?string, tem_data: bool, relativo: bool, recorrencia: ?array, inequivoco: bool}
     */
    private function tempo(string $t, CarbonImmutable $agora, array &$removidos, array &$avisos): array
    {
        $agora = $agora->setSecond(0)->setMicrosecond(0);
        $inequivoco = true;
        $num = '(\d{1,3}|'.implode('|', array_map('preg_quote', array_keys(self::NUMEROS))).')';

        // 1. Relativo: "daqui a 10 minutos", "em 2 horas", "daqui a meia hora".
        if (preg_match('/\b(?:daqui\s+a|daqui|em|dentro\s+de)\s+meia\s+hora\b/u', $t, $m)) {
            $removidos[] = $m[0];

            return ['quando' => $agora->addMinutes(30), 'hora' => null, 'tem_data' => false, 'relativo' => true, 'recorrencia' => null, 'inequivoco' => true];
        }
        if (preg_match('/\b(?:daqui\s+a|daqui|em|dentro\s+de)\s+'.$num.'\s*(minutos?|mins?|horas?|h|dias?)\b(?:\s+e\s+meia)?/u', $t, $m)) {
            $removidos[] = $m[0];
            $n = ctype_digit($m[1]) ? (int) $m[1] : self::NUMEROS[$m[1]];
            $unidade = $m[2][0];
            $quando = match ($unidade) {
                'm' => $agora->addMinutes($n),
                'h' => $agora->addMinutes($n * 60 + (str_ends_with($m[0], 'e meia') ? 30 : 0)),
                default => $agora->addDays($n),
            };
            // "daqui a 2 dias às 10h" ainda pode trazer hora.
            $hora = $unidade === 'd' ? $this->hora($t, $removidos, $avisos) : null;
            if ($hora) {
                $quando = $quando->setTimeFromTimeString($hora);
            }

            return ['quando' => $quando, 'hora' => $hora, 'tem_data' => $unidade === 'd', 'relativo' => $unidade !== 'd', 'recorrencia' => null,
                'inequivoco' => $unidade !== 'd' || $hora !== null];
        }

        // 2. Recorrência.
        $recorrencia = null;
        $diasRegex = 'segunda|terca|quarta|quinta|sexta|sabado|domingo';
        if (preg_match('/\b(?:todo\s+dia|todos\s+os\s+dias|diariamente)\b(?!\s+\d)/u', $t, $m)) {
            $recorrencia = ['freq' => 'diaria'];
            $removidos[] = $m[0];
        } elseif (preg_match('/\btod[ao]s?\s+(?:as\s+|os\s+)?((?:'.$diasRegex.')s?(?:-feiras?)?(?:\s*(?:,|e)\s*(?:as\s+|os\s+)?(?:'.$diasRegex.')s?(?:-feiras?)?)*)/u', $t, $m)) {
            preg_match_all('/'.$diasRegex.'/u', $m[1], $d);
            $recorrencia = ['freq' => 'semanal', 'dias' => array_values(array_unique(array_map(fn ($x) => self::DIAS[$x], $d[0])))];
            sort($recorrencia['dias']);
            $removidos[] = $m[0];
        } elseif (preg_match('/\btodo\s+dia\s+(\d{1,2})(?:\s+de\s+cada\s+mes|\s+do\s+mes)?\b/u', $t, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 31) {
            $recorrencia = ['freq' => 'mensal', 'dia_mes' => (int) $m[1]];
            $removidos[] = $m[0];
        } elseif (preg_match('/\b(?:toda\s+semana|semanalmente)\b/u', $t, $m)) {
            $recorrencia = ['freq' => 'semanal', 'dias' => [$agora->dayOfWeekIso]];
            $removidos[] = $m[0];
        }

        $hora = $this->hora($t, $removidos, $avisos);
        if ($hora !== null && in_array('ajustada', $avisos, true)) {
            $inequivoco = false;
        }

        if ($recorrencia) {
            $quando = $hora ? Recorrencia::proxima($recorrencia, $agora, $hora) : null;

            return ['quando' => $quando, 'hora' => $hora, 'tem_data' => true, 'relativo' => false, 'recorrencia' => $recorrencia, 'inequivoco' => $inequivoco];
        }

        // 3. Data.
        $data = null;
        $hojeDito = false;
        if (preg_match('/\bdepois\s+de\s+amanha\b/u', $t, $m)) {
            $data = $agora->addDays(2)->startOfDay();
            $removidos[] = $m[0];
        } elseif (preg_match('/\bamanha\b(?:\s+(?:de\s+manha|cedo))?/u', $t, $m)) {
            $data = $agora->addDay()->startOfDay();
            $removidos[] = $m[0];
            if (str_contains($m[0], 'manha') && $hora === null) {
                // "amanhã de manhã" sem hora: o padrão do dia, dito na proposta.
                $hora = '08:00';
                $inequivoco = false;
            }
        } elseif (preg_match('/\bhoje\b(?:\s+a\s+noite|\s+a\s+tarde)?/u', $t, $m)) {
            $data = $agora->startOfDay();
            $hojeDito = true;
            $removidos[] = $m[0];
        } elseif (preg_match('/\b(?:(?:na|no|nesta|neste|nessa|nesse|proxima|proximo|esta|este)\s+)?('.$diasRegex.')(?:-feira)?(?:\s+que\s+vem)?\b/u', $t, $m)) {
            $alvo = self::DIAS[$m[1]];
            $dias = ($alvo - $agora->dayOfWeekIso + 7) % 7;
            // Dia da semana igual a hoje é a PRÓXIMA semana (spec, Edge Cases).
            $data = $agora->addDays($dias === 0 ? 7 : $dias)->startOfDay();
            $removidos[] = $m[0];
            if ($dias === 0) {
                $avisos[] = 'proxima_semana';
            }
        } elseif (preg_match('/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/u', $t, $m) && checkdate((int) $m[2], (int) $m[1], (int) ($m[3] ?? $agora->year) < 100 ? 2000 + (int) ($m[3] ?? 0) : (int) ($m[3] ?? $agora->year))) {
            $ano = isset($m[3]) ? ((int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3]) : $agora->year;
            $data = CarbonImmutable::create($ano, (int) $m[2], (int) $m[1], 0, 0, 0, $agora->timezone);
            if (! isset($m[3]) && $data->lessThan($agora->startOfDay())) {
                $data = $data->addYear();
                $avisos[] = 'ano_seguinte';
                $inequivoco = false;
            }
            $removidos[] = $m[0];
        } elseif (preg_match('/\b(?:no\s+|ate\s+o\s+|para\s+o\s+)?dia\s+(\d{1,2})\b/u', $t, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 31) {
            $dia = (int) $m[1];
            $c = $agora->startOfMonth();
            $data = $dia <= $c->daysInMonth ? $c->setDay($dia) : null;
            if (! $data || $data->lessThan($agora->startOfDay())) {
                $prox = $c->addMonthNoOverflow();
                $data = $prox->setDay(min($dia, $prox->daysInMonth));
            }
            $removidos[] = $m[0];
        }

        if ($data === null && $hora === null) {
            return ['quando' => null, 'hora' => null, 'tem_data' => false, 'relativo' => false, 'recorrencia' => null, 'inequivoco' => false];
        }

        if ($data === null) {
            // Só hora: hoje, se ainda não passou; senão amanhã (e a proposta diz).
            $quando = $agora->setTimeFromTimeString($hora);
            if ($quando->lessThanOrEqualTo($agora)) {
                $quando = $quando->addDay();
                $avisos[] = 'amanha_porque_passou';
                $inequivoco = false;
            }

            return ['quando' => $quando, 'hora' => $hora, 'tem_data' => false, 'relativo' => false, 'recorrencia' => null, 'inequivoco' => $inequivoco];
        }

        if ($hora === null) {
            // Só data: prazo do dia, avisado às 08:00 (research D7).
            return ['quando' => $data, 'hora' => null, 'tem_data' => true, 'relativo' => false, 'recorrencia' => null, 'inequivoco' => false];
        }

        $quando = $data->setTimeFromTimeString($hora);
        if ($quando->lessThanOrEqualTo($agora)) {
            // "Hoje às 8h" às 10h: não grava no passado — propõe amanhã e pergunta.
            $quando = $quando->addDay();
            $avisos[] = $hojeDito ? 'hoje_ja_passou' : 'amanha_porque_passou';
            $inequivoco = false;
        }

        return ['quando' => $quando, 'hora' => $hora, 'tem_data' => true, 'relativo' => false, 'recorrencia' => null, 'inequivoco' => $inequivoco];
    }

    /** "às 9h", "9h30", "15:00", "meio-dia", "às 7 da noite" → "HH:MM". */
    private function hora(string $t, array &$removidos, array &$avisos): ?string
    {
        if (preg_match('/\b(?:as\s+|ao\s+|a\s+partir\s+do\s+|ate\s+o\s+)?meio[\s-]dia(?:\s+e\s+meia)?\b/u', $t, $m)) {
            $removidos[] = $m[0];

            return str_contains($m[0], 'meia') ? '12:30' : '12:00';
        }
        if (preg_match('/\b(?:as\s+|a\s+)?meia[\s-]noite\b/u', $t, $m)) {
            $removidos[] = $m[0];

            return '00:00';
        }
        $periodo = '(?:\s+(da\s+manha|de\s+manha|da\s+tarde|a\s+tarde|de\s+tarde|da\s+noite|a\s+noite|de\s+noite))?';
        if (! preg_match('/\b(?:as|ate\s+as|la\s+pelas|pelas|umas)\s+(\d{1,2})(?:(?:h|:)(\d{2})?|\s*horas?)?(?:\s+e\s+meia)?'.$periodo.'\b/u', $t, $m)
            && ! preg_match('/\b(\d{1,2})(?:h(\d{2})?|:(\d{2}))(?:\s+e\s+meia)?'.$periodo.'\b/u', $t, $m)) {
            return null;
        }
        // O padrão sem "às" precisa de h ou ":" — "dia 15" não é hora.
        $h = (int) $m[1];
        $min = (int) (($m[2] ?? '') !== '' ? $m[2] : (($m[3] ?? '') !== '' && ctype_digit($m[3]) ? $m[3] : 0));
        if (str_contains($m[0], 'e meia')) {
            $min = 30;
        }
        $per = '';
        foreach ($m as $i => $g) {
            if ($i > 0 && is_string($g) && preg_match('/manha|tarde|noite/u', $g)) {
                $per = $g;
            }
        }
        if ($h > 23 || $min > 59) {
            return null;
        }
        if ($per !== '' && ! str_contains($per, 'manha') && $h < 12) {
            $h += 12;
        } elseif ($per === '' && $h >= 1 && $h <= 6) {
            // "às 3" sem período é de tarde na rotina de trabalho; a proposta
            // mostra 15:00 e quem quis 03:00 corrige (spec, Edge Cases).
            $h += 12;
            $avisos[] = 'ajustada';
        }
        $removidos[] = $m[0];

        return sprintf('%02d:%02d', $h, $min);
    }

    /** O que sobra da frase sem o gatilho e sem os trechos de tempo, com a caixa original. */
    private function titulo(string $original, string $norm, array $removidos): string
    {
        $t = $norm;
        foreach ($removidos as $r) {
            $t = str_replace($r, ' ', $t);
        }
        $t = preg_replace([
            '/^(matriz|secretario)[,:!]?\s+/u',
            self::GATILHO_LEMBRETE, self::GATILHO_ANOTAR, self::GATILHO_PRECISO, self::GATILHO_AGENDAR,
            '/^\s*(?:por\s+favor|pfv|pf)\b/u',
        ], ' ', $t);
        $t = trim(preg_replace('/\s+/u', ' ', $t), " ,.;:!?-");
        // Conectores que sobram no começo ("de ligar", "que tenho que", "para").
        do {
            $antes = $t;
            $t = preg_replace('/^(?:de|do|da|que|pra|para|a|ai|aí|eu|tenho\s+que|preciso|e|,)\s+/u', '', $t);
            $t = preg_replace('/\s+(?:de|do|da|a|e|para|pra|as)$/u', '', $t);
        } while ($t !== $antes);
        $t = trim($t, " ,.;:!?-");
        if ($t === '') {
            return '';
        }

        // Volta para a caixa original: acha a mesma sequência no texto dito.
        $pos = mb_stripos($this->normalizar($original), $t);
        $titulo = $pos !== false ? mb_substr(trim(preg_replace('/\s+/u', ' ', $original)), $pos, mb_strlen($t)) : $t;

        return Str::limit(Str::ucfirst(trim($titulo)), 200, '…');
    }
}
