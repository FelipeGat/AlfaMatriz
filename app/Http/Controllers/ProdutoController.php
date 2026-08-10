<?php

namespace App\Http\Controllers;

use App\Models\Sistema;
use Illuminate\Http\Request;

class ProdutoController extends Controller
{
    public function index()
    {
        $this->bloquearVisaoDaMatriz();

        $produtos = Sistema::with(['modulos.contratacoes' => fn ($q) => $q->where('status', 'ativo')])
            ->orderBy('nome')->get()->map(function (Sistema $sistema) {
            $ativos = $sistema->clientesAtivosCount();
            $cancelados = $sistema->clientesCanceladosCount();
            $mrr = $sistema->mrrEstimado();

            return [
                'sistema' => $sistema,
                'clientes_ativos' => $ativos,
                'clientes_cancelados' => $cancelados,
                'mrr' => $mrr,
                'arr' => $mrr * 12,
                'ticket_medio' => $ativos > 0 ? $mrr / $ativos : 0,
                'taxa_cancelamento' => ($ativos + $cancelados) > 0 ? ($cancelados / ($ativos + $cancelados)) * 100 : 0,
                // Sem tier de atacado o sistema não entra no faturamento das
                // revendas — é a pendência que a tela precisa gritar.
                'sem_tier' => $sistema->tiersVigentes()->isEmpty(),
                // Módulos são receita à parte da licença: sem eles na tela, o
                // MRR do produto parece menor do que é.
                'modulos_ativos' => $sistema->modulos->filter(fn ($m) => $m->contratacoes->isNotEmpty())->count(),
                'mrr_modulos' => (float) $sistema->modulos->sum(fn ($m) => $m->contratacoes->sum('valor_mensal')),
            ];
        });

        $mrrTotal = (float) $produtos->sum('mrr');

        // Ordenar por receita é o que transforma sete cartões soltos numa
        // lista comparável: a primeira linha é o produto que sustenta a casa.
        $produtos = $produtos
            ->map(fn ($p) => $p + ['share' => $mrrTotal > 0 ? $p['mrr'] / $mrrTotal : 0])
            ->sortByDesc('mrr')
            ->values();

        $maiorMrr = (float) ($produtos->max('mrr') ?: 0);

        return view('produtos.index', [
            'produtos' => $produtos->map(fn ($p) => $p + [
                'largura' => $maiorMrr > 0 ? $p['mrr'] / $maiorMrr : 0,
            ]),
            'mrrTotal' => $mrrTotal,
            'totais' => [
                'mrr' => $mrrTotal,
                'arr' => $mrrTotal * 12,
                'ativos' => (int) $produtos->sum('clientes_ativos'),
                'cancelados' => (int) $produtos->sum('clientes_cancelados'),
            ],
            'contagens' => [
                'sistemas' => $produtos->count(),
                'ativos' => $produtos->filter(fn ($p) => $p['sistema']->ativo)->count(),
                'sem_tier' => $produtos->where('sem_tier', true)->count(),
            ],
        ]);
    }

    public function update(Request $request, Sistema $sistema)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'versao' => 'nullable|string|max:255',
            'responsavel' => 'nullable|string|max:255',
            'roadmap' => 'nullable|string',
        ]);

        $sistema->update($data);

        return redirect()->route('produtos.index')->with('status', "Informações de gestão de {$sistema->nome} atualizadas.");
    }
}
