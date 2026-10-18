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

    public function pasta(Request $request, int $pasta): View
    {
        $p = $this->visivel($request, $pasta);
        $busca = trim((string) $request->query('q', ''));
        $docs = $p->documentos()
            ->when($busca !== '', fn ($q) => $q->where('caminho', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $busca).'%'))
            ->orderBy('subpasta')->orderBy('nome')->get();

        return view('documentos.pasta', [
            'pasta' => $p, 'busca' => $busca,
            // Uma seção por subpasta de primeiro nível (o cliente); o resto do caminho vai ao lado do nome.
            'grupos' => $docs->groupBy(fn (Documento $d) => explode('/', $d->subpasta)[0]),
            'outras' => DocumentoPasta::visiveisPara($request->user())->whereKeyNot($p->id)->orderBy('nome')->get(['id', 'nome']),
            'pessoas' => $p->ehDono($request->user()) ? User::where('ativo', true)->whereKeyNot($p->user_id)->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
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
