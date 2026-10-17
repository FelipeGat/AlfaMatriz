<?php

namespace App\Services\Secretario;

use App\Models\SecretarioItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * O resumo do dia por REGRA, sem IA (docs/specs/secretario-briefing). Só dado
 * das fontes, e o rodapé diz o que foi lido e o que ainda não existe — o
 * briefing nunca afirma "nada pendente" com uma fonte faltando (FR-006).
 */
class MontadorDeBriefing
{
    public const MAX_LINHAS = 8;
    public const MAX_CARACTERES = 3800;
    /** Fontes da missão que ainda não têm etapa pronta: o briefing diz que não entram. */
    public const AINDA_AUSENTES = ['e-mail', 'reuniões', 'Gestor'];

    public function __construct(private AgendaConsolidada $agenda) {}

    /** @return array{texto: string, fontes: array} */
    public function montar(User $user, CarbonImmutable $agora, bool $pedido = false): array
    {
        $hoje = $agora->startOfDay();
        $itensDoDia = $this->agenda->periodo($user, $hoje, $hoje->endOfDay());
        $situacao = $this->agenda->situacaoGoogle($user);
        $googleOk = $situacao['estado'] === 'ok';

        $saudacao = $pedido ? '📋 SEU DIA' : match (true) {
            $agora->hour < 12 => '☀️ BOM DIA',
            $agora->hour < 18 => '🌤 BOA TARDE',
            default => '🌙 BOA NOITE',
        };
        $partes = [$saudacao.' — '.Datas::porExtenso($hoje, true, $agora).($pedido ? ' (às '.$agora->format('H:i').')' : '')];

        // Agenda: sempre presente, com a limitação quando o Google faltou.
        // Prazo do dia (tarefa do Secretário sem hora) não é compromisso: fica em
        // "Para hoje"/"Atrasados", e não aqui também (resumo sem repetição).
        $agendaLinhas = $itensDoDia->reject(fn ($i) => $i['origem'] === 'secretario' && $i['dia_inteiro'])
            ->map(fn ($i) => $this->linhaDaAgenda($i, $agora))->all();
        $partes[] = "📅 Agenda de hoje\n".($agendaLinhas
            ? $this->limitar($agendaLinhas)
            : ($googleOk ? 'Nada na agenda de hoje.' : 'Nada nas fontes que consegui ler.'));
        $conflitos = $itensDoDia->where('conflito', true)->count();
        if ($conflitos > 0) {
            $partes[] = "⚠ {$conflitos} compromisso(s) em conflito de horário hoje.";
        }

        $abertos = SecretarioItem::de($user)->where('status', SecretarioItem::ABERTO)->orderBy('quando')->orderBy('id')->limit(500)->get();
        $atrasados = $abertos->filter(fn ($i) => $i->quando && ($i->so_data ? $i->quando->copy()->endOfDay() : $i->quando)->lessThan($agora));
        // Só o que é do dia SEM hora: o que tem hora já está na agenda acima, e
        // a missão pede o resumo "sem informações repetidas".
        $deHoje = $abertos->reject(fn ($i) => $atrasados->contains($i))->filter(fn ($i) => $i->so_data && $i->quando?->isSameDay($agora));
        $semData = $abertos->filter(fn ($i) => ! $i->quando);

        if ($atrasados->isNotEmpty()) {
            $partes[] = '⚠ Atrasados ('.$atrasados->count().")\n".$this->limitar($atrasados->map(fn ($i) => '• '.$i->titulo.' (desde '.Datas::porExtenso($i->quando, $i->so_data, $agora).')')->all());
        }
        if ($deHoje->isNotEmpty()) {
            $partes[] = "✅ Para hoje (sem hora marcada)\n".$this->limitar($deHoje->map(fn ($i) => '• '.$i->titulo)->all());
        }
        if ($semData->isNotEmpty()) {
            $partes[] = '📌 Pendências sem data ('.$semData->count().")\n".$this->limitar($semData->map(fn ($i) => '• '.$i->titulo)->all());
        }

        if ($sugestao = $this->sugestao($itensDoDia, $hoje, $agora, $atrasados, $semData, $googleOk)) {
            $partes[] = '💡 '.$sugestao;
        }

        $usadas = array_values(array_filter([
            $googleOk ? 'Google (lido às '.$situacao['sincronizado_em']->format('H:i').')' : null,
            // Só diz que leu a Agenda do time quem pode lê-la (FR-006).
            $user->canPermissao('agenda', 'ler') ? 'Agenda do Matriz' : null, 'Secretário',
        ]));
        $rodape = 'Fontes: '.implode(', ', $usadas).'.';
        if ($aviso = $this->agenda->aviso($situacao)) {
            $rodape = $aviso."\n".$rodape;
        }
        $rodape .= "\nAinda não entram: ".implode(', ', self::AINDA_AUSENTES).'.';
        $partes[] = $rodape;

        $texto = implode("\n\n", $partes);
        if (mb_strlen($texto) > self::MAX_CARACTERES) {
            $texto = mb_substr($texto, 0, self::MAX_CARACTERES - 60)."\n… (o resto está em Secretário → Meu Dia)";
        }

        return ['texto' => $texto, 'fontes' => [
            'usadas' => $usadas, 'ausentes' => self::AINDA_AUSENTES,
            'google' => ['estado' => $situacao['estado'], 'sincronizado_em' => $situacao['sincronizado_em']?->format('Y-m-d H:i')],
        ]];
    }

