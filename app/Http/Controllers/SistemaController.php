<?php

namespace App\Http\Controllers;

use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\IndicadoresService;
use Illuminate\Http\Request;

class SistemaController extends Controller
{
    public function __construct(private readonly IndicadoresService $indicadores) {}

    public function index(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        $sistemas = Sistema::withCount(['clientes' => fn ($q) => $q->where('clientes.ativo', true)->where('cliente_sistema.ativo', true)])
            ->with(['precosAtacado' => fn ($q) => $q->whereNull('revenda_id')->orderBy('ordem')])
            ->orderBy('categoria')
            ->orderBy('nome')
            ->get();

        // A seleção vive na query string: sobrevive ao recarregar e pode ser
        // compartilhada por link.
        $selecionado = $sistemas->firstWhere('id', (int) $request->sistema) ?? $sistemas->first();

        $detalhe = $selecionado ? $this->detalharSistema($selecionado, $sistemas) : null;

        // Resumo do topo. O atacado sai da mesma origem do painel Comercial —
        // é o que garante que os dois mostrem o mesmo número.
        $sistemasAtivos = $this->indicadores->sistemasAtivos();
        $clientesAtivos = $this->indicadores->clientesAtivos();
        $mrrAtacado = $this->indicadores->mrrAtacado();
        $vinculosAtivos = (int) $sistemas->sum('clientes_count');
        $precoMedio = $vinculosAtivos > 0 ? $mrrAtacado / $vinculosAtivos : 0.0;

        return view('sistemas.index', compact(
            'sistemas', 'selecionado', 'detalhe',
            'sistemasAtivos', 'clientesAtivos', 'mrrAtacado', 'vinculosAtivos', 'precoMedio'
        ));
    }

    /**
     * Números do painel de detalhe. O "quem revende" traz só as cinco maiores
     * e o resto vira um rodapé — o card precisa continuar do mesmo tamanho
     * com 5 ou com 50 revendas.
     */
    private function detalharSistema(Sistema $sistema, $todos): array
    {
        $clientes = $sistema->clientes()
            ->where('clientes.ativo', true)
            ->where('cliente_sistema.ativo', true)
            ->get(['clientes.id', 'clientes.nome', 'clientes.revenda_id']);

        $porRevenda = $clientes->groupBy('revenda_id');

        $mrr = 0.0;
        foreach ($porRevenda as $revendaId => $doRevenda) {
            // Mesma normalização do CentroControleController: venda direta chega
            // como chave vazia e precisa virar null antes do tier.
            $tier = $sistema->tierParaVolume($doRevenda->count(), $sistema->chaveDeRevenda($revendaId));
            $mrr += $tier?->calcularMensalidade($doRevenda->count()) ?? 0;
        }

        $revendas = Revenda::whereIn('id', $porRevenda->keys()->filter())->get()->keyBy('id');

        $ranking = $porRevenda
            ->map(fn ($doRevenda, $revendaId) => [
                'nome' => $revendas[$revendaId]->nome ?? 'Venda direta',
                'clientes' => $doRevenda->count(),
            ])
            ->sortByDesc('clientes')
            ->values();

        $totalClientesTodosSistemas = max($todos->sum('clientes_count'), 1);

        return [
            'clientes_ativos' => $clientes->count(),
            'mrr' => $mrr,
            'preco_medio' => $clientes->count() > 0 ? $mrr / $clientes->count() : 0.0,
            'participacao' => ($clientes->count() / $totalClientesTodosSistemas) * 100,
            'tiers' => $sistema->precosAtacado->whereNull('revenda_id')->sortBy('ordem')->values(),
            'tier_vigente' => $sistema->tierParaVolume($clientes->count()),
            'top_revendas' => $ranking->take(5),
            'outras_revendas' => max($ranking->count() - 5, 0),
            'clientes_em_outras' => $ranking->skip(5)->sum('clientes'),
        ];
    }

    public function edit(Sistema $sistema)
    {
        $this->bloquearVisaoDaMatriz();

        $sistema->load(['precosAtacado' => fn ($q) => $q->orderBy('ordem'), 'precosAtacado.revenda']);
        $revendas = \App\Models\Revenda::orderBy('nome')->get();

        return view('sistemas.edit', compact('sistema', 'revendas'));
    }

    public function update(Request $request, Sistema $sistema)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'categoria' => 'required|in:saas,crm',
            'unidade_cobranca' => 'required|string|max:255',
            'base_url' => 'nullable|url|max:255',
            'token' => 'nullable|string',
        ]);

        $data['ativo'] = $request->boolean('ativo');

        $sistema->update($data);

        return redirect()->route('produtos.index')->with('status', 'Sistema atualizado com sucesso.');
    }
}
