<?php

namespace App\Http\Controllers;

use App\Models\Revenda;
use App\Models\Sistema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SistemaController extends Controller
{
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

        // `marca` não é coluna: o arquivo é achado pelo slug, então ele sai do
        // array antes de virar `create`. Sem isto o Eloquent o descartaria em
        // silêncio hoje — e estouraria no dia em que alguém ligar o
        // `preventSilentlyDiscardingAttributes`.
        unset($data['marca'], $data['remover_marca']);

        $sistema = Sistema::create($data);

        // Depois do `create`, e não antes: o nome do arquivo é o slug, e no
        // cadastro o slug só existe depois de gerado a partir do nome.
        $this->salvarMarca($request, $sistema);

        return redirect()
            ->route('produtos.index', $sistema->ehInterno() ? ['aba' => 'internos'] : [])
            ->with('status', "{$sistema->nome} cadastrado.");
    }

    public function edit(Sistema $sistema)
    {
        $this->bloquearVisaoDaMatriz();

        $sistema->load(['precosAtacado' => fn ($q) => $q->orderBy('ordem'), 'precosAtacado.revenda']);
        $revendas = Revenda::orderBy('nome')->get();

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

        unset($data['marca'], $data['remover_marca']);

        $sistema->update($data);

        $this->salvarMarca($request, $sistema);

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
            // A marca do sistema. SVG fica DE FORA de propósito, e é a única
            // restrição desta tela que não é sobre tamanho: SVG é XML, aceita
            // `<script>` dentro, e seria servido do mesmo domínio do painel —
            // um logo enviado viraria execução de código na sessão de quem
            // abrisse o quadro de tarefas. As marcas em SVG que existem hoje
            // vieram pelo repositório, que passa por revisão; o que chega por
            // formulário é raster.
            //
            // `image` além do `mimes` porque ele confere o CONTEÚDO: script
            // renomeado para `.png` passa na extensão e morre aqui.
            'marca' => 'nullable|image|mimes:png,webp,jpg|max:512',
            'remover_marca' => 'nullable|boolean',
            // Desde quando o produto está no catálogo — ver a migration
            // `data_de_cadastro_de_revenda_e_sistema` para o porquê de não ser
            // o `created_at`.
            'data_cadastro' => 'nullable|date',
        ];
    }

    /**
     * Grava a marca enviada, ou apaga a que havia.
     *
     * O arquivo vai para o disco `public`, e não para `public/marcas/` do
     * repositório: em produção a pasta da aplicação troca a cada publicação
     * (azul/verde), e a marca gravada lá dentro sumiria no deploy seguinte —
     * sem erro nenhum, só um ícone que um dia deixa de aparecer. O disco
     * `public` aponta para `compartilhado/`, que sobrevive à troca. É a mesma
     * razão pela qual os anexos de cobrança moram lá.
     *
     * O nome é o SLUG, e não um id aleatório como nos anexos: aqui o arquivo
     * não é um entre vários, é O ícone daquele sistema. Nomeado pelo slug, ele
     * é achado pelo mesmo caminho das marcas que vêm no repositório, e não
     * precisa de coluna no banco para ser encontrado.
     */
    private function salvarMarca(Request $request, Sistema $sistema): void
    {
        if (! $request->hasFile('marca') && ! $request->boolean('remover_marca')) {
            return;
        }

        // A anterior sai em QUALQUER extensão antes de a nova entrar. Sem isto,
        // trocar um PNG por um WEBP deixaria os dois no disco, e quem decidiria
        // qual aparece seria a ordem de busca do componente — o sistema
        // mostraria a marca velha e ninguém saberia por quê.
        foreach (['png', 'webp', 'jpg'] as $extensao) {
            Storage::disk('public')->delete('marcas/'.$sistema->slug.'.'.$extensao);
        }

        if (! $request->hasFile('marca')) {
            return;
        }

        $arquivo = $request->file('marca');

        // A extensão sai do CONTEÚDO (`extension()` consulta o mime), e não do
        // nome que veio do navegador: o nome é texto do usuário e entraria no
        // caminho do arquivo. `jpeg` é normalizado para `jpg` porque é a
        // extensão que o componente procura — as duas nomeiam o mesmo formato,
        // e aceitar as duas faria a busca depender de qual chegou.
        $extensao = $arquivo->extension() === 'jpeg' ? 'jpg' : $arquivo->extension();

        $arquivo->storeAs('marcas', $sistema->slug.'.'.$extensao, 'public');
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