    private function linhaDaAgenda(array $i, CarbonImmutable $agora): string
    {
        $hora = $i['dia_inteiro'] ? 'dia inteiro' : $i['inicio']->format('H:i').($i['fim'] ? '–'.$i['fim']->format('H:i') : '');
        $passou = ! $i['dia_inteiro'] && ($i['fim'] ?? $i['inicio'])->lessThan($agora) ? ' (já passou)' : '';

        return "{$hora} {$i['titulo']} ({$i['origem_rotulo']})".($i['conflito'] ? ' ⚠ conflito' : '').$passou;
    }

    /**
     * Por regra (FR-005): o maior livre que ainda vem, de 30 min ou mais, para
     * o atrasado mais antigo — ou a primeira pendência sem data. Sem Google em
     * dia, o "livre" não é confiável: sem sugestão.
     */
    private function sugestao(Collection $itensDoDia, CarbonImmutable $hoje, CarbonImmutable $agora, Collection $atrasados, Collection $semData, bool $googleOk): ?string
    {
        if (! $googleOk) {
            return null;
        }
        $alvo = $atrasados->first() ?? $semData->first();
        if (! $alvo) {
            return null;
        }
        $livres = collect($this->agenda->livres($itensDoDia, $hoje))
            ->map(fn ($l) => [$l[0]->greaterThan($agora) ? $l[0] : $agora->setSecond(0), $l[1]])
            ->filter(fn ($l) => $l[0]->diffInMinutes($l[1], false) >= AgendaConsolidada::LIVRE_MIN);
        $maior = $livres->sortByDesc(fn ($l) => $l[0]->diffInMinutes($l[1]))->first();
        if (! $maior) {
            return null;
        }

        return 'Livre '.$maior[0]->format('H:i').'–'.$maior[1]->format('H:i').': bom momento para "'.Str::limit($alvo->titulo, 80).'".';
    }

    /** @param list<string> $linhas */
    private function limitar(array $linhas): string
    {
        $mostrar = array_slice($linhas, 0, self::MAX_LINHAS);
        if (count($linhas) > self::MAX_LINHAS) {
            $mostrar[] = '+'.(count($linhas) - self::MAX_LINHAS).' no Meu Dia';
        }

        return implode("\n", $mostrar);
    }
}
