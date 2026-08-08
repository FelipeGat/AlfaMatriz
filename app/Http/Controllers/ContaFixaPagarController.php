<?php

namespace App\Http\Controllers;

use App\Models\CentroCusto;
use App\Models\Conta;
use App\Models\ContaFinanceira;
use App\Models\ContaFixaPagar;
use App\Models\ContaPagar;
use App\Models\Fornecedor;
use App\Services\DespesaFixaService;
use Illuminate\Http\Request;

class ContaFixaPagarController extends Controller
{
    public function __construct()
    {
        $this->bloquearVisaoDaMatriz();
    }

    public function index()
    {
        $contasFixas = ContaFixaPagar::with(['centroCusto', 'conta.subcategoria.categoria', 'fornecedor'])
            ->orderBy('descricao')
            ->get();

        $ativas = $contasFixas->where('ativo', true);
        $totalMensal = (float) $ativas->sum('valor');
        $quantidadeAtivas = $ativas->count();

        // Quantas já viraram conta a pagar nesta competência: é o que diz se o
        // mês foi fechado ou não, sem precisar abrir a tela de Despesas.
        $competencia = now()->format('Y-m');
        $geradasNoMes = ContaPagar::where('tipo', 'fixa')->where('competencia', $competencia)->count();
        $pendentesDeGeracao = max($quantidadeAtivas - $geradasNoMes, 0);

        return view('contas-fixas-pagar.index', array_merge($this->formData(), compact(
            'contasFixas', 'totalMensal', 'quantidadeAtivas', 'geradasNoMes', 'pendentesDeGeracao', 'competencia'
        )));
    }

    public function store(Request $request, DespesaFixaService $service)
    {
        $fixa = ContaFixaPagar::create([...$this->validated($request), 'ativo' => true]);

        // Já gera a parcela da competência atual pra despesa aparecer na lista
        // de despesas imediatamente, sem esperar o fechamento do mês.
        if ($fixa->vigenteEm(now())) {
            $service->gerarParaCompetencia(now()->format('Y-m'));
        }

        return redirect()->route('contas-pagar.index')->with('status', 'Despesa recorrente cadastrada.');
    }

    public function update(Request $request, ContaFixaPagar $conta_fixa_pagar)
    {
        $data = $this->validated($request);
        $data['ativo'] = $request->boolean('ativo');

        $conta_fixa_pagar->update($data);

        return redirect()->route('contas-pagar.index')->with('status', 'Despesa fixa atualizada.');
    }

    public function destroy(ContaFixaPagar $conta_fixa_pagar)
    {
        $conta_fixa_pagar->delete();

        return redirect()->route('contas-pagar.index')->with('status', 'Despesa fixa removida.');
    }

    public function pausar(ContaFixaPagar $conta_fixa_pagar)
    {
        $conta_fixa_pagar->update(['ativo' => ! $conta_fixa_pagar->ativo]);

        return back()->with('status', $conta_fixa_pagar->ativo ? 'Despesa fixa reativada.' : 'Despesa fixa pausada — não gera mais parcelas até ser reativada.');
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
