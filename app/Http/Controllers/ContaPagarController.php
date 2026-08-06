<?php

namespace App\Http\Controllers;

use App\Models\CentroCusto;
use App\Models\Conta;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\ContaPagarAnexo;
use App\Models\Fornecedor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ContaPagarController extends Controller
{
    public function index(Request $request)
    {
        $contasPagar = ContaPagar::with(['centroCusto', 'conta.subcategoria.categoria', 'fornecedor', 'contaFixaPagar'])
            ->withCount('anexos')
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->tipo, fn ($q) => $q->where('tipo', $request->tipo))
            ->orderByDesc('data_vencimento')
            ->paginate(20)
            ->withQueryString();

        $hoje = now()->startOfDay();

        $emAberto = ContaPagar::where('status', 'em_aberto')->get(['id', 'valor', 'data_vencimento']);
        $atrasadas = $emAberto->filter(fn ($c) => \Illuminate\Support\Carbon::parse($c->data_vencimento)->lt($hoje));

        $kpis = [
            'a_pagar' => (float) $emAberto->sum('valor'),
            'a_pagar_titulos' => $emAberto->count(),
            'vence_em_7_dias' => (float) $emAberto->filter(
                fn ($c) => \Illuminate\Support\Carbon::parse($c->data_vencimento)->between($hoje, $hoje->copy()->addDays(7))
            )->sum('valor'),
            'pago_mes' => (float) ContaPagar::where('status', 'pago')
                ->whereYear('data_pagamento', $hoje->year)
                ->whereMonth('data_pagamento', $hoje->month)
                ->sum('valor_pago'),
            'atrasado' => (float) $atrasadas->sum('valor'),
            'atrasado_titulos' => $atrasadas->count(),
        ];

        $faixas = $this->faixasDeAging($emAberto, $hoje);

        return view('contas-pagar.index', array_merge(
            $this->formData(),
            compact('contasPagar', 'kpis', 'faixas', 'hoje')
        ));
    }

    /**
     * Distribui o total em aberto nas quatro faixas de vencimento — a mesma
     * gramática das Receitas, porque a pergunta é a mesma: onde o dinheiro
     * está travado.
     *
     * @param  \Illuminate\Support\Collection<int, ContaPagar>  $emAberto
     * @return array<string, array{rotulo: string, valor: float}>
     */
    private function faixasDeAging($emAberto, \Illuminate\Support\Carbon $hoje): array
    {
        $faixas = [
            'a_vencer' => ['rotulo' => 'A vencer', 'valor' => 0.0],
            '1_15' => ['rotulo' => '1 a 15 dias', 'valor' => 0.0],
            '16_30' => ['rotulo' => '16 a 30 dias', 'valor' => 0.0],
            'mais_30' => ['rotulo' => '+30 dias', 'valor' => 0.0],
        ];

        foreach ($emAberto as $conta) {
            $paraVencer = $hoje->diffInDays(\Illuminate\Support\Carbon::parse($conta->data_vencimento), false);
            $atraso = $paraVencer < 0 ? (int) abs($paraVencer) : 0;

            $chave = match (true) {
                $atraso === 0 => 'a_vencer',
                $atraso <= 15 => '1_15',
                $atraso <= 30 => '16_30',
                default => 'mais_30',
            };

            $faixas[$chave]['valor'] += (float) $conta->valor;
        }

        return $faixas;
    }

    public function create()
    {
        return view('contas-pagar.create', $this->formData());
    }

    public function store(Request $request)
    {
        ContaPagar::create([...$this->validated($request), 'tipo' => 'avulsa']);

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

    public function baixarEmMassa(Request $request)
    {
        $data = $request->validate(['ids' => 'required|array', 'ids.*' => 'exists:contas_pagar,id']);

        $contas = ContaPagar::whereIn('id', $data['ids'])->where('status', 'em_aberto')->get();
        $semConta = $contas->whereNull('conta_financeira_id');

        $contas->whereNotNull('conta_financeira_id')->each->baixar();

        $status = $contas->count() - $semConta->count().' despesa(s) baixada(s).';
        if ($semConta->isNotEmpty()) {
            $status .= ' '.$semConta->count().' pulada(s) por não ter conta financeira definida.';
        }

        return redirect()->route('contas-pagar.index')->with('status', $status);
    }

    public function listarAnexos(ContaPagar $conta_pagar)
    {
        return response()->json($conta_pagar->anexos()->latest()->get());
    }

    public function storeAnexo(Request $request, ContaPagar $conta_pagar)
    {
        $data = $request->validate([
            'tipo' => 'required|in:nf,boleto',
            'arquivos' => 'required|array|min:1|max:5',
            'arquivos.*' => 'required|file|mimes:pdf|max:10240',
        ]);

        foreach ($request->file('arquivos') as $arquivo) {
            $nomeOriginal = preg_replace('/[^a-zA-Z0-9._-]/', '_', $arquivo->getClientOriginalName());
            $nomeArquivo = uniqid().'_'.time().'.'.$arquivo->getClientOriginalExtension();
            $caminho = $arquivo->storeAs('anexos/contas-pagar', $nomeArquivo, 'public');

            $conta_pagar->anexos()->create([
                'tipo' => $data['tipo'],
                'nome_original' => $nomeOriginal,
                'nome_arquivo' => $nomeArquivo,
                'caminho' => $caminho,
                'tamanho' => $arquivo->getSize(),
            ]);
        }

        return response()->json(['message' => 'Anexo(s) enviado(s) com sucesso.']);
    }

    public function downloadAnexo(ContaPagarAnexo $anexo)
    {
        if (! Storage::disk('public')->exists($anexo->caminho)) {
            abort(404, 'Arquivo não encontrado no servidor.');
        }

        return Storage::disk('public')->download($anexo->caminho, $anexo->nome_original);
    }

    public function destroyAnexo(ContaPagarAnexo $anexo)
    {
        Storage::disk('public')->delete($anexo->caminho);
        $anexo->delete();

        return response()->json(['message' => 'Anexo removido.']);
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
            'forma_pagamento' => 'nullable|string|max:255',
        ]);
    }
}
