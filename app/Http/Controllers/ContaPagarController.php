<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\CentroCusto;
use App\Models\Conta;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\ContaPagarAnexo;
use App\Models\Fornecedor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class ContaPagarController extends Controller
{
    public function __construct()
    {
        $this->bloquearVisaoDaMatriz();
    }

    /**
     * Mesmos filtros das Receitas (16/08/2026, pedido de replicar) — período
     * por vencimento, busca ampla, pills de status/tipo com contador. A
     * diferença de modelo é só o que substitui "revenda": aqui é centro de
     * custo, porque despesa não nasce de revenda nenhuma.
     */
    public function index(Request $request)
    {
        $hoje = now()->startOfDay();

        [$periodoDe, $periodoAte] = $this->periodoSelecionado($request);
        $busca = trim((string) $request->query('busca', ''));
        $centroCustoId = $request->query('centro_custo_id');

        $base = ContaPagar::query()
            ->when($centroCustoId, fn ($q) => $q->where('centro_custo_id', $centroCustoId))
            ->when($periodoDe, fn ($q) => $q->whereDate('data_vencimento', '>=', $periodoDe->toDateString()))
            ->when($periodoAte, fn ($q) => $q->whereDate('data_vencimento', '<=', $periodoAte->toDateString()))
            ->when($busca !== '', fn ($q) => $this->aplicarBusca($q, $busca));

        $contagens = [
            'todos' => (clone $base)->count(),
            'em_aberto' => (clone $base)->where('status', 'em_aberto')->count(),
            'vencido' => (clone $base)->where('status', 'em_aberto')->whereDate('data_vencimento', '<', $hoje)->count(),
            'pago' => (clone $base)->where('status', 'pago')->count(),
            'cancelado' => (clone $base)->where('status', 'cancelado')->count(),
        ];
        $contagensTipo = [
            'avulsa' => (clone $base)->where('tipo', 'avulsa')->count(),
            'fixa' => (clone $base)->where('tipo', 'fixa')->count(),
        ];

        $filtroStatus = $this->filtroStatusSelecionado($request);
        $filtroTipo = $this->filtroTipoSelecionado($request);

        $contasPagar = (clone $base)
            ->with(['centroCusto', 'conta.subcategoria.categoria', 'fornecedor', 'contaFixaPagar'])
            ->withCount('anexos')
            ->when($filtroStatus === 'em_aberto', fn ($q) => $q->where('status', 'em_aberto'))
            ->when($filtroStatus === 'vencido', fn ($q) => $q->where('status', 'em_aberto')->whereDate('data_vencimento', '<', $hoje))
            ->when($filtroStatus === 'pago', fn ($q) => $q->where('status', 'pago'))
            ->when($filtroStatus === 'cancelado', fn ($q) => $q->where('status', 'cancelado'))
            ->when($filtroTipo, fn ($q) => $q->where('tipo', $filtroTipo))
            ->orderByDesc('data_vencimento')
            ->paginate(20)
            ->withQueryString();

        // Os quatro cards — mesma régua das Receitas: sempre sobre o recorte
        // de período/busca/centro de custo ativo, nunca um total fixo.
        $kpis = [
            'a_pagar' => (float) (clone $base)->where('status', '!=', 'pago')->whereDate('data_vencimento', '>=', $hoje)->sum('valor'),
            'pago' => (float) (clone $base)->where('status', 'pago')->sum('valor_pago'),
            'vencido' => (float) (clone $base)->where('status', '!=', 'pago')->whereDate('data_vencimento', '<', $hoje)->sum('valor'),
            'vence_hoje' => (float) (clone $base)->where('status', '!=', 'pago')->whereDate('data_vencimento', $hoje)->sum('valor'),
        ];

        // Aging continua GLOBAL, sem período/busca — exposição total, não a
        // fatia do mês navegado (mesma escolha das Receitas).
        $emAbertoGlobal = ContaPagar::where('status', 'em_aberto')->get(['id', 'valor', 'data_vencimento']);
        $faixas = $this->faixasDeAging($emAbertoGlobal, $hoje);
        $totalEmAbertoGlobal = (float) $emAbertoGlobal->sum('valor');

        return view('contas-pagar.index', array_merge(
            $this->formData(),
            compact('contasPagar', 'kpis', 'faixas', 'hoje', 'totalEmAbertoGlobal', 'contagens', 'contagensTipo'),
            [
                'filtroPeriodo' => $this->filtroPeriodoSelecionado($request),
                'periodoDe' => $periodoDe?->format('Y-m-d'),
                'periodoAte' => $periodoAte?->format('Y-m-d'),
                'busca' => $busca,
                'centroCustoId' => $centroCustoId,
                'filtroStatus' => $filtroStatus,
                'filtroTipo' => $filtroTipo,
            ]
        ));
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function periodoSelecionado(Request $request): array
    {
        return match ($this->filtroPeriodoSelecionado($request)) {
            'mes_anterior' => [now()->startOfMonth()->subMonth(), now()->startOfMonth()->subMonth()->endOfMonth()],
            'proximo_mes' => [now()->startOfMonth()->addMonth(), now()->startOfMonth()->addMonth()->endOfMonth()],
            'ontem' => [now()->subDay()->startOfDay(), now()->subDay()->startOfDay()],
            'hoje' => [now()->startOfDay(), now()->startOfDay()],
            'amanha' => [now()->addDay()->startOfDay(), now()->addDay()->startOfDay()],
            'personalizado' => $this->periodoPersonalizado($request),
            'todos' => [null, null],
            default => [now()->startOfMonth(), now()->endOfMonth()],
        };
    }

    private function filtroPeriodoSelecionado(Request $request): string
    {
        $valor = $request->query('periodo');

        return in_array($valor, ['mes_anterior', 'proximo_mes', 'ontem', 'hoje', 'amanha', 'personalizado', 'todos'], true)
            ? $valor
            : 'mes_atual';
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function periodoPersonalizado(Request $request): array
    {
        $de = $request->query('periodo_de');
        $ate = $request->query('periodo_ate');

        $inicio = $this->dataValida($de) ?? now()->startOfMonth();
        $fim = $this->dataValida($ate) ?? now()->endOfMonth();

        if ($fim->lessThan($inicio)) {
            [$inicio, $fim] = [$fim, $inicio];
        }

        return [$inicio, $fim];
    }

    private function dataValida(mixed $valor): ?Carbon
    {
        if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $valor);
        } catch (\Exception) {
            return null;
        }
    }

    private function filtroStatusSelecionado(Request $request): string
    {
        $valor = $request->query('status_filtro');

        return in_array($valor, ['em_aberto', 'vencido', 'pago', 'cancelado'], true) ? $valor : 'todos';
    }

    private function filtroTipoSelecionado(Request $request): ?string
    {
        $valor = $request->query('tipo_filtro');

        return in_array($valor, ['avulsa', 'fixa'], true) ? $valor : null;
    }

    /**
     * Mesma ideia da busca de Receitas: fornecedor (nome, fantasia, CNPJ/CPF
     * sem máscara), descrição e valor (aceita vírgula ou ponto decimal).
     */
    private function aplicarBusca($query, string $busca)
    {
        $digitos = preg_replace('/\D/', '', $busca);
        $valorBusca = str_replace(',', '.', $busca);

        return $query->where(function ($qq) use ($busca, $digitos, $valorBusca) {
            $qq->where('descricao', 'like', "%{$busca}%")
                ->orWhere('valor', 'like', "%{$valorBusca}%")
                ->orWhereHas('fornecedor', function ($f) use ($busca, $digitos) {
                    $f->where('razao_social', 'like', "%{$busca}%")
                        ->orWhere('nome_fantasia', 'like', "%{$busca}%");
                    if ($digitos !== '') {
                        $f->orWhere('cpf_cnpj', 'like', "%{$digitos}%");
                    }
                });
        });
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

        // Mesma razão do anexo de receita: nota fiscal e comprovante de despesa
        // saem do sistema quando alguém os baixa, e o dado que eles carregam
        // não volta. A linha aqui é a única testemunha disso.
        Auditoria::registrar(
            recurso: 'contas_pagar',
            acao: 'baixou',
            alvo: $anexo->contaPagar,
            descricao: $anexo->nome_original,
        );

        return Storage::disk('public')->download($anexo->caminho, $anexo->nome_original);
    }

    public function destroyAnexo(ContaPagarAnexo $anexo)
    {
        // O arquivo sai junto com a linha — ver o `deleting` do `ContaPagarAnexo`.
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
