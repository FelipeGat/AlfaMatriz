<?php

namespace App\Http\Controllers;

use App\Models\Documento;
use App\Models\DocumentoPasta;
use App\Models\User;
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
        if ($nivel !== '' && ! $todos->contains(fn (Documento $d) => $d->subpasta === $nivel || str_starts_with($d->subpasta, $nivel.'/'))) {
            $nivel = '';
        }

        if ($busca !== '') {
            $achados = $todos->filter(fn (Documento $d) => mb_stripos($d->caminho, $busca) !== false)->values();

            return view('documentos.pasta', $this->comuns($request, $p) + ['busca' => $busca, 'nivel' => '', 'trilha' => [], 'cards' => collect(), 'arquivos' => $achados]);
        }

        $abaixo = $todos->filter(fn (Documento $d) => $nivel === '' || $d->subpasta === $nivel || str_starts_with($d->subpasta, $nivel.'/'));
        $resto = fn (Documento $d) => ltrim(substr($d->subpasta, strlen($nivel)), '/');
        $cards = $abaixo->filter(fn ($d) => $resto($d) !== '')
            ->groupBy(fn ($d) => explode('/', $resto($d))[0])
            ->map(function ($docs, $nome) use ($nivel, $resto) {
                $sub = $docs->map(fn ($d) => explode('/', $resto($d))[1] ?? null)->filter()->unique()->values();

                return [
                    'nome' => $nome,
                    'caminho' => ltrim($nivel.'/'.$nome, '/'),
                    'arquivos' => $docs->count(),
                    'bytes' => (int) $docs->sum('tamanho'),
                    'tipos' => $docs->countBy(fn (Documento $d) => $d->tipo())->sortDesc()->all(),
                    'subpastas' => $sub,
                    'exemplos' => $sub->isNotEmpty() ? $sub->take(4)->all() : $docs->sortByDesc('modificado_em')->take(3)->pluck('nome')->all(),
                    'atualizado' => $docs->max('modificado_em'),
                ];
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

    /** O que a tela precisa em qualquer modo. */
    private function comuns(Request $request, DocumentoPasta $p): array
    {
        return [
            'pasta' => $p,
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
