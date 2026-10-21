<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Orcamento;
use App\Models\OrcamentoItem;
use App\Services\Orcamentos\Modelos;
use App\Services\Orcamentos\Orcamentos;
use App\Services\Orcamentos\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Comercial › Orçamentos (docs/specs/orcamentos-vixcard). Recurso `orcamentos`,
 * fora do "tudo do administrador": só quem recebeu o perfil (Felipe e Victor).
 */
class OrcamentoController extends Controller
{
    public function __construct(private Orcamentos $orcamentos, private Pdf $pdf) {}

    public function index(Request $request, \App\Services\Orcamentos\Painel $painel): View
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'situacao' => ['nullable', Rule::in(array_keys(Orcamento::SITUACOES))],
            'projeto' => ['nullable', 'integer'], 'responsavel' => ['nullable', 'integer'], 'periodo' => ['nullable', Rule::in(array_keys(\App\Services\Orcamentos\Painel::PERIODOS))]]);
        $busca = trim((string) ($f['q'] ?? ''));
        $lista = Orcamento::query()
            ->when($busca !== '', function ($q) use ($busca) {
                $termo = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $busca).'%';
                $num = preg_match('/^(\d{1,4})(?:\/(\d{4})|\.rev\d+)?$/i', $busca, $m) ? $m : null;
                $q->where(fn ($w) => $w->where('cliente', 'like', $termo)->orWhere('titulo', 'like', $termo)->orWhere('contato', 'like', $termo)
                    ->orWhereHas('preCliente', fn ($l) => $l->where('nome', 'like', $termo))
                    ->when($num, fn ($x) => $x->orWhere(fn ($y) => $y->where('numero', (int) $num[1])->when($num[2] ?? null, fn ($z, $ano) => $z->where('ano', $ano)))));
            })
            ->when($f['situacao'] ?? null, fn ($q, $s) => $q->where('situacao', $s), fn ($q) => $q->where('situacao', '!=', 'substituido'))
            ->when($f['projeto'] ?? null, fn ($q, $id) => $q->whereHas('preCliente', fn ($l) => $l->where('sistema_id', $id)))
            ->when($f['responsavel'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->with(['preCliente.sistema:id,nome', 'autor:id,name'])
            ->orderByDesc('updated_at')->limit(300)->get();
        // As revisões de cada número, para a linha da vigente mostrar as anteriores.
        $revisoes = Orcamento::whereIn('numero', $lista->pluck('numero'))->orderBy('revisao')->get(['id', 'ano', 'numero', 'revisao'])
            ->groupBy(fn ($r) => $r->ano.'-'.$r->numero);

        return view('orcamentos.index', [
            'lista' => $lista, 'revisoes' => $revisoes, 'busca' => $busca, 'filtros' => $f,
            'painel' => $painel->montar($f['periodo'] ?? '30'),
            'projetos' => \App\Models\Sistema::whereIn('id', \App\Models\Lead::whereIn('id', Orcamento::whereNotNull('lead_id')->pluck('lead_id'))->pluck('sistema_id'))->orderBy('nome')->get(['id', 'nome']),
            'responsaveis' => \App\Models\User::whereIn('id', Orcamento::distinct()->pluck('user_id'))->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): View
    {
        $produto = array_key_exists((string) $request->query('produto'), Modelos::PRODUTOS) ? (string) $request->query('produto') : 'vix-id';

        $lead = $request->filled('lead') ? Lead::find($request->integer('lead')) : null;

        return view('orcamentos.editor', ['o' => null, 'preClientes' => $this->preClientes(), 'dados' => Modelos::paraFormulario($produto) + ['cliente' => $lead?->nome ?? '', 'contato' => '', 'cidade' => '',
            'validade_dias' => 15, 'entrada' => 0, 'parcelas' => 1, 'desconto_avista' => 0, 'aceite' => true, 'observacoes' => '',
            'lead_id' => $lead?->id, 'tipo' => 'Proposta comercial', 'rotulo_3' => null, 'valor_3' => null, 'natureza' => null,
            'dados_cliente' => ['cnpj' => $lead?->cpf_cnpj, 'telefone' => $lead?->telefone, 'email' => $lead?->email], 'objeto' => null, 'secoes' => [], 'planos' => null, 'pagamento_texto' => null, 'notas_internas' => null]]);
    }

    public function store(Request $request): RedirectResponse
    {
        $o = $this->orcamentos->criar($request->user(), $this->validar($request));
        $this->guardarCapa($request, $o);
        $this->guardarImagens($request, $o);
        if ($request->input('depois') === 'enviar') {
            return $this->enviado($request, $o);
        }

        return redirect()->route('orcamentos.edit', $o)->with('status', 'Orçamento '.$o->codigo().' criado.')
            ->with('abrir_pdf', $request->input('depois') === 'pdf');
    }

    public function edit(Orcamento $orcamento): View
    {
        $orcamento->load('itens', 'autor:id,name');

        return view('orcamentos.editor', ['o' => $orcamento, 'preClientes' => $this->preClientes(), 'dados' => $orcamento->only(['produto', 'cliente', 'contato', 'cidade', 'titulo', 'destaque',
            'lead', 'validade_dias', 'entrada', 'parcelas', 'desconto_avista', 'inclui', 'nao_inclui', 'passos', 'aceite', 'observacoes', ...self::CAMPOS_NOVOS])
            + ['itens' => $orcamento->itens->map(fn ($i) => $i->only(['descricao', 'quantidade', 'unidade', 'valor_unitario', 'cobranca']) + ['categoria' => $i->categoriaEfetiva(), 'opcional' => $i->cobranca === 'opcao'])->all()],
            'revisoes' => Orcamento::where('ano', $orcamento->ano)->where('numero', $orcamento->numero)->orderBy('revisao')->get(['id', 'revisao', 'situacao'])]);
    }

    public function update(Request $request, Orcamento $orcamento): RedirectResponse
    {
        $this->orcamentos->atualizar($orcamento, $this->validar($request));
        $this->guardarCapa($request, $orcamento->fresh());
        $this->guardarImagens($request, $orcamento->fresh());
        if ($request->input('depois') === 'enviar') {
            return $this->enviado($request, $orcamento->fresh());
        }

        return back()->with('status', 'Orçamento salvo.')->with('abrir_pdf', $request->input('depois') === 'pdf');
    }

    /** A prévia viva do editor: o MESMO Blade do PDF, montado sem gravar nada. */
    public function previa(Request $request): Response
    {
        if ($request->boolean('salvo')) {
            return response($this->pdf->html(Orcamento::with('itens')->findOrFail($request->integer('id')), previa: true))
                ->header('Content-Type', 'text/html; charset=utf-8')->header('Cache-Control', 'no-store');
        }
        $d = $this->validar($request, previa: true);
        $o = $request->filled('id') ? Orcamento::findOrFail($request->integer('id'))->replicate() : new Orcamento(['ano' => (int) now()->year]);
        $o->fill(collect($d)->except('itens')->all());
        $itens = collect($d['itens'] ?? [])->filter(fn ($i) => trim((string) ($i['descricao'] ?? '')) !== '')->values()
            ->map(fn ($i, $n) => new OrcamentoItem(['ordem' => $n, 'descricao' => $i['descricao'], 'quantidade' => (float) ($i['quantidade'] ?? 1),
                'unidade' => $i['unidade'] ?? 'un', 'valor_unitario' => (float) ($i['valor_unitario'] ?? 0)] + OrcamentoItem::classificar($i)));
        $o->setRelation('itens', $itens);
        $o->forceFill(\App\Services\Orcamentos\Orcamentos::totais($itens));
        $o->entrada = min((float) $o->entrada, $o->total_unico);

        // A foto escolhida e ainda não salva aparece na hora (como data URI); a já salva, pela rota.
        $capa = null;
        if ($request->hasFile('capa') && $request->file('capa')->isValid()) {
            $capa = 'data:'.$request->file('capa')->getMimeType().';base64,'.base64_encode((string) file_get_contents($request->file('capa')->getRealPath()));
        } elseif (! $request->boolean('remover_capa') && $request->filled('id') && ($salvo = Orcamento::find($request->integer('id')))?->capa_imagem) {
            $capa = route('orcamentos.capa', $salvo->id);
        } elseif ($request->boolean('remover_capa')) {
            $o->capa_imagem = null;
        }

        return response($this->pdf->html($o, previa: true, capaDataUri: $capa))->header('Content-Type', 'text/html; charset=utf-8')->header('Cache-Control', 'no-store');
    }

    /** A foto de capa do orçamento (prévia do editor). */
    public function capa(Orcamento $orcamento): StreamedResponse
    {
        abort_unless($orcamento->capa_imagem && Storage::disk(Pdf::DISCO)->exists($orcamento->capa_imagem), 404);

        return Storage::disk(Pdf::DISCO)->response($orcamento->capa_imagem, null, ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Uma imagem do corpo, pela posição na lista (miniatura do editor e prévia). */
    public function imagem(Orcamento $orcamento, int $n): StreamedResponse
    {
        $arquivo = $orcamento->imagensDoCorpo()[$n]['arquivo'] ?? null;
        abort_unless($arquivo && Storage::disk(Pdf::DISCO)->exists($arquivo), 404);

        return Storage::disk(Pdf::DISCO)->response($arquivo, null, ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function capaPadrao(\App\Services\Orcamentos\Ajustes $ajustes): StreamedResponse
    {
        $arquivo = $ajustes->get('capa_padrao');
        abort_unless($arquivo && Storage::disk(Pdf::DISCO)->exists($arquivo), 404);

        return Storage::disk(Pdf::DISCO)->response($arquivo, null, ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Ajustes do módulo: emissor, clientes que atendemos e capa padrão (FR-H9). */
    public function ajustes(\App\Services\Orcamentos\Ajustes $ajustes): View
    {
        return view('orcamentos.ajustes', ['ajustes' => $ajustes]);
    }

    public function salvarAjustes(Request $request, \App\Services\Orcamentos\Ajustes $ajustes): RedirectResponse
    {
        $d = $request->validate(collect(\App\Services\Orcamentos\Ajustes::CAMPOS)->map(fn () => ['nullable', 'string', 'max:255'])->all()
            + ['clientes' => ['nullable', 'string', 'max:6000'], 'capa_padrao' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'], 'remover_capa_padrao' => ['nullable', 'boolean']]);
        $valores = collect($d)->only([...array_keys(\App\Services\Orcamentos\Ajustes::CAMPOS), 'clientes'])->all();
        if ($request->hasFile('capa_padrao')) {
            $valores['capa_padrao'] = $request->file('capa_padrao')->store(Pdf::PASTA_CAPAS, Pdf::DISCO);
        } elseif ($request->boolean('remover_capa_padrao')) {
            $valores['capa_padrao'] = null;
        }
        $ajustes->gravar($valores);

        return back()->with('status', 'Ajustes dos orçamentos salvos.');
    }

    /** A foto de capa enviada no formulário (ou o pedido de tirar a que havia). */
    private function guardarCapa(Request $request, Orcamento $o): void
    {
        if ($request->hasFile('capa')) {
            $o->forceFill(['capa_imagem' => $request->file('capa')->store(Pdf::PASTA_CAPAS, Pdf::DISCO)])->save();
        } elseif ($request->boolean('remover_capa')) {
            $o->forceFill(['capa_imagem' => null])->save();
        }
    }

    /**
     * As imagens do corpo: as que já estavam ficam na ordem, legenda e lugar da tela (ou saem,
     * se marcadas); as novas entram no fim. O arquivo só se aceita se já era deste orçamento —
     * o caminho vem do formulário e é ele que o Chromium vai abrir. Nada se apaga do disco: a
     * revisão anterior e as cópias usam os mesmos arquivos.
     */
    private function guardarImagens(Request $request, Orcamento $o): void
    {
        if (! $request->has('imagens') && ! $request->hasFile('imagens_novas')) {
            return;
        }
        $lugares = $o->lugaresDeImagem();
        $lugar = fn ($onde) => array_key_exists((string) $onde, $lugares) ? (string) $onde : 'galeria';
        $atuais = collect($o->imagensDoCorpo())->pluck('arquivo')->all();
        $lista = collect((array) $request->input('imagens', []))
            ->filter(fn ($i) => in_array($i['arquivo'] ?? null, $atuais, true) && ! filter_var($i['remover'] ?? false, FILTER_VALIDATE_BOOLEAN))
            ->map(fn ($i) => ['arquivo' => $i['arquivo'], 'legenda' => trim((string) ($i['legenda'] ?? '')), 'onde' => $lugar($i['onde'] ?? null)])
            ->values()->all();
        foreach ((array) $request->file('imagens_novas', []) as $f) {
            $lista[] = ['arquivo' => $f->store(Pdf::PASTA_IMAGENS, Pdf::DISCO), 'legenda' => '', 'onde' => $lugar($request->input('imagens_novas_onde'))];
        }
        $o->forceFill(['imagens' => $lista ?: null])->save();
    }

    public function pdf(Orcamento $orcamento): StreamedResponse
    {
        // O enviado é o PDF congelado no envio. O importado não: o que foi ao cliente é o original
        // guardado à parte, e o daqui acompanha o modelo (20/10/2026, o desenho da FRID).
        $arquivo = $orcamento->situacao === 'rascunho' || $orcamento->importado_em ? $this->pdf->atual($orcamento->load('itens')) : ($orcamento->pdf_arquivo && Storage::disk(Pdf::DISCO)->exists($orcamento->pdf_arquivo)
            ? $orcamento->pdf_arquivo : $this->pdf->gerar($orcamento->load('itens')));

        return Storage::disk(Pdf::DISCO)->response($arquivo, $orcamento->nomeDoPdf(), [
            'Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    public function enviado(Request $request, Orcamento $orcamento): RedirectResponse
    {
        $caminho = $this->orcamentos->enviado($request->user(), $orcamento);

        return redirect()->route('orcamentos.edit', $orcamento)->with('status', 'Marcado como enviado. O PDF desta revisão ficou congelado'
            .($caminho ? ' e vai para Documentos › '.str_replace('/', ' › ', dirname($caminho)).' (OneDrive) em até 1 minuto.' : '.'))->with('abrir_pdf', true);
    }

    public function resposta(Request $request, Orcamento $orcamento): RedirectResponse
    {
        $d = $request->validate(['resposta' => ['required', Rule::in(['negociacao', 'aprovado', 'recusado'])]]);
        $this->orcamentos->responder($orcamento, $d['resposta']);

        return back()->with('status', 'Orçamento '.Orcamento::SITUACOES[$d['resposta']].'.');
    }

    public function revisar(Request $request, Orcamento $orcamento): RedirectResponse
    {
        $nova = $this->orcamentos->revisar($request->user(), $orcamento);

        return redirect()->route('orcamentos.edit', $nova)->with('status', 'Revisão '.$nova->revisao.' criada; a anterior ficou como substituída.');
    }

    public function duplicar(Request $request, Orcamento $orcamento): RedirectResponse
    {
        $nova = $this->orcamentos->duplicar($request->user(), $orcamento->load('itens'));

        return redirect()->route('orcamentos.edit', $nova)->with('status', 'Cópia criada: '.$nova->codigo().'. Ajuste o cliente.');
    }

    public function destroy(Orcamento $orcamento): RedirectResponse
    {
        abort_unless($orcamento->situacao === 'rascunho', 422, 'Só rascunho se apaga.');
        $orcamento->delete();

        return redirect()->route('orcamentos.index')->with('status', 'Rascunho apagado.');
    }

    /** Os campos da etapa 2 que vão e voltam do editor. */
    private const CAMPOS_NOVOS = ['lead_id', 'tipo', 'rotulo_3', 'valor_3', 'natureza', 'dados_cliente', 'objeto', 'secoes', 'planos', 'pagamento_texto', 'notas_internas'];

    /** Os pré-clientes para o seletor do editor, com o que preenche o orçamento. */
    private function preClientes(): array
    {
        return Lead::with('sistema:id,nome')->whereNotIn('estagio', ['perdido'])->orderBy('nome')->get(['id', 'nome', 'cpf_cnpj', 'telefone', 'email', 'sistema_id'])
            ->map(fn (Lead $l) => ['id' => $l->id, 'nome' => $l->nome, 'cnpj' => $l->cpf_cnpj, 'telefone' => $l->telefone, 'email' => $l->email, 'projeto' => $l->sistema?->nome])
            ->all();
    }

    private function validar(Request $request, bool $previa = false): array
    {
        return $request->validate([
            'produto' => ['required', Rule::in(array_keys(Modelos::PRODUTOS))],
            'cliente' => [$previa ? 'nullable' : 'required', 'string', 'max:160'],
            'contato' => ['nullable', 'string', 'max:120'],
            'cidade' => ['nullable', 'string', 'max:120'],
            'titulo' => [$previa ? 'nullable' : 'required', 'string', 'max:120'],
            'destaque' => ['nullable', 'string', 'max:80'],
            'lead' => ['nullable', 'string', 'max:600'],
            'validade_dias' => ['nullable', 'integer', Rule::in(Orcamento::VALIDADES)],
            'entrada' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'parcelas' => ['required', 'integer', Rule::in(Orcamento::PARCELAS)],
            'desconto_avista' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'inclui' => ['nullable', 'string', 'max:3000'],
            'nao_inclui' => ['nullable', 'string', 'max:3000'],
            'passos' => ['nullable', 'string', 'max:3000'],
            'aceite' => ['nullable', 'boolean'],
            'observacoes' => ['nullable', 'string', 'max:3000'],
            'itens' => ['nullable', 'array', 'max:60'],
            'itens.*.descricao' => ['nullable', 'string', 'max:255'],
            'itens.*.quantidade' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'itens.*.unidade' => ['nullable', 'string', 'max:20'],
            'itens.*.valor_unitario' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'itens.*.cobranca' => ['nullable', Rule::in(array_keys(OrcamentoItem::COBRANCAS))],
            'itens.*.categoria' => ['nullable', Rule::in(array_keys(OrcamentoItem::CATEGORIAS))],
            'itens.*.opcional' => ['nullable', 'boolean'],
            // Etapa 2 (padrao-e-historico.md).
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'tipo' => ['nullable', 'string', 'max:40'],
            'rotulo_3' => ['nullable', 'string', 'max:30'],
            'valor_3' => ['nullable', 'string', 'max:160'],
            'natureza' => ['nullable', Rule::in(array_keys(Orcamento::NATUREZAS))],
            'dados_cliente' => ['nullable', 'array'],
            'dados_cliente.*' => ['nullable', 'string', 'max:255'],
            'objeto' => ['nullable', 'string', 'max:3000'],
            'secoes' => ['nullable', 'array'],
            'secoes.*.ativo' => ['nullable', 'boolean'],
            'secoes.*.*' => ['nullable', 'string', 'max:12000'],
            'planos' => ['nullable', 'string', 'max:3000'],
            'pagamento_texto' => ['nullable', 'string', 'max:3000'],
            'notas_internas' => ['nullable', 'string', 'max:6000'],
            'capa' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'remover_capa' => ['nullable', 'boolean'],
            'imagens' => ['nullable', 'array', 'max:40'],
            'imagens.*.arquivo' => ['required', 'string', 'max:255'],
            'imagens.*.legenda' => ['nullable', 'string', 'max:300'],
            'imagens.*.onde' => ['nullable', 'string', 'max:120'],
            'imagens.*.remover' => ['nullable', 'boolean'],
            'imagens_novas' => ['nullable', 'array', 'max:10'],
            'imagens_novas.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'imagens_novas_onde' => ['nullable', 'string', 'max:120'],
        ]);
    }
}
