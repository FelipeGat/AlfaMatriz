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

        // A lista INTEIRA continua sendo carregada: ela alimenta o resumo do
        // topo, a seleção por query string e a participação de cada sistema no
        // total. Só a TABELA é cortada em páginas, no fim.
        $todos = Sistema::withCount(['clientes' => fn ($q) => $q->where('clientes.ativo', true)->where('cliente_sistema.ativo', true)])
            ->with(['precosAtacado' => fn ($q) => $q->whereNull('revenda_id')->orderBy('ordem')])
            ->orderBy('categoria')
            ->orderBy('nome')
            ->get();

        // A seleção vive na query string: sobrevive ao recarregar e pode ser
        // compartilhada por link. Ela procura no conjunto todo, não na página:
        // um link para o sistema da página 3 precisa continuar abrindo nele.
        $selecionado = $todos->firstWhere('id', (int) $request->sistema) ?? $todos->first();

        $detalhe = $selecionado ? $this->detalharSistema($selecionado, $todos) : null;

        // Resumo do topo. O atacado sai da mesma origem do painel Comercial —
        // é o que garante que os dois mostrem o mesmo número.
        $sistemasAtivos = $this->indicadores->sistemasAtivos();
        $clientesAtivos = $this->indicadores->clientesAtivos();
        $mrrAtacado = $this->indicadores->mrrAtacado();
        // Só vínculo de sistema ATIVO. O numerador já não conta produto
        // desativado — ele fica fora do fechamento —, e dividir por licenças
        // que ele ignora achatava o preço médio na proporção do catálogo
        // aposentado: quanto mais produto antigo na base, menor o preço médio
        // que a tela mostrava, sem nada ter mudado de preço.
        $vinculosAtivos = (int) $todos->where('ativo', true)->sum('clientes_count');
        $precoMedio = $vinculosAtivos > 0 ? $mrrAtacado / $vinculosAtivos : 0.0;

        // O aviso de "sem preço de atacado" era contado na própria view. Com a
        // tabela paginada isso passaria a contar só a página, e a pendência
        // sumiria do rodapé por estar na página seguinte — some justamente
        // quando é mais importante ver.
        $semTier = $todos->filter(fn (Sistema $s) => $s->precosAtacado->isEmpty())->count();

        $sistemas = $this->paginarColecao($todos);

        return view('sistemas.index', compact(
            'sistemas', 'selecionado', 'detalhe', 'semTier',
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

        // O MRR e a abertura por revenda saem da MESMA origem, no modelo. Este
        // laço já existiu aqui — era a quarta cópia da conta de tier no
        // código, e a única sem a guarda de produto desativado: o painel
        // mostraria receita de um sistema que o resto do sistema dá como zero.
        $mrrPorRevenda = $sistema->mrrPorRevenda();
        $mrr = (float) $mrrPorRevenda->sum();

        $revendas = Revenda::whereIn('id', $porRevenda->keys()->filter())->get()->keyBy('id');

        $ranking = $porRevenda
            ->map(fn ($doRevenda, $revendaId) => [
                'nome' => $revendas[$revendaId]->nome ?? 'Venda direta',
                'clientes' => $doRevenda->count(),
                'mrr' => (float) ($mrrPorRevenda[$revendaId] ?? 0),
            ])
            ->sortByDesc('clientes')
            ->values();

        // Participação sobre o catálogo ATIVO, que é o que o resumo do topo
        // conta: medir contra os desativados encolheria a fatia de todo mundo.
        $totalClientesTodosSistemas = max($todos->where('ativo', true)->sum('clientes_count'), 1);

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
            // Desde quando o produto está no catálogo — ver a migration
            // `data_de_cadastro_de_revenda_e_sistema` para o porquê de não ser
            // o `created_at`.
            'data_cadastro' => 'nullable|date',
        ]);

        $data['ativo'] = $request->boolean('ativo');

        // Campo em branco significa "não mexer na chave" — é o que a tela promete
        // no placeholder ("preencher para trocar"). Sem isto, salvar a tela para
        // corrigir só o endereço apaga o token e desliga a integração em silêncio:
        // o campo vazio chega como null (ConvertEmptyStringsToNull), passa no
        // `nullable` e sobrescreve a chave.
        if (blank($data['token'] ?? null)) {
            unset($data['token']);
        }

        $sistema->update($data);

        return redirect()->route('produtos.index')->with('status', 'Sistema atualizado com sucesso.');
    }
}
