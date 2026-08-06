<?php

namespace App\Http\Controllers;

use App\Models\ContaFinanceira;
use Illuminate\Http\Request;

class ContaFinanceiraController extends Controller
{
    public function index()
    {
        $contasFinanceiras = ContaFinanceira::withCount('movimentacoes')->orderBy('nome')->get();
        $saldoTotal = (float) $contasFinanceiras->where('ativo', true)->sum('saldo');

        $inicioMes = now()->startOfMonth();
        $fimMes = now()->endOfMonth();

        $doMes = \App\Models\MovimentacaoFinanceira::whereBetween('data', [$inicioMes->toDateString(), $fimMes->toDateString()])
            ->get(['tipo', 'valor']);

        $entradas = (float) $doMes->where('tipo', 'entrada')->sum('valor');
        $saidas = (float) $doMes->where('tipo', 'saida')->sum('valor');

        return view('contas-financeiras.index', [
            'contasFinanceiras' => $contasFinanceiras,
            'saldoTotal' => $saldoTotal,
            'cartoes' => $contasFinanceiras->map(fn (ContaFinanceira $conta) => [
                'conta' => $conta,
                'share' => $saldoTotal != 0 ? (float) $conta->saldo / $saldoTotal : 0.0,
                'variacao' => $this->variacaoDoMes($conta),
                'serie' => $this->serieDeSaldo($conta),
            ]),
            'mes' => [
                'entradas' => $entradas,
                'saidas' => $saidas,
                'resultado' => $entradas - $saidas,
                'rotulo' => now()->translatedFormat('F'),
            ],
            'ultimas' => \App\Models\MovimentacaoFinanceira::with('contaFinanceira')
                ->orderByDesc('data')->orderByDesc('id')->limit(8)->get(),
            'folga' => $this->folgaDeCaixa($saldoTotal),
        ]);
    }

    /** Quanto o saldo da conta se moveu no mês corrente, em percentual. */
    private function variacaoDoMes(ContaFinanceira $conta): ?string
    {
        $movimento = (float) $conta->movimentacoes()
            ->whereBetween('data', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE -valor END), 0) as total")
            ->value('total');

        $saldoNoInicio = (float) $conta->saldo - $movimento;

        if ($saldoNoInicio == 0.0) {
            return $movimento == 0.0 ? 'estável' : null;
        }

        $percentual = ($movimento / abs($saldoNoInicio)) * 100;

        if (abs($percentual) < 0.05) {
            return 'estável';
        }

        return ($percentual >= 0 ? '+' : '−').number_format(abs($percentual), 1, ',', '.').'%';
    }

    /**
     * Saldo da conta no fim de cada um dos últimos seis meses, recomposto de
     * trás para frente — não existe foto histórica de saldo gravada.
     *
     * @return list<float>
     */
    private function serieDeSaldo(ContaFinanceira $conta): array
    {
        return collect(range(5, 0))->map(function (int $atras) use ($conta) {
            $fim = now()->copy()->subMonths($atras)->endOfMonth();

            $depois = (float) $conta->movimentacoes()
                ->where('data', '>', $fim->toDateString())
                ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE -valor END), 0) as total")
                ->value('total');

            return (float) $conta->saldo - $depois;
        })->all();
    }

    /** Quantos dias de despesa o caixa cobre — o número que decide se dá para respirar. */
    private function folgaDeCaixa(float $saldo): ?string
    {
        $mediaMensal = (float) \App\Models\ContaPagar::where('data_vencimento', '>=', now()->subMonths(3))
            ->sum('valor') / 3;

        if ($mediaMensal <= 0 || $saldo <= 0) {
            return null;
        }

        return 'cobre '.(int) floor($saldo / ($mediaMensal / 30)).' dias de despesa';
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
