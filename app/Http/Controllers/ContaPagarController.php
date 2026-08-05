<?php

namespace App\Http\Controllers;

use App\Models\CentroCusto;
use App\Models\Conta;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\Fornecedor;
use Illuminate\Http\Request;

class ContaPagarController extends Controller
{
    public function index(Request $request)
    {
        $contasPagar = ContaPagar::with(['centroCusto', 'conta.subcategoria.categoria', 'fornecedor'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('data_vencimento')
            ->paginate(20)
            ->withQueryString();

        return view('contas-pagar.index', compact('contasPagar'));
    }

    public function create()
    {
        return view('contas-pagar.create', $this->formData());
    }

    public function store(Request $request)
    {
        ContaPagar::create($this->validated($request));

        return redirect()->route('contas-pagar.index')->with('status', 'Despesa cadastrada com sucesso.');
    }

    public function edit(ContaPagar $conta_pagar)
    {
        return view('contas-pagar.edit', array_merge($this->formData(), ['contaPagar' => $conta_pagar]));
    }

    public function update(Request $request, ContaPagar $conta_pagar)
    {
        $conta_pagar->update($this->validated($request));

        return redirect()->route('contas-pagar.index')->with('status', 'Despesa atualizada com sucesso.');
    }

    public function destroy(ContaPagar $conta_pagar)
    {
        $conta_pagar->delete();

        return redirect()->route('contas-pagar.index')->with('status', 'Despesa removida.');
    }

    public function baixar(Request $request, ContaPagar $conta_pagar)
    {
        $data = $request->validate([
            'valor_pago' => 'nullable|numeric|min:0',
            'data_pagamento' => 'nullable|date',
        ]);

        if (! $conta_pagar->conta_financeira_id) {
            return back()->withErrors(['conta_financeira_id' => 'Defina a conta financeira de pagamento antes de baixar.']);
        }

        $conta_pagar->baixar($data['valor_pago'] ?? null, $data['data_pagamento'] ?? null);

        return redirect()->route('contas-pagar.index')->with('status', 'Despesa baixada com sucesso.');
    }

    private function formData(): array
    {
        return [
            'centrosCusto' => CentroCusto::where('ativo', true)->orderBy('nome')->get(),
            'contas' => Conta::with('subcategoria.categoria')->where('ativo', true)->get(),
            'fornecedores' => Fornecedor::orderBy('razao_social')->get(),
            'contasFinanceiras' => ContaFinanceira::where('ativo', true)->orderBy('nome')->get(),
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'centro_custo_id' => 'nullable|exists:centros_custo,id',
            'conta_id' => 'nullable|exists:contas,id',
            'fornecedor_id' => 'nullable|exists:fornecedores,id',
            'conta_financeira_id' => 'nullable|exists:contas_financeiras,id',
            'descricao' => 'required|string|max:255',
            'valor' => 'required|numeric|min:0',
            'data_vencimento' => 'required|date',
            'tipo' => 'required|in:avulsa,fixa',
            'forma_pagamento' => 'nullable|string|max:255',
        ]);
    }
}
