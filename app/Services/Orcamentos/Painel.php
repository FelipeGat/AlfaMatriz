<?php

namespace App\Services\Orcamentos;

use App\Models\Orcamento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Os números da tela de orçamentos (Felipe, 20/10/2026: "nossa tela está sem vida,
 * sem KPI"): indicadores, evolução por dia, situações e top clientes, sempre sobre
 * as versões VIGENTES (a revisão substituída não conta duas vezes) do período.
 *
 * A data de cada orçamento é a do envio; o rascunho, que não foi enviado, vale
 * pela data em que foi criado.
 */
class Painel
{
    public const PERIODOS = ['7' => 'Últimos 7 dias', '30' => 'Últimos 30 dias', '90' => 'Últimos 90 dias', 'mes' => 'Este mês', 'tudo' => 'Tudo'];

    /** O intervalo do período escolhido, e o anterior de mesmo tamanho (para a comparação). @return array{0: ?Carbon, 1: Carbon, 2: ?Carbon, 3: ?Carbon} */
    public static function intervalo(string $periodo): array
    {
        $fim = now()->endOfDay();
        $inicio = match ($periodo) {
            '7' => now()->subDays(6)->startOfDay(),
            '90' => now()->subDays(89)->startOfDay(),
            'mes' => now()->startOfMonth(),
            'tudo' => null,
            default => now()->subDays(29)->startOfDay(),
        };
        if (! $inicio) {
            return [null, $fim, null, null];
        }
        $dias = (int) $inicio->diffInDays($fim) + 1;

        return [$inicio, $fim, $inicio->copy()->subDays($dias), $inicio->copy()->subSecond()];
    }

    public static function data(Orcamento $o): Carbon
    {
        return Carbon::parse($o->enviado_em ?? $o->created_at);
    }

    /** @return Collection<int, Orcamento> */
    private function vigentes(?Carbon $de, ?Carbon $ate): Collection
    {
        return Orcamento::with('preCliente:id,nome')->where('situacao', '!=', 'substituido')->get()
            ->filter(fn (Orcamento $o) => (! $de || self::data($o)->gte($de)) && (! $ate || self::data($o)->lte($ate)))->values();
    }

    public function montar(string $periodo): array
    {
        [$de, $ate, $antesDe, $antesAte] = self::intervalo($periodo);
        $os = $this->vigentes($de, $ate);
        $valor = fn (Collection $c) => (float) $c->sum('total_unico');
        $total = $valor($os);
        $anterior = $antesDe ? $valor($this->vigentes($antesDe, $antesAte)) : null;
        $grupo = fn (array $s) => $os->whereIn('situacao', $s);
        $kpi = fn (array $s) => ['qtd' => $grupo($s)->count(), 'valor' => $valor($grupo($s)), 'mensal' => (float) $grupo($s)->sum('total_mensal'),
            'pct' => $total > 0 ? round(100 * $valor($grupo($s)) / $total) : 0];

        // Evolução: um ponto por dia (até 31) ou por semana, empilhado por situação.
        $porSemana = ! $de || $de->diffInDays($ate) > 31;
        $chave = fn (Carbon $d) => $porSemana ? $d->copy()->startOfWeek()->format('Y-m-d') : $d->format('Y-m-d');
        $inicioSerie = $de ?? ($os->isEmpty() ? now()->subDays(29) : $os->map(fn ($o) => self::data($o))->min());
        $pontos = [];
        for ($d = $inicioSerie->copy()->startOfDay(); $d->lte($ate); $porSemana ? $d->addWeek() : $d->addDay()) {
            $pontos[$chave($d)] = ['rotulo' => $porSemana ? $d->copy()->startOfWeek()->format('d/m') : $d->format('d/m'), 'aberto' => 0, 'negociacao' => 0, 'aprovado' => 0, 'recusado' => 0];
        }
        foreach ($os as $o) {
            $k = $chave(self::data($o));
            $s = match ($o->situacao) { 'aprovado' => 'aprovado', 'recusado' => 'recusado', 'negociacao' => 'negociacao', default => 'aberto' };
            if (isset($pontos[$k])) {
                $pontos[$k][$s] += $o->total_unico;
            }
        }

        $porCliente = $os->groupBy(fn ($o) => $o->preCliente?->nome ?? $o->cliente)->map(fn ($c) => $valor($c))->filter()->sortDesc();

        return [
            'periodo' => $periodo, 'de' => $de, 'ate' => $ate,
            'total' => ['qtd' => $os->count(), 'valor' => $total, 'mensal' => (float) $os->sum('total_mensal'),
                'delta' => $anterior !== null && $anterior > 0 ? round(100 * ($total - $anterior) / $anterior) : null],
            'aberto' => $kpi(['rascunho', 'enviado']), 'negociacao' => $kpi(['negociacao']), 'aprovado' => $kpi(['aprovado']), 'recusado' => $kpi(['recusado']),
            'serie' => array_values($pontos), 'porSemana' => $porSemana,
            'situacoes' => collect(['aberto' => ['rascunho', 'enviado'], 'negociacao' => ['negociacao'], 'aprovado' => ['aprovado'], 'recusado' => ['recusado']])
                ->map(fn ($s) => $grupo($s)->count())->all(),
            'top' => $porCliente->take(4)->map(fn ($v, $nome) => ['nome' => $nome, 'valor' => $v])->values()->all()
                + ($porCliente->count() > 4 ? [4 => ['nome' => 'Outros', 'valor' => (float) $porCliente->slice(4)->sum()]] : []),
            'vencendo' => $os->where('situacao', 'enviado')->filter(fn ($o) => $o->validoAte()?->between(now()->startOfDay(), now()->addDays(3)->endOfDay()))->count(),
        ];
    }
}
