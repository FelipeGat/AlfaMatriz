<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\CobrancaAnexo;
use App\Models\ContaFinanceira;
use App\Models\Revenda;
use App\Models\Sistema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CobrancaController extends Controller
{
    public function index(Request $request)
    {
        $cobrancas = Cobranca::with(['revenda', 'cliente', 'sistema'])
            ->withCount('anexos')
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->competencia, fn ($q) => $q->where('competencia', $request->competencia))
            ->orderByDesc('data_vencimento')
            ->paginate(20)
            ->withQueryString();

        $hoje = now()->startOfDay();

        $pendentes = Cobranca::where('status', 'pendente')->get(['id', 'valor', 'data_vencimento']);
        $atrasadas = $pendentes->filter(fn ($c) => $c->data_vencimento->lt($hoje));

        $kpis = [
            'em_aberto' => (float) $pendentes->sum('valor'),
            'em_aberto_titulos' => $pendentes->count(),
            'vence_em_7_dias' => (float) $pendentes->filter(
                fn ($c) => $c->data_vencimento->between($hoje, $hoje->copy()->addDays(7))
            )->sum('valor'),
            'recebido_mes' => (float) Cobranca::where('status', 'pago')
                ->whereYear('data_pagamento', $hoje->year)
                ->whereMonth('data_pagamento', $hoje->month)
                ->sum('valor_pago'),
            'atrasado' => (float) $atrasadas->sum('valor'),
            'atrasado_titulos' => $atrasadas->count(),
        ];

        $faixas = $this->faixasDeAging($pendentes, $hoje);

        return view('cobrancas.index', array_merge(
            compact('cobrancas', 'kpis', 'faixas', 'hoje'),
            // O formulário de nova receita agora vive num modal desta tela, e
            // não numa página à parte: as listas que ele oferece precisam vir
            // junto com a lista.
            $this->listasDoFormulario()
        ));
    }

    /**
     * Distribui o total em aberto nas quatro faixas de vencimento do
     * desenho: a vencer, 1 a 15 dias de atraso, 16 a 30, e mais de 30.
     *
     * @param  \Illuminate\Support\Collection<int, Cobranca>  $pendentes
     * @return array<string, array{rotulo: string, valor: float}>
     */
    private function faixasDeAging($pendentes, \Carbon\Carbon $hoje): array
    {
        $faixas = [
            'a_vencer' => ['rotulo' => 'A vencer', 'valor' => 0.0],
            '1_15' => ['rotulo' => '1 a 15 dias', 'valor' => 0.0],
            '16_30' => ['rotulo' => '16 a 30 dias', 'valor' => 0.0],
            'mais_30' => ['rotulo' => '+30 dias', 'valor' => 0.0],
        ];

        foreach ($pendentes as $cobranca) {
            $diasParaVencer = $hoje->diffInDays($cobranca->data_vencimento, false);
            $diasDeAtraso = $diasParaVencer < 0 ? abs($diasParaVencer) : 0;

            $chave = match (true) {
                $diasDeAtraso === 0 => 'a_vencer',
                $diasDeAtraso <= 15 => '1_15',
                $diasDeAtraso <= 30 => '16_30',
                default => 'mais_30',
            };

            $faixas[$chave]['valor'] += (float) $cobranca->valor;
        }

        return $faixas;
    }

    public function show(Cobranca $cobranca)
    {
        $cobranca->load('revenda', 'cliente', 'sistema', 'contaFinanceira');

        return view('cobrancas.show', compact('cobranca'));
    }

    /** @return array<string, \Illuminate\Support\Collection> */
    private function listasDoFormulario(): array
    {
        return [
            'revendas' => Revenda::orderBy('nome')->get(),
            'clientes' => Cliente::orderBy('nome')->get(),
            'sistemas' => Sistema::orderBy('nome')->get(),
            'contasFinanceiras' => ContaFinanceira::where('ativo', true)->orderBy('nome')->get(),
        ];
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

    public function baixarEmMassa(Request $request)
    {
        $data = $request->validate(['ids' => 'required|array', 'ids.*' => 'exists:cobrancas,id']);

        $cobrancas = Cobranca::whereIn('id', $data['ids'])->where('status', 'pendente')->get();
        $semConta = $cobrancas->whereNull('conta_financeira_id');

        $cobrancas->whereNotNull('conta_financeira_id')->each->baixar();

        $status = $cobrancas->count() - $semConta->count().' receita(s) baixada(s).';
        if ($semConta->isNotEmpty()) {
            $status .= ' '.$semConta->count().' pulada(s) por não ter conta financeira definida.';
        }

        return redirect()->route('cobrancas.index')->with('status', $status);
    }

    public function listarAnexos(Cobranca $cobranca)
    {
        return response()->json($cobranca->anexos()->latest()->get());
    }

    public function storeAnexo(Request $request, Cobranca $cobranca)
    {
        $data = $request->validate([
            'tipo' => 'required|in:nf,boleto',
            'arquivos' => 'required|array|min:1|max:5',
            'arquivos.*' => 'required|file|mimes:pdf|max:10240',
        ]);

        foreach ($request->file('arquivos') as $arquivo) {
            $nomeOriginal = preg_replace('/[^a-zA-Z0-9._-]/', '_', $arquivo->getClientOriginalName());
            $nomeArquivo = uniqid().'_'.time().'.'.$arquivo->getClientOriginalExtension();
            $caminho = $arquivo->storeAs('anexos/cobrancas', $nomeArquivo, 'public');

            $cobranca->anexos()->create([
                'tipo' => $data['tipo'],
                'nome_original' => $nomeOriginal,
                'nome_arquivo' => $nomeArquivo,
                'caminho' => $caminho,
                'tamanho' => $arquivo->getSize(),
            ]);
        }

        return response()->json(['message' => 'Anexo(s) enviado(s) com sucesso.']);
    }

    public function downloadAnexo(CobrancaAnexo $anexo)
    {
        if (! Storage::disk('public')->exists($anexo->caminho)) {
            abort(404, 'Arquivo não encontrado no servidor.');
        }

        return Storage::disk('public')->download($anexo->caminho, $anexo->nome_original);
    }

    public function destroyAnexo(CobrancaAnexo $anexo)
    {
        Storage::disk('public')->delete($anexo->caminho);
        $anexo->delete();

        return response()->json(['message' => 'Anexo removido.']);
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
