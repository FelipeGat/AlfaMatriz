<?php

namespace App\Services\Secretario;

use App\Models\SecretarioGoogleConta;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Quais compromissos tenho amanhã?" e "tenho horário livre amanhã às 15h?"
 * respondidos pelo Matriz, com dado real e sem IA (spec US3, FR-012). Só
 * entra com o Google conectado: sem ele, a Central segue respondendo pela
 * agenda do runner, como hoje.
 */
class ConsultaDeAgenda
{
    private const ASSUNTO = '/\b(compromissos?|agenda|reunioes|reuniao|eventos?|calls?)\b/u';
    private const PERGUNTA = '/^(quais|qual|o\s+que|oque|tenho|tem|como|minha|meus|meu|me\s+(mostra|passa|diz|fala)|mostra|lista|ver|resume|resumo)\b|\?\s*$/u';
    private const LIVRE = '/\b(livre|disponivel|vago|desocupado)\b/u';

    public function __construct(private AgendaConsolidada $agenda, private Interpretador $interpretador) {}

    public function ativa(User $user): bool
    {
        return SecretarioGoogleConta::where('user_id', $user->id)->whereIn('situacao', [SecretarioGoogleConta::CONECTADA, SecretarioGoogleConta::MAQUINA])->exists();
    }

    /** O texto da resposta, ou null se a frase não é pergunta de agenda. */
    public function responder(User $user, string $texto, ?CarbonImmutable $agora = null): ?string
    {
        if (! $this->ativa($user)) {
            return null;
        }
        $agora ??= CarbonImmutable::now();
        $t = $this->normalizar($texto);
        // "agenda uma reunião…" é pedido de marcar, não pergunta.
        if (preg_match('/^(agend|marc)/u', $t) || ! preg_match(self::PERGUNTA, $t)) {
            return null;
        }
        if (preg_match(self::LIVRE, $t) && ($q = $this->interpretador->soQuando($t, $agora)) && ! $q['so_data']) {
            return $this->livreAs($user, CarbonImmutable::parse($q['quando']), $agora);
        }
        if (! preg_match(self::ASSUNTO, $t)) {
            return null;
        }
        if (preg_match('/\b(esta|essa|nesta|nessa|da|a)\s+semana\b|\bsemana\b/u', $t)) {
            return $this->listar($user, $agora->startOfDay(), 7, $agora);
        }
        $dia = ($q = $this->interpretador->soQuando($t, $agora)) ? CarbonImmutable::parse($q['quando'])->startOfDay() : $agora->startOfDay();

        return $this->listar($user, $dia, 1, $agora);
    }

    private function listar(User $user, CarbonImmutable $inicio, int $dias, CarbonImmutable $agora): string
    {
        $fim = $inicio->addDays($dias)->subSecond();
        $itens = $this->agenda->periodo($user, $inicio, $fim);
        $situacao = $this->agenda->situacaoGoogle($user);
        $titulo = $dias === 1 ? '📅 '.ucfirst(Datas::porExtenso($inicio, true, $agora)) : '📅 Próximos 7 dias';
        $linhas = [$titulo];

        if ($itens->isEmpty()) {
            $linhas[] = $situacao['estado'] === 'ok' ? 'Nada na agenda.' : 'Nada encontrado nas fontes que consegui ler.';
        }
        foreach ($itens->groupBy(fn ($i) => $i['inicio']->format('Y-m-d')) as $data => $doDia) {
            if ($dias > 1) {
                $linhas[] = "\n".ucfirst(Datas::porExtenso(CarbonImmutable::parse($data), true, $agora));
            }
            foreach ($doDia as $i) {
                $linhas[] = '• '.$this->linha($i);
            }
            if ($dias === 1 && $inicio->greaterThanOrEqualTo($agora->startOfDay())) {
                $livres = $this->agenda->livres($doDia, $inicio);
                if ($livres) {
                    $linhas[] = 'Livre: '.implode(', ', array_map(fn ($l) => $l[0]->format('H:i').'–'.$l[1]->format('H:i'), $livres));
                }
            }
        }
        if ($dias === 1 && $itens->isEmpty() && $situacao['estado'] === 'ok') {
            $linhas[] = 'Livre: 08:00–18:00';
        }

        return implode("\n", array_merge($linhas, [$this->rodape($user, $situacao)]));
    }

    private function livreAs(User $user, CarbonImmutable $hora, CarbonImmutable $agora): string
    {
        $dia = $this->agenda->periodo($user, $hora->startOfDay(), $hora->endOfDay());
        $fim = $hora->addMinutes(60);
        $ocupando = $dia->filter(fn ($i) => $i['ocupa'] && $i['inicio']->lessThan($fim) && $hora->lessThan($i['fim']));
        $situacao = $this->agenda->situacaoGoogle($user);
        $quando = Datas::porExtenso($hora, false, $agora);

        if ($ocupando->isEmpty()) {
            $resposta = $situacao['estado'] === 'ok'
                ? "✅ Sim: {$quando} está livre (considerei 1 hora)."
                : "Nas fontes que consegui ler, {$quando} está livre (considerei 1 hora) — mas veja o aviso abaixo.";
        } else {
            $livres = $this->agenda->livres($dia, $hora->startOfDay());
            $proximo = collect($livres)->first(fn ($l) => $l[1]->greaterThan($hora));
            $resposta = "❌ Não: {$quando} está ocupado.\n".$ocupando->map(fn ($i) => '• '.$this->linha($i))->implode("\n")
                .($proximo ? "\nLivre mais perto: ".$proximo[0]->format('H:i').'–'.$proximo[1]->format('H:i').'.' : "\nSem livre de 30 min entre 08:00 e 18:00 nesse dia.");
        }

        return $resposta."\n".$this->rodape($user, $situacao);
    }

    private function linha(array $i): string
    {
        $hora = $i['dia_inteiro'] ? 'dia inteiro' : ($i['inicio']->format('H:i').($i['fim'] ? '–'.$i['fim']->format('H:i') : ''));

        return "{$hora} {$i['titulo']} — {$i['origem_rotulo']}".($i['conflito'] ? ' ⚠ conflito' : '');
    }

    private function rodape(User $user, array $situacao): string
    {
        $google = match ($situacao['estado']) {
            'ok' => 'Google (lido às '.$situacao['sincronizado_em']->format('H:i').')',
            default => null,
        };
        $fontes = 'Fontes: '.implode(', ', array_filter([$google, $user->canPermissao('agenda', 'ler') ? 'Agenda do Matriz' : null, 'Secretário'])).'.';

        return trim(($this->agenda->aviso($situacao) ?? '')."\n".$fontes);
    }

    private function normalizar(string $t): string
    {
        $t = mb_strtolower(trim($t));
        $t = strtr($t, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);

        return preg_replace('/^(matriz|secretario)[,:!]?\s+/u', '', $t);
    }
}
