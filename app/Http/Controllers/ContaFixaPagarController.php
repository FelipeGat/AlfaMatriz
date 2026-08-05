<?php

namespace App\Http\Controllers;

use App\Models\CentroCusto;
use App\Models\Conta;
use App\Models\ContaFinanceira;
use App\Models\ContaFixaPagar;
use App\Models\Fornecedor;
use App\Services\DespesaFixaService;
use Illuminate\Http\Request;

class ContaFixaPagarController extends Controller
{
    public function index()
    {
        $contasFixas = ContaFixaPagar::with(['centroCusto', 'conta.subcategoria.categoria', 'fornecedor'])
            ->orderBy('descricao')
            ->get();

        $totalMensal = $contasFixas->where('ativo', true)->sum('valor');

        return view('contas-fixas-pagar.index', array_merge($this->formData(), compact('contasFixas', 'totalMensal')));
    }

    public function store(Request $request)
    {
        ContaFixaPagar::create($this->validated($request));

        return redirect()->route('contas-fixas-pagar.index')->with('status', 'Despesa fixa cadastrada.');
    }

    public function update(Request $request, ContaFixaPagar $conta_fixa_pagar)
    {
        $data = $this->validated($request);
        $data['ativo'] = $request->boolean('ativo');

        $conta_fixa_pagar->update($data);

        return redirect()->route('contas-fixas-pagar.index')->with('status', 'Despesa fixa atualizada.');
    }

    public function destroy(ContaFixaPagar $conta_fixa_pagar)
    {
        $conta_fixa_pagar->delete();

        return redirect()->route('contas-fixas-pagar.index')->with('status', 'Despesa fixa removida.');
    }

    public function gerar(Request $request, DespesaFixaService $service)
    {
        $competencia = $request->input('competencia', now()->format('Y-m'));

        $resultado = $service->gerarParaCompetencia($competencia);

        $gerados = collect($resultado)->where('status', 'gerado')->count();
        $jaExistiam = collect($resultado)->where('status', 'ja_gerado')->count();

        return redirect()->route('contas-fixas-pagar.index')
            ->with('status', "Competência {$competencia}: {$gerados} despesa(s) gerada(s), {$jaExistiam} já existiam.");
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
            'dia_vencimento' => 'required|integer|min:1|max:31',
            'data_inicio' => 'required|date',
            'data_fim' => 'nullable|date|after_or_equal:data_inicio',
            'forma_pagamento' => 'nullable|string|max:255',
        ]);
    }
}
