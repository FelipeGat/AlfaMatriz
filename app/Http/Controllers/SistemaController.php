<?php

namespace App\Http\Controllers;

use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\IndicadoresService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SistemaController extends Controller
{
    public function __construct(private readonly IndicadoresService $indicadores) {}

    public function index(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        // A lista INTEIRA continua sendo carregada: ela alimenta o resumo do
        // topo, a seleção por query string e a participação de cada sistema no
        // total. Só a TABELA é cortada em páginas, no fim.
        $todos = Sistema::produtos()
            ->withCount(['clientes' => fn ($q) => $q->where('clientes.ativo', true)->where('cliente_sistema.ativo', true)])
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

    /**
     * O formulário de cadastro.
     *
     * A natureza chega pela query string porque o botão que traz para cá sabe
     * de qual aba partiu: quem clica em "Novo" na aba de internos está
     * cadastrando um interno, e ter de escolher de novo no formulário é
     * perguntar o que a pessoa acabou de responder.
     */
    public function create(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        $natureza = $request->query('natureza') === 'interno' ? 'interno' : 'produto';

        return view('sistemas.create', compact('natureza'));
    }

    public function store(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate($this->regras($request) + [
            'nome' => 'required|string|max:255',
            // O slug é a chave por onde a marca do produto é resolvida
            // (`x-marca-sistema`) e por onde `sincronizar:sistemas` acha o
            // sistema. Em branco, sai do nome; digitado, vale o que digitaram.
            //
            // `:ascii` porque ele vai para a URL e para o nome de arquivo da
            // marca: `alpha_dash` sozinho aceita letra acentuada, e "café" no
            // slug produz um endereço que não se copia inteiro.
            'slug' => 'nullable|string|max:255|alpha_dash:ascii|unique:sistemas,slug',
            'natureza' => 'required|in:'.implode(',', array_keys(Sistema::NATUREZAS)),
        ]);

        // `?? null` antes do `?:`: campo ausente do envio nem chega ao array
        // validado — só o campo VAZIO chega como null. Os dois casos querem o
        // slug derivado do nome, e o acesso direto estourava no primeiro.
        $data['slug'] = ($data['slug'] ?? null) ?: $this->slugLivre($data['nome']);
        $data['ativo'] = $request->boolean('ativo');
        $data = $this->limparComercial($data, $data['natureza']);

        // Sem poder nenhum ao nascer. Capacidade se declara depois, uma a uma:
        // um sistema não deve ganhar o direito de provisionar cliente porque
        // alguém preencheu um formulário de cadastro.
        $data['capacidades'] = [];

        $sistema = Sistema::create($data);

        return redirect()
            ->route('produtos.index', $sistema->ehInterno() ? ['aba' => 'internos'] : [])
            ->with('status', "{$sistema->nome} cadastrado.");
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

        // `sometimes` e não `required`: o nome é campo NOVO desta tela, e a
        // edição é parcial por natureza — quem manda um formulário sem ele está
        // salvando outra coisa, não apagando o nome. No cadastro é `required`
        // de verdade: lá não há nome anterior para preservar.
        $data = $request->validate($this->regras($request) + [
            'nome' => 'sometimes|required|string|max:255',
        ]);

        // O slug NÃO se edita depois de criado. Ele é a chave que amarra a
        // marca na tela e o `sincronizar:sistemas` na linha certa; trocá-lo
        // desfaz as duas amarras em silêncio, e nada na tela avisaria.

        // Trocar de natureza só enquanto ninguém depende da resposta
        // comercial. A recusa é validação, e não um `unset` calado: quem tentou
        // precisa saber que não mudou — senão salva, vê o campo voltar ao que
        // era e conclui que a tela perdeu o que ele digitou.
        if ($request->filled('natureza') && $request->input('natureza') !== $sistema->natureza) {
            if (! $sistema->podeTrocarDeNatureza()) {
                return back()->withInput()->withErrors([
                    'natureza' => 'Este sistema já tem cliente ou tier de atacado. '
                        .'Tirá-lo do catálogo agora o removeria do faturamento sem aviso.',
                ]);
            }

            $data['natureza'] = $request->input('natureza');
        }

        $data['ativo'] = $request->boolean('ativo');
        $data = $this->limparComercial($data, $data['natureza'] ?? $sistema->natureza);

        // Campo em branco significa "não mexer na chave" — é o que a tela promete
        // no placeholder ("preencher para trocar"). Sem isto, salvar a tela para
        // corrigir só o endereço apaga o token e desliga a integração em silêncio:
        // o campo vazio chega como null (ConvertEmptyStringsToNull), passa no
        // `nullable` e sobrescreve a chave.
        if (blank($data['token'] ?? null)) {
            unset($data['token']);
        }

        $sistema->update($data);

        return redirect()
            ->route('produtos.index', $sistema->ehInterno() ? ['aba' => 'internos'] : [])
            ->with('status', 'Sistema atualizado com sucesso.');
    }

    /**
     * As regras que cadastro e edição têm em comum.
     *
     * O que separa produto de interno é o bloco COMERCIAL: categoria e unidade
     * de cobrança são obrigatórias para quem se vende e não existem para quem
     * não se vende. Exigi-las do interno forçaria a inventar uma unidade para
     * quem não é cobrado — e a invenção apareceria na tela como informação.
     *
     * O `nullable` sozinho não bastaria: o campo em branco chega como null pelo
     * `ConvertEmptyStringsToNull`, e o produto cadastrado sem unidade entraria
     * no fechamento sem ter o que contar.
     *
     * @return array<string, string>
     */
    private function regras(Request $request): array
    {
        $interno = $request->input('natureza') === 'interno';

        return [
            'categoria' => $interno ? 'nullable|in:saas,crm' : 'required|in:saas,crm',
            'unidade_cobranca' => $interno ? 'nullable|string|max:255' : 'required|string|max:255',
            'base_url' => 'nullable|url|max:255',
            'token' => 'nullable|string',
            'versao' => 'nullable|string|max:255',
            'responsavel' => 'nullable|string|max:255',
            // Desde quando o produto está no catálogo — ver a migration
            // `data_de_cadastro_de_revenda_e_sistema` para o porquê de não ser
            // o `created_at`.
            'data_cadastro' => 'nullable|date',
        ];
    }

    /**
     * Zera o que só faz sentido em produto.
     *
     * Os dois campos viajam no formulário mesmo escondidos — o `x-show` não
     * desliga o `input`. Sem esta limpeza, quem preenche a unidade de cobrança
     * e só então troca a natureza para interno grava um sistema que não é
     * cobrado carregando a unidade pela qual seria.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function limparComercial(array $data, string $natureza): array
    {
        if ($natureza === 'interno') {
            $data['categoria'] = null;
            $data['unidade_cobranca'] = null;
        }

        return $data;
    }

    /**
     * Um slug que ainda não está em uso, derivado do nome.
     *
     * O sufixo numérico é a saída para o nome que colide — dois "Alfa Home" em
     * momentos diferentes da casa. Sem ele, o cadastro devolveria erro de
     * unicidade num campo que a pessoa nem viu, porque ela digitou o NOME.
     */
    private function slugLivre(string $nome): string
    {
        $base = Str::slug($nome) ?: 'sistema';
        $slug = $base;
        $sufixo = 1;

        while (Sistema::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$sufixo);
        }

        return $slug;
    }
}
