<?php

namespace App\Http\Controllers;

use App\Models\Cobranca;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\FaturamentoService;
use Illuminate\Http\Request;

class FaturamentoController extends Controller
{
    public function index(Request $request)
    {
        $competencia = $request->input('competencia', now()->format('Y-m'));
        $mes = \Illuminate\Support\Carbon::createFromFormat('Y-m', $competencia)->startOfMonth();

        $escopo = auth()->user()->temEscopoDeRevenda()
            ? ['id' => auth()->user()->revenda_id]
            : [];

        $revendas = Revenda::when($escopo, fn ($q) => $q->where($escopo))
            ->where('ativo', true)
            ->orderBy('nome')
            ->get();

        $preview = $revendas
            ->map(fn (Revenda $revenda) => $this->painelDaRevenda($revenda))
            ->filter(fn ($painel) => $painel['linhas']->isNotEmpty())
            ->values();

        $cobrancasGeradas = Cobranca::where('tipo', 'locacao_sistema')
            ->where('competencia', $competencia)
            ->when(auth()->user()->temEscopoDeRevenda(), fn ($q) => $q->where('revenda_id', auth()->user()->revenda_id))
            ->with('revenda')
            ->get();

        $pendencias = $preview->flatMap(fn ($p) => $p['linhas']->where('sem_tier', true)
            ->map(fn ($l) => [
                'revenda' => $p['revenda']->nome,
                'sistema' => $l['sistema'],
                'unidades' => $l['unidades'],
                'unidade_cobranca' => $l['unidade_cobranca'],
            ]))->values();

        return view('faturamento.index', [
            'competencia' => $competencia,
            'preview' => $preview,
            'cobrancasGeradas' => $cobrancasGeradas,
            'pendencias' => $pendencias,
            'ciclo' => [
                // O total do ciclo é a soma dos subtotais, que por sua vez são
                // a soma das linhas. Nenhum número é digitado em lugar nenhum.
                'total' => (float) $preview->sum('total'),
                'revendas' => $preview->count(),
                'linhas' => (int) $preview->sum(fn ($p) => $p['linhas']->count()),
                'pendencias' => $pendencias->count(),
                'gerado' => $cobrancasGeradas->isNotEmpty(),
                // Mesma regra do motor que gera: fim da competência + 5 dias.
                'vencimento' => $mes->copy()->endOfMonth()->addDays(5),
                'rotulo' => $mes->translatedFormat('m/Y'),
            ],
        ]);
    }

    /**
     * O painel de uma revenda no ciclo: uma linha por sistema, cada uma com a
     * conta que a originou.
     *
     * @return array<string, mixed>
     */
    private function painelDaRevenda(Revenda $revenda): array
    {
        $sistemas = Sistema::where('ativo', true)
            ->whereHas('clientes', fn ($q) => $q->where('revenda_id', $revenda->id)
                ->where('clientes.ativo', true)
                ->where('cliente_sistema.ativo', true))
            ->orderBy('nome')
            ->get();

        $linhas = $sistemas->map(function (Sistema $sistema) use ($revenda) {
            $unidades = $sistema->clientes()
                ->where('revenda_id', $revenda->id)
                ->where('clientes.ativo', true)
                ->where('cliente_sistema.ativo', true)
                ->count();

            $tier = $sistema->tierParaVolume($unidades, $revenda->id);

            return [
                'sistema' => $sistema->nome,
                'unidades' => $unidades,
                'unidade_cobranca' => $sistema->unidade_cobranca,
                'tier' => $tier?->nome,
                'tipo_tier' => $this->tipoDoTier($tier),
                'calculo' => $this->calculo($tier, $unidades),
                'valor' => $tier?->calcularMensalidade($unidades),
                'sem_tier' => $tier === null,
            ];
        });

        return [
            'revenda' => $revenda,
            'linhas' => $linhas,
            'unidades' => (int) $linhas->sum('unidades'),
            // Linha sem tier fica FORA do subtotal — cobrar por ela seria
            // inventar um preço que ninguém configurou.
            'total' => (float) $linhas->where('sem_tier', false)->sum('valor'),
            'foraDoTotal' => $linhas->where('sem_tier', true)->count(),
        ];
    }

    private function tipoDoTier(?\App\Models\PrecoAtacado $tier): string
    {
        if (! $tier) {
            return 'sem_tier';
        }

        return $tier->valor_excedente_unidade !== null ? 'metrado' : 'fixo';
    }

    /**
     * A conta em uma frase — a coluna que torna o valor auditável antes de
     * virar cobrança.
     */
    private function calculo(?\App\Models\PrecoAtacado $tier, int $unidades): ?string
    {
        if (! $tier) {
            return null;
        }

        $inclusas = $tier->unidades_inclusas ?? 0;
        $emReais = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');

        if ($unidades <= $inclusas || $tier->valor_excedente_unidade === null) {
            return $tier->limite_unidades
                ? 'fixo · teto '.number_format($tier->limite_unidades, 0, ',', '.')
                : 'fixo do tier';
        }

        $excedente = $unidades - $inclusas;
        $conta = $excedente.' × '.$emReais($tier->valor_excedente_unidade);

        return $tier->preco_base > 0
            ? $emReais($tier->preco_base).' + '.$conta
            : $conta;
    }

    public function gerar(Request $request, FaturamentoService $service)
    {
        abort_if(auth()->user()->temEscopoDeRevenda(), 403, 'O fechamento do ciclo é feito pela matriz.');

        $competencia = $request->input('competencia', now()->format('Y-m'));

        $resultado = $service->gerarParaCompetencia($competencia);

        $cobrancasGeradas = collect($resultado)->filter(fn ($r) => isset($r['cobranca_id']))->count();

        return redirect()->route('faturamento.index', ['competencia' => $competencia])
            ->with('status', "Faturamento da competência {$competencia} gerado: {$cobrancasGeradas} cobrança(s) consolidada(s) criada(s).");
    }
}
