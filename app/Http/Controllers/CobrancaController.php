<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\ContaFinanceira;
use App\Models\Revenda;
use App\Models\Sistema;
use Illuminate\Http\Request;

class CobrancaController extends Controller
{
    public function index(Request $request)
    {
        $cobrancas = Cobranca::with(['revenda', 'cliente', 'sistema'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('data_vencimento')
            ->paginate(20)
            ->withQueryString();

        $hoje = now()->startOfDay();
        $emAberto = Cobranca::where('status', 'pendente')->sum('valor');
        $vencidas = Cobranca::where('status', 'pendente')->whereDate('data_vencimento', '<', $hoje)->sum('valor');
        $baixadas = Cobranca::where('status', 'pago')
            ->whereBetween('data_pagamento', [now()->startOfMonth(), now()->endOfMonth()])->sum('valor_pago');
        $totalMes = Cobranca::where('competencia', now()->format('Y-m'))
            ->where('status', '!=', 'cancelado')->sum('valor');

        return view('cobrancas.index', compact('cobrancas', 'emAberto', 'vencidas', 'baixadas', 'totalMes'));
    }

    public function show(Cobranca $cobranca)
    {
        $cobranca->load('revenda', 'cliente', 'sistema', 'contaFinanceira');

        return view('cobrancas.show', compact('cobranca'));
    }

    public function create()
    {
        $revendas = Revenda::orderBy('nome')->get();
        $clientes = Cliente::orderBy('nome')->get();
        $sistemas = Sistema::orderBy('nome')->get();
        $contasFinanceiras = ContaFinanceira::where('ativo', true)->orderBy('nome')->get();

        return view('cobrancas.create', compact('revendas', 'clientes', 'sistemas', 'contasFinanceiras'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        Cobranca::create($data);

        return redirect()->route('cobrancas.index')->with('status', 'Receita cadastrada com sucesso.');
    }

    public function edit(Cobranca $cobranca)
    {
        $revendas = Revenda::orderBy('nome')->get();
        $clientes = Cliente::orderBy('nome')->get();
        $sistemas = Sistema::orderBy('nome')->get();
        $contasFinanceiras = ContaFinanceira::where('ativo', true)->orderBy('nome')->get();

        return view('cobrancas.edit', compact('cobranca', 'revendas', 'clientes', 'sistemas', 'contasFinanceiras'));
    }

    public function update(Request $request, Cobranca $cobranca)
    {
        $cobranca->update($this->validated($request));

        return redirect()->route('cobrancas.index')->with('status', 'Receita atualizada com sucesso.');
    }

    public function destroy(Cobranca $cobranca)
    {
        $cobranca->delete();

        return redirect()->route('cobrancas.index')->with('status', 'Receita removida.');
    }

    public function baixar(Request $request, Cobranca $cobranca)
    {
        $data = $request->validate([
            'valor_pago' => 'nullable|numeric|min:0',
            'data_pagamento' => 'nullable|date',
        ]);

        if (! $cobranca->conta_financeira_id) {
            return back()->withErrors(['conta_financeira_id' => 'Defina a conta financeira de recebimento antes de baixar.']);
        }

        $cobranca->baixar($data['valor_pago'] ?? null, $data['data_pagamento'] ?? null);

        return redirect()->route('cobrancas.index')->with('status', 'Receita baixada com sucesso.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'revenda_id' => 'nullable|exists:revendas,id',
            'cliente_id' => 'nullable|exists:clientes,id',
            'sistema_id' => 'nullable|exists:sistemas,id',
            'conta_financeira_id' => 'nullable|exists:contas_financeiras,id',
            'descricao' => 'required|string|max:255',
            'valor' => 'required|numeric|min:0',
            'data_vencimento' => 'required|date',
            'tipo' => 'required|in:locacao_sistema,avulsa,direta',
            'competencia' => 'nullable|string|max:7',
            'forma_pagamento' => 'nullable|string|max:255',
        ]);
    }
}
