<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\CobrancaAnexo;
use App\Models\ContaFinanceira;
use App\Models\Revenda;
use App\Models\Sistema;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class CobrancaController extends Controller
{
    /**
     * Reformulada em 16/08/2026 para acompanhar a tela de Contas a Receber
     * do Gestor.Alfa: navegação de período por VENCIMENTO, busca, filtro por
     * revenda, pills de status/tipo com contador, e os quatro cards "A
     * receber / Recebido / Vencido / Vence hoje" — os quatro TODOS respeitam
     * os filtros ativos, igual lá (não são números fixos do dia).
     *
     * O bloco "Em aberto por faixa de vencimento" é diferente: não existe no
     * Gestor (conferido — é peça própria daqui), e continua GLOBAL por
     * propósito — ele responde "quanto está em aberto no total, agora",
     * não "quanto está em aberto NESTE recorte de período que estou olhando".
     * Misturar os dois faria o card mentir sobre exposição real.
     */
    public function index(Request $request)
    {
        $usuario = auth()->user();
        $hoje = now()->startOfDay();

        [$periodoDe, $periodoAte] = $this->periodoSelecionado($request);
        $busca = trim((string) $request->query('busca', ''));
        $revendaId = $request->query('revenda_id');

        $base = Cobranca::query()
            ->when($usuario->temEscopoDeRevenda(), fn ($q) => $q->where('revenda_id', $usuario->revenda_id))
            ->when(! $usuario->temEscopoDeRevenda() && $revendaId, fn ($q) => $q->where('revenda_id', $revendaId))
            ->when($periodoDe, fn ($q) => $q->whereDate('data_vencimento', '>=', $periodoDe->toDateString()))
            ->when($periodoAte, fn ($q) => $q->whereDate('data_vencimento', '<=', $periodoAte->toDateString()))
            ->when($busca !== '', fn ($q) => $this->aplicarBusca($q, $busca));

        // Contadores das pills ANTES do filtro de status/tipo — pergunta "quantos
        // há em cada status DENTRO deste período/busca/revenda", igual ao Gestor:
        // trocar de pill não deveria também trocar o que as outras pills contam.
        $contagens = [
            'todos' => (clone $base)->count(),
            'pendente' => (clone $base)->where('status', 'pendente')->count(),
            'vencido' => (clone $base)->where('status', 'pendente')->whereDate('data_vencimento', '<', $hoje)->count(),
            'pago' => (clone $base)->where('status', 'pago')->count(),
            'cancelado' => (clone $base)->where('status', 'cancelado')->count(),
        ];
        $contagensTipo = [
            'locacao_sistema' => (clone $base)->where('tipo', 'locacao_sistema')->count(),
            'locacao_cliente' => (clone $base)->where('tipo', 'locacao_cliente')->count(),
            'avulsa' => (clone $base)->where('tipo', 'avulsa')->count(),
            'direta' => (clone $base)->where('tipo', 'direta')->count(),
        ];

        $filtroStatus = $this->filtroStatusSelecionado($request);
        $filtroTipo = $this->filtroTipoSelecionado($request);

        $cobrancas = (clone $base)
            ->with(['revenda', 'cliente', 'sistema'])
            ->withCount('anexos')
            ->when($filtroStatus === 'pendente', fn ($q) => $q->where('status', 'pendente'))
            ->when($filtroStatus === 'vencido', fn ($q) => $q->where('status', 'pendente')->whereDate('data_vencimento', '<', $hoje))
            ->when($filtroStatus === 'pago', fn ($q) => $q->where('status', 'pago'))
            ->when($filtroStatus === 'cancelado', fn ($q) => $q->where('status', 'cancelado'))
            ->when($filtroTipo, fn ($q) => $q->where('tipo', $filtroTipo))
            ->orderByDesc('data_vencimento')
            ->paginate(20)
            ->withQueryString();

        // Os quatro cards do Gestor — "a_receber/recebido/vencido/vence_hoje" —
        // sempre sobre o MESMO recorte de período/busca/revenda que a tabela,
        // nunca sobre um total fixo. `pago` usa `valor_pago` (o que
        // efetivamente entrou), os outros três usam `valor` (o que está
        // programado) — mesma régua de `entradasDoMes()` no Painel Financeiro.
        $kpis = [
            'a_receber' => (float) (clone $base)->where('status', '!=', 'pago')->whereDate('data_vencimento', '>=', $hoje)->sum('valor'),
            'recebido' => (float) (clone $base)->where('status', 'pago')->sum('valor_pago'),
            'vencido' => (float) (clone $base)->where('status', '!=', 'pago')->whereDate('data_vencimento', '<', $hoje)->sum('valor'),
            'vence_hoje' => (float) (clone $base)->where('status', '!=', 'pago')->whereDate('data_vencimento', $hoje)->sum('valor'),
        ];

        // O aging continua GLOBAL (ver docblock do método) — só o escopo de
        // revenda do usuário entra aqui, não período/busca.
        $escopoRevenda = $usuario->temEscopoDeRevenda() ? ['revenda_id' => $usuario->revenda_id] : [];
        $pendentesGlobais = Cobranca::where($escopoRevenda)->where('status', 'pendente')->get(['id', 'valor', 'data_vencimento']);
        $faixas = $this->faixasDeAging($pendentesGlobais, $hoje);
        $emAbertoGlobal = (float) $pendentesGlobais->sum('valor');

        return view('cobrancas.index', array_merge(
            compact('cobrancas', 'kpis', 'faixas', 'hoje', 'emAbertoGlobal', 'contagens', 'contagensTipo'),
            [
                'filtroPeriodo' => $this->filtroPeriodoSelecionado($request),
                'periodoDe' => $periodoDe?->format('Y-m-d'),
                'periodoAte' => $periodoAte?->format('Y-m-d'),
                'busca' => $busca,
                'revendaId' => $revendaId,
                'filtroStatus' => $filtroStatus,
                'filtroTipo' => $filtroTipo,
            ],
            // O formulário de nova receita agora vive num modal desta tela, e
            // não numa página à parte: as listas que ele oferece precisam vir
            // junto com a lista.
            $this->listasDoFormulario()
        ));
    }

    /**
     * As opções rápidas do período — por VENCIMENTO, não por criação: é o
     * que o Gestor navega (mês anterior/atual/próximo, ontem/hoje/amanhã,
     * personalizado). Sem filtro reconhecido, `mes_atual` — ao contrário do
     * Funil (que mostra tudo por padrão), aqui o padrão é o mês corrente
     * porque é a pergunta mais comum de quem abre a tela de receber: "o que
     * vence este mês".
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
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

        return in_array($valor, ['pendente', 'vencido', 'pago', 'cancelado'], true) ? $valor : 'todos';
    }

    private function filtroTipoSelecionado(Request $request): ?string
    {
        $valor = $request->query('tipo_filtro');

        return in_array($valor, ['locacao_sistema', 'avulsa', 'direta'], true) ? $valor : null;
    }

    /**
     * A busca alcança TODO campo que identifica o título, não só descrição —
     * pedido em 16/08/2026: nome, CPF/CNPJ (da revenda e do cliente final),
     * razão social, valor e a própria descrição.
     *
     * CNPJ/CPF comparam pelos DÍGITOS: a coluna grava só número (sem ponto,
     * barra ou traço — ver `Cliente::cpf_cnpj`), então buscar "025.098.147"
     * com pontuação não bateria com nada sem tirar a máscara antes. Se a
     * busca não tem dígito nenhum (ex.: buscou só um nome), o pedaço de CNPJ
     * some da query — comparar com `%%` casaria toda linha.
     *
     * Valor aceita vírgula OU ponto decimal: quem busca "27,50" está
     * digitando reais, não regex — a coluna é armazenada com ponto.
     */
    private function aplicarBusca($query, string $busca)
    {
        $digitos = preg_replace('/\D/', '', $busca);
        $valorBusca = str_replace(',', '.', $busca);

        return $query->where(function ($qq) use ($busca, $digitos, $valorBusca) {
            $qq->where('descricao', 'like', "%{$busca}%")
                ->orWhere('valor', 'like', "%{$valorBusca}%")
                ->orWhereHas('revenda', function ($r) use ($busca, $digitos) {
                    $r->where('nome', 'like', "%{$busca}%");
                    if ($digitos !== '') {
                        $r->orWhere('cnpj', 'like', "%{$digitos}%");
                    }
                })
                ->orWhereHas('cliente', function ($c) use ($busca, $digitos) {
                    $c->where('nome', 'like', "%{$busca}%")
                        ->orWhere('razao_social', 'like', "%{$busca}%");
                    if ($digitos !== '') {
                        $c->orWhere('cpf_cnpj', 'like', "%{$digitos}%");
                    }
                });
        });
    }

    // `faixasDeAging()` subiu para o Controller base quando os Relatórios
    // passaram a repartir o mesmo em-aberto pelas mesmas quatro faixas.

    public function show(Cobranca $cobranca)
    {
        $this->autorizarAcesso($cobranca);
        $cobranca->load('revenda', 'cliente', 'sistema', 'contaFinanceira');

        return view('cobrancas.show', compact('cobranca'));
    }

    /** @return array<string, \Illuminate\Support\Collection> */
    private function listasDoFormulario(): array
    {
        $escopo = auth()->user()->temEscopoDeRevenda()
            ? ['revenda_id' => auth()->user()->revenda_id]
            : [];

        return [
            'revendas' => Revenda::when($escopo, fn ($q) => $q->where($escopo))->orderBy('nome')->get(),
            'clientes' => Cliente::when($escopo, fn ($q) => $q->where($escopo))->orderBy('nome')->get(),
            'sistemas' => Sistema::produtos()->orderBy('nome')->get(),
            'contasFinanceiras' => ContaFinanceira::where('ativo', true)->orderBy('nome')->get(),
        ];
    }

    public function create()
    {
        $listas = $this->listasDoFormulario();

        return view('cobrancas.create', $listas);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data = $this->aplicarEscopo($data);

        Cobranca::create($data);

        return redirect()->route('cobrancas.index')->with('status', 'Receita cadastrada com sucesso.');
    }

    public function edit(Cobranca $cobranca)
    {
        $this->autorizarAcesso($cobranca);

        return view('cobrancas.edit', ['cobranca' => $cobranca] + $this->listasDoFormulario());
    }

    public function update(Request $request, Cobranca $cobranca)
    {
        $this->autorizarAcesso($cobranca);

        $cobranca->update($this->aplicarEscopo($this->validated($request)));

        return redirect()->route('cobrancas.index')->with('status', 'Receita atualizada com sucesso.');
    }

    public function destroy(Cobranca $cobranca)
    {
        $this->autorizarAcesso($cobranca);

        $cobranca->delete();

        return redirect()->route('cobrancas.index')->with('status', 'Receita removida.');
    }

    public function baixar(Request $request, Cobranca $cobranca)
    {
        $this->autorizarAcesso($cobranca);

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

        $cobrancas = Cobranca::when(auth()->user()->temEscopoDeRevenda(), fn ($q) => $q->where('revenda_id', auth()->user()->revenda_id))
            ->whereIn('id', $data['ids'])
            ->where('status', 'pendente')
            ->get();
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
        $this->autorizarAcesso($cobranca);

        return response()->json($cobranca->anexos()->latest()->get());
    }

    public function storeAnexo(Request $request, Cobranca $cobranca)
    {
        $this->autorizarAcesso($cobranca);

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
        $this->autorizarAcesso($anexo->cobranca);

        if (! Storage::disk('public')->exists($anexo->caminho)) {
            abort(404, 'Arquivo não encontrado no servidor.');
        }

        // O único fato do sistema que não deixa marca nenhuma no dado: o
        // arquivo sai daqui e passa a existir na máquina de quem baixou. Boleto
        // e comprovante trazem conta bancária e CNPJ, então "quem levou isto
        // embora, e quando" é pergunta que alguém vai fazer — e a resposta só
        // existe se for gravada no momento.
        Auditoria::registrar(
            recurso: 'cobrancas',
            acao: 'baixou',
            alvo: $anexo->cobranca,
            descricao: $anexo->nome_original,
        );

        return Storage::disk('public')->download($anexo->caminho, $anexo->nome_original);
    }

    public function destroyAnexo(CobrancaAnexo $anexo)
    {
        $this->autorizarAcesso($anexo->cobranca);

        // O arquivo sai junto com a linha, e quem faz isso é o `deleting` do
        // `CobrancaAnexo` — aqui a remoção era feita na mão, e todo caminho
        // novo que apagasse a linha sem repetir o gesto deixava o arquivo.
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
            'tipo' => 'required|in:locacao_sistema,locacao_cliente,avulsa,direta',
            'competencia' => 'nullable|string|max:7',
            'forma_pagamento' => 'nullable|string|max:255',
        ]);
    }

    /**
     * Usuário de revenda não escolhe a revenda da cobrança: ela é sempre a
     * dele. Ignora o campo do formulário e força a própria revenda.
     */
    private function aplicarEscopo(array $data): array
    {
        if (auth()->user()->temEscopoDeRevenda()) {
            $data['revenda_id'] = auth()->user()->revenda_id;
        }

        return $data;
    }

    private function autorizarAcesso(Cobranca $cobranca): void
    {
        $user = auth()->user();

        if ($user->temEscopoDeRevenda() && $cobranca->revenda_id !== $user->revenda_id) {
            abort(403, 'Você só pode acessar as cobranças da sua revenda.');
        }
    }
}
