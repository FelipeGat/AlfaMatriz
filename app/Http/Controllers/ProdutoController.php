<?php

namespace App\Http\Controllers;

use App\Models\Sistema;
use Illuminate\Http\Request;

class ProdutoController extends Controller
{
    public function index()
    {
        $produtos = Sistema::orderBy('categoria')->orderBy('nome')->get()->map(function (Sistema $sistema) {
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
            ];
        });

        $mrrTotal = $produtos->sum('mrr');

        return view('produtos.index', compact('produtos', 'mrrTotal'));
    }

    public function update(Request $request, Sistema $sistema)
    {
        $data = $request->validate([
            'versao' => 'nullable|string|max:255',
            'responsavel' => 'nullable|string|max:255',
            'roadmap' => 'nullable|string',
        ]);

        $sistema->update($data);

        return redirect()->route('produtos.index')->with('status', "Informações de gestão de {$sistema->nome} atualizadas.");
    }
}
