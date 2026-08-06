<?php

namespace App\Http\Controllers;

use App\Models\ContaFinanceira;
use Illuminate\Http\Request;

class ContaFinanceiraController extends Controller
{
    public function index()
    {
        $contasFinanceiras = ContaFinanceira::orderBy('nome')->get();

        $ativas = $contasFinanceiras->where('ativo', true);
        $saldoTotal = (float) $ativas->sum('saldo');
        $contasAtivas = $ativas->count();
        $contasNegativas = $ativas->where('saldo', '<', 0)->count();
        $maiorSaldo = (float) ($ativas->max('saldo') ?? 0);

        return view('contas-financeiras.index', compact(
            'contasFinanceiras', 'saldoTotal', 'contasAtivas', 'contasNegativas', 'maiorSaldo'
        ));
    }

    public function create()
    {
        return view('contas-financeiras.create');
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['ativo'] = $request->boolean('ativo');
        $saldoInicial = (float) ($data['saldo'] ?? 0);
        $data['saldo'] = 0;

        $conta = ContaFinanceira::create($data);

        if ($saldoInicial !== 0.0) {
            $conta->movimentacoes()->create([
                'tipo' => 'ajuste',
                'descricao' => 'Saldo inicial',
                'valor' => $saldoInicial,
                'saldo_resultante' => $saldoInicial,
                'data' => now()->toDateString(),
            ]);
            $conta->reprocessarSaldo();
        }

        return redirect()->route('contas-financeiras.index')->with('status', 'Conta financeira cadastrada.');
    }

    public function edit(ContaFinanceira $conta_financeira)
    {
        return view('contas-financeiras.edit', ['contaFinanceira' => $conta_financeira]);
    }

    public function update(Request $request, ContaFinanceira $conta_financeira)
    {
        $data = $this->validated($request);
        $data['ativo'] = $request->boolean('ativo');
        unset($data['saldo']);

        $conta_financeira->update($data);

        return redirect()->route('contas-financeiras.index')->with('status', 'Conta financeira atualizada.');
    }

    public function destroy(ContaFinanceira $conta_financeira)
    {
        $conta_financeira->delete();

        return redirect()->route('contas-financeiras.index')->with('status', 'Conta financeira removida.');
    }

    public function extrato(ContaFinanceira $conta_financeira)
    {
        $movimentacoes = $conta_financeira->movimentacoes()
            ->orderByDesc('data')
            ->orderByDesc('id')
            ->paginate(30);

        return view('contas-financeiras.extrato', ['contaFinanceira' => $conta_financeira, 'movimentacoes' => $movimentacoes]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'nome' => 'required|string|max:255',
            'tipo' => 'required|in:corrente,poupanca,cartao,caixa',
            'banco_codigo' => 'nullable|string|max:10',
            'agencia' => 'nullable|string|max:20',
            'numero_conta' => 'nullable|string|max:30',
            'saldo' => 'nullable|numeric',
            'limite_cartao' => 'nullable|numeric',
        ]);
    }
}
