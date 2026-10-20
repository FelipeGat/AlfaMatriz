<?php

namespace App\Http\Controllers;

use App\Models\Documento;
use App\Models\DocumentoPasta;
use App\Models\User;
use App\Services\Documentos\Alteracoes;
use App\Services\Documentos\Espelho;
use App\Services\Documentos\Miniaturas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Comercial › Documentos (docs/specs/documentos-espelhados): as pastas que a
 * pessoa pode ver, e os arquivos servidos só por aqui (FR-001, FR-003).
 */
class DocumentosController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $pastas = DocumentoPasta::visiveisPara($request->user())->with('dono:id,name')->orderBy('nome')->get();
        if ($pastas->count() === 1) {
            return redirect()->route('documentos.pasta', $pastas->first());
        }

        return view('documentos.index', ['pastas' => $pastas]);
    }

    /**
     * A pasta navegada por níveis (Felipe, 19/10/2026: "cards explicativos do
     * que irão encontrar dentro"): cada subpasta do nível é um card com o que
     * tem dentro — quantos arquivos de cada tipo, subpastas e a última
     * mudança —, e os arquivos do próprio nível vão em lista. Com busca, o
     * resultado é plano, de todas as subpastas.
     */
    public function pasta(Request $request, int $pasta): View
    {
        $p = $this->visivel($request, $pasta);
        $busca = trim((string) $request->query('q', ''));
        $todos = $p->documentos()->orderBy('subpasta')->orderBy('nome')->get();
        // O nível pedido só vale se existir: caminho inventado volta para a raiz.
        $nivel = trim(str_replace('\\', '/', (string) $request->query('caminho', '')), '/');
        if ($nivel !== '' && ! $todos->contains(fn (Documento $d) => $d->subpasta === $nivel || str_starts_with($d->subpasta, $nivel.'/'))
            && ! in_array($nivel, $p->subpastas ?? [], true)) {
            $nivel = '';
        }

        if ($busca !== '') {
            $achados = $todos->filter(fn (Documento $d) => mb_stripos($d->caminho, $busca) !== false)->values();

            return view('documentos.pasta', $this->comuns($request, $p) + ['busca' => $busca, 'nivel' => '', 'trilha' => [], 'cards' => collect(), 'arquivos' => $achados]);
        }

        $abaixo = $todos->filter(fn (Documento $d) => $nivel === '' || $d->subpasta === $nivel || str_starts_with($d->subpasta, $nivel.'/'));
        $resto = fn (string $sub) => ltrim(substr($sub, strlen($nivel)), '/');
        // Pastas que existem sem arquivo (criadas pelo Matriz ou vazias no OneDrive) também viram card (FR-006).
        $vazias = collect($p->subpastas ?? [])->filter(fn ($s) => $nivel === '' || str_starts_with($s, $nivel.'/'));
        $nomes = $abaixo->toBase()->map(fn ($d) => $resto($d->subpasta))->merge($vazias->map($resto))->filter(fn ($r) => $r !== '')
            ->map(fn ($r) => explode('/', $r)[0])->unique();
        $cards = $nomes->mapWithKeys(function ($nome) use ($nivel, $resto, $abaixo, $vazias) {
            $caminho = ltrim($nivel.'/'.$nome, '/');
            $docs = $abaixo->filter(fn ($d) => $d->subpasta === $caminho || str_starts_with($d->subpasta, $caminho.'/'));
            $sub = $docs->toBase()->map(fn ($d) => $d->subpasta)->merge($vazias)->filter(fn ($s) => str_starts_with($s, $caminho.'/'))
                ->map(fn ($s) => explode('/', substr($s, strlen($caminho) + 1))[0])->unique()->values();

            return [$nome => [
                'nome' => $nome,
                'caminho' => $caminho,
                'arquivos' => $docs->count(),
                'bytes' => (int) $docs->sum('tamanho'),
                'tipos' => $docs->countBy(fn (Documento $d) => $d->tipo())->sortDesc()->all(),
                'subpastas' => $sub,
                'exemplos' => $sub->isNotEmpty() ? $sub->take(4)->all() : $docs->sortByDesc('modificado_em')->take(3)->pluck('nome')->all(),
                'atualizado' => $docs->max('modificado_em'),
            ]];
        })->sortKeys(SORT_NATURAL | SORT_FLAG_CASE)->values();
        $trilha = [];
        $acumulado = '';
        foreach ($nivel === '' ? [] : explode('/', $nivel) as $parte) {
            $acumulado = ltrim($acumulado.'/'.$parte, '/');
            $trilha[] = ['nome' => $parte, 'caminho' => $acumulado];
        }

        return view('documentos.pasta', $this->comuns($request, $p) + [
            'busca' => '', 'nivel' => $nivel, 'trilha' => $trilha, 'cards' => $cards,
            'arquivos' => $abaixo->filter(fn ($d) => $d->subpasta === $nivel)->values(),
        ]);
    }

    public function criarPasta(Request $request, int $pasta, Alteracoes $alteracoes): RedirectResponse
    {
        $p = $this->visivel($request, $pasta);
        $d = $request->validate(['nome' => ['required', 'string', 'max:120'], 'nivel' => ['nullable', 'string', 'max:600']]);
        $alteracoes->criarPasta($p, $request->user(), trim((string) ($d['nivel'] ?? ''), '/'), $d['nome']);

        return back()->with('status', 'Pasta "'.trim($d['nome']).'" anotada: a sua máquina cria no OneDrive em até 1 minuto.');
    }

    /** Uma parte de um arquivo arrastado para a tela (o navegador manda em partes de 8 MB). */
    public function enviar(Request $request, int $pasta, Alteracoes $alteracoes): JsonResponse
    {
        $p = $this->visivel($request, $pasta);
        $d = $request->validate([
            'envio' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
            'nome' => ['required', 'string', 'max:255'],
            'nivel' => ['nullable', 'string', 'max:600'],
            'tamanho' => ['required', 'integer', 'min:0', 'max:'.Documento::MAX_BYTES],
            'parte' => ['required', 'integer', 'min:0', 'max:100'],
            'partes' => ['required', 'integer', 'min:1', 'max:100'],
            'arquivo' => ['required', 'file', 'max:'.(int) (Espelho::PARTE_BYTES / 1024 + 1)],
        ]);
        $op = $alteracoes->receberParte($p, $request->user(), $d, $request->file('arquivo'));

        return response()->json(['ok' => true, 'completo' => $op !== null]);
    }

    public function apagar(Request $request, int $pasta, Alteracoes $alteracoes): RedirectResponse
    {
        $p = $this->visivel($request, $pasta);
        $d = $request->validate(['caminho' => ['required', 'string', 'max:600']]);
        $alteracoes->apagar($p, $request->user(), $d['caminho']);

        return back()->with('status', '"'.basename($d['caminho']).'" será apagado do OneDrive pela sua máquina em até 1 minuto (fica na lixeira do OneDrive por 30 dias).');
    }

    public function mover(Request $request, int $pasta, Alteracoes $alteracoes): RedirectResponse
    {
        $p = $this->visivel($request, $pasta);
        $d = $request->validate(['caminho' => ['required', 'string', 'max:600'], 'destino' => ['required', 'string', 'max:600']]);
        $alteracoes->mover($p, $request->user(), $d['caminho'], $d['destino']);

        return back()->with('status', '"'.basename($d['caminho']).'" vai para "'.trim($d['destino'], '/').'" em até 1 minuto.');
    }

    /** Quantas alterações ainda esperam a máquina: a tela recarrega quando zera. */
    public function pendentes(Request $request, int $pasta): JsonResponse
    {
        $p = $this->visivel($request, $pasta);

        return response()->json(['pendentes' => $p->operacoes()->where('situacao', 'pendente')->count()]);
    }

    /** O que a tela precisa em qualquer modo. */
    private function comuns(Request $request, DocumentoPasta $p): array
    {
        return [
            'pasta' => $p,
            'pendentes' => $p->operacoes()->with('autor:id,name')->where('situacao', 'pendente')->orderBy('id')->get(),
            'recentes' => $p->operacoes()->with('autor:id,name')->where('situacao', '!=', 'pendente')->where('updated_at', '>=', now()->subDays(7))->latest('id')->limit(8)->get(),
            'outras' => DocumentoPasta::visiveisPara($request->user())->whereKeyNot($p->id)->orderBy('nome')->get(['id', 'nome']),
            'pessoas' => $p->ehDono($request->user()) ? User::where('ativo', true)->whereKeyNot($p->user_id)->orderBy('name')->get(['id', 'name']) : collect(),
        ];
    }


    public function ver(Request $request, int $documento): StreamedResponse
    {
        $doc = $this->documento($request, $documento);
        abort_unless($doc->abreNoNavegador(), 404);

        return $this->servir($doc, 'inline');
    }

    /** A miniatura (JPEG regravado pelo servidor), ou 404 — a tela cai no ícone. */
    public function miniatura(Request $request, int $documento, Miniaturas $miniaturas): StreamedResponse
    {
        $doc = $this->documento($request, $documento);
        $arquivo = $miniaturas->arquivo($doc);
        abort_unless($arquivo, 404);

        return Storage::disk(Documento::DISCO)->response($arquivo, 'miniatura.jpg', [
            'Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=604800',
        ], 'inline');
    }

    public function baixar(Request $request, int $documento): StreamedResponse
    {
        return $this->servir($this->documento($request, $documento), 'attachment');
    }

    /** Com quem a pasta está compartilhada: só o dono muda. */
    public function acessos(Request $request, int $pasta): RedirectResponse
    {
        $p = DocumentoPasta::where('user_id', $request->user()->id)->findOrFail($pasta);
        $ids = $request->validate(['pessoas' => ['nullable', 'array'], 'pessoas.*' => ['integer']])['pessoas'] ?? [];
        $validos = User::where('ativo', true)->whereKeyNot($p->user_id)->whereIn('id', $ids)->pluck('id');
        $p->pessoas()->sync($validos);

        return back()->with('status', 'Acesso atualizado: '.($validos->isEmpty() ? 'só você.' : $validos->count().' pessoa(s) além de você.'));
    }

    private function visivel(Request $request, int $pasta): DocumentoPasta
    {
        return DocumentoPasta::visiveisPara($request->user())->findOrFail($pasta);
    }

    private function documento(Request $request, int $documento): Documento
    {
        $doc = Documento::findOrFail($documento);
        $this->visivel($request, $doc->pasta_id);

        return $doc;
    }

    private function servir(Documento $doc, string $modo): StreamedResponse
    {
        $disco = Storage::disk(Documento::DISCO);
        abort_unless($disco->exists($doc->arquivo), 404);
        $nome = str_replace(['"', "\r", "\n"], '', $doc->nome);

        return $disco->response($doc->arquivo, $nome, [
            'Content-Type' => $modo === 'inline' ? ($doc->mime ?: 'application/octet-stream') : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; object-src 'self'",
        ], $modo);
    }
}
