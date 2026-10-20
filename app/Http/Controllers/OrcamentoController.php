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

    public function index(Request $request): View
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'situacao' => ['nullable', Rule::in(array_keys(Orcamento::SITUACOES))]]);
        $busca = trim((string) ($f['q'] ?? ''));
        $lista = Orcamento::query()
            ->when($busca !== '', function ($q) use ($busca) {
                $termo = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $busca).'%';
                $num = preg_match('/^(\d{1,4})(?:\/(\d{4}))?$/', $busca, $m) ? $m : null;
                $q->where(fn ($w) => $w->where('cliente', 'like', $termo)->orWhere('titulo', 'like', $termo)->orWhere('contato', 'like', $termo)
                    ->orWhereHas('preCliente', fn ($l) => $l->where('nome', 'like', $termo))
                    ->when($num, fn ($x) => $x->orWhere(fn ($y) => $y->where('numero', (int) $num[1])->when($num[2] ?? null, fn ($z, $ano) => $z->where('ano', $ano)))));
            })
            ->when($f['situacao'] ?? null, fn ($q, $s) => $q->where('situacao', $s), fn ($q) => $q->where('situacao', '!=', 'substituido'))
            ->with('preCliente.sistema:id,nome')
            ->orderByDesc('ano')->orderByDesc('numero')->orderByDesc('revisao')->limit(500)->get();
        // Por cliente (padrao-e-historico.md, FR-H2): o pré-cliente do Funil; sem vínculo, o nome digitado.
        $grupos = $lista->groupBy(fn (Orcamento $o) => $o->lead_id ? 'lead:'.$o->lead_id : 'nome:'.mb_strtolower(trim($o->cliente)))
            ->map(fn ($os) => ['lead' => $os->first()->preCliente, 'nome' => $os->first()->preCliente?->nome ?? $os->first()->cliente,
                'projeto' => $os->first()->preCliente?->sistema?->nome ?? Modelos::nome($os->first()->produto), 'orcamentos' => $os,
                'ultimo' => $os->max(fn ($o) => $o->enviado_em ?? $o->updated_at)])
            ->sortByDesc('ultimo')->values();
        $mes = now()->startOfMonth();

        return view('orcamentos.index', [
            'grupos' => $grupos, 'total' => $lista->count(), 'busca' => $busca, 'situacao' => $f['situacao'] ?? null,
            'kpis' => [
                'abertos' => Orcamento::whereIn('situacao', ['rascunho', 'enviado'])->count(),
                'enviados' => (float) Orcamento::whereNotNull('enviado_em')->where('enviado_em', '>=', $mes)->sum('total_unico'),
                'aprovados' => (float) Orcamento::where('situacao', 'aprovado')->where('respondido_em', '>=', $mes)->sum('total_unico'),
                'vencendo' => Orcamento::where('situacao', 'enviado')->get()->filter(fn ($o) => $o->validoAte()?->between(now()->startOfDay(), now()->addDays(3)->endOfDay()))->count(),
            ],
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
            + ['itens' => $orcamento->itens->map(fn ($i) => $i->only(['descricao', 'quantidade', 'unidade', 'valor_unitario', 'cobranca']))->all()],
            'revisoes' => Orcamento::where('ano', $orcamento->ano)->where('numero', $orcamento->numero)->orderBy('revisao')->get(['id', 'revisao', 'situacao'])]);
    }

    public function update(Request $request, Orcamento $orcamento): RedirectResponse
    {
        $this->orcamentos->atualizar($orcamento, $this->validar($request));
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
                'unidade' => $i['unidade'] ?? 'un', 'valor_unitario' => (float) ($i['valor_unitario'] ?? 0), 'cobranca' => array_key_exists($i['cobranca'] ?? '', OrcamentoItem::COBRANCAS) ? $i['cobranca'] : 'unico']));
        $o->setRelation('itens', $itens);
        $o->total_unico = round($itens->where('cobranca', 'unico')->sum(fn ($i) => $i->subtotal()), 2);
        $o->total_mensal = round($itens->where('cobranca', 'mensal')->sum(fn ($i) => $i->subtotal()), 2);
        $o->entrada = min((float) $o->entrada, $o->total_unico);

        return response($this->pdf->html($o, previa: true))->header('Content-Type', 'text/html; charset=utf-8')->header('Cache-Control', 'no-store');
    }

    public function pdf(Orcamento $orcamento): StreamedResponse
    {
        $arquivo = $orcamento->situacao === 'rascunho' ? $this->pdf->atual($orcamento->load('itens')) : ($orcamento->pdf_arquivo && Storage::disk(Pdf::DISCO)->exists($orcamento->pdf_arquivo)
            ? $orcamento->pdf_arquivo : $this->pdf->gerar($orcamento->load('itens')));

        return Storage::disk(Pdf::DISCO)->response($arquivo, $orcamento->nomeDoPdf(), [
            'Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    /** O PDF que foi de fato enviado ao cliente, no histórico importado (FR-H5). */
    public function original(Orcamento $orcamento): StreamedResponse
    {
        abort_unless($orcamento->pdf_original && Storage::disk(Pdf::DISCO)->exists($orcamento->pdf_original), 404);

        return Storage::disk(Pdf::DISCO)->response($orcamento->pdf_original, basename($orcamento->origem_arquivo ?: $orcamento->pdf_original), [
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
        $d = $request->validate(['resposta' => ['required', Rule::in(['aprovado', 'recusado'])]]);
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
        ]);
    }
}
