<?php

namespace App\Services\Secretario;

use App\Models\SecretarioAcompanhamento;
use App\Models\SecretarioEmail;
use App\Models\SecretarioItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * O que o Meu Dia mostra além das listas de itens: compromissos de hoje,
 * os acompanhamentos mais urgentes, os e-mails recentes e as anotações
 * rápidas. Tudo da própria conta, sem IA.
 */
class PainelDoDia
{
    public function __construct(private AgendaConsolidada $agenda, private CentralDeGestao $central) {}

    public function montar(User $user, CarbonImmutable $agora): array
    {
        $doDia = $this->agenda->periodo($user, $agora->startOfDay(), $agora->endOfDay())
            ->reject(fn ($i) => $i['origem'] === 'secretario' && $i['dia_inteiro'])->values();

        return [
            'compromissos' => $doDia->map(fn ($i) => $i + ['quanto' => $this->quanto($i, $agora)])->all(),
            'contagem' => [
                'compromissos' => $doDia->count(),
                'pendencias' => $this->central->vencidas($user, $agora)->count(),
                'concluidos' => SecretarioItem::de($user)->where('status', SecretarioItem::CONCLUIDO)->where('origem', '!=', 'anotacao')
                    ->where('concluido_em', '>=', $agora->startOfDay())->count(),
            ],
            'urgentes' => $this->urgentes($user, $agora),
            'emailsRecentes' => SecretarioEmail::de($user)->with('conta')->where('enviado', false)->orderByDesc('recebido_em')->limit(5)->get(),
            'anotacoes' => SecretarioItem::de($user)->where('origem', 'anotacao')->where('status', SecretarioItem::ABERTO)->latest('id')->limit(5)->get(),
        ];
    }

    /** "Em 3h20min", "agora", "encerrado" — para a linha do compromisso. */
    public function quanto(array $i, CarbonImmutable $agora): string
    {
        if ($i['dia_inteiro']) {
            return 'dia inteiro';
        }
        $fim = $i['fim'] ?? $i['inicio']->addHour();
        if ($agora->greaterThanOrEqualTo($i['inicio']) && $agora->lessThan($fim)) {
            return 'agora';
        }
        if ($agora->greaterThanOrEqualTo($fim)) {
            return 'encerrado';
        }
        $min = (int) ceil($agora->diffInMinutes($i['inicio'], true));

        return 'em '.($min >= 60 ? intdiv($min, 60).'h'.($min % 60 ? str_pad((string) ($min % 60), 2, '0', STR_PAD_LEFT).'min' : '') : $min.'min');
    }

    /** Até 5: pontos de gestão e alertas, os de prazo mais perto primeiro. */
    private function urgentes(User $user, CarbonImmutable $agora): Collection
    {
        $pontos = SecretarioItem::de($user)->whereNotNull('empresa')->where('status', SecretarioItem::ABERTO)->get()
            ->map(fn ($i) => ['titulo' => $i->titulo, 'sub' => CentralDeGestao::EMPRESAS[$i->empresa] ?? $i->empresa, 'prioridade' => $i->prioridade,
                'quando' => $i->quando ? CarbonImmutable::instance($i->quando) : null, 'link' => route('secretario.acompanhamentos')]);
        $alertas = SecretarioAcompanhamento::de($user)->ativos()->get()
            ->map(fn ($a) => ['titulo' => $a->texto, 'sub' => \App\Services\Secretario\Acompanhamento\Regras::REGRAS[$a->regra][0] ?? $a->regra,
                'prioridade' => $a->prioridade, 'quando' => $a->referencia_em ? CarbonImmutable::instance($a->referencia_em) : null, 'link' => $a->link ?: route('secretario.acompanhamentos')]);
        $peso = ['alta' => 0, 'normal' => 1, 'baixa' => 2];

        return $pontos->concat($alertas)
            ->sortBy(fn ($x) => sprintf('%d%s', $peso[$x['prioridade']] ?? 1, $x['quando']?->format('YmdHi') ?? '99999999'))
            ->take(5)->values()->map(fn ($x) => $x + ['prazo' => self::prazo($x['quando'], $agora)]);
    }

    /** "Hoje", "Amanhã", "20/10", "Vencido" — o rótulo curto de prazo das listas. */
    public static function prazo(?CarbonImmutable $quando, CarbonImmutable $agora): ?string
    {
        return match (true) {
            $quando === null => null,
            $quando->isSameDay($agora) => 'Hoje',
            $quando->isSameDay($agora->addDay()) => 'Amanhã',
            $quando->lessThan($agora->startOfDay()) => 'Vencido',
            default => $quando->format('d/m'),
        };
    }
}
