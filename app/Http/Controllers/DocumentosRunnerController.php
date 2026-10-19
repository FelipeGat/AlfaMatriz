<?php

namespace App\Http\Controllers;

use App\Models\CentralIaRunner;
use App\Models\Documento;
use App\Models\DocumentoOperacao;
use App\Models\DocumentoPasta;
use App\Models\User;
use App\Services\Documentos\Alteracoes;
use App\Services\Documentos\Espelho;
use App\Services\Documentos\Miniaturas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * A porta da máquina para o espelho das pastas (docs/specs/documentos-espelhados):
 * só a máquina da própria conta, e só nas pastas dela (FR-002).
 */
class DocumentosRunnerController extends Controller
{
    public function __construct(private Espelho $espelho) {}

    public function inventario(Request $request): JsonResponse
    {
        [$user, $maquina] = $this->conta($request);
        $d = $request->validate([
            'pasta' => ['required', 'string', 'max:120', 'regex:/^[^\/\\\\\x00-\x1F]+$/u'],
            'arquivos' => ['present', 'array', 'max:20000'],
            'arquivos.*.caminho' => ['required', 'string', 'max:600'],
            'arquivos.*.tamanho' => ['required', 'integer', 'min:0'],
            'arquivos.*.modificado' => ['required', 'string', 'max:40'],
            'pastas' => ['nullable', 'array', 'max:20000'],
            'pastas.*' => ['string', 'max:600'],
        ]);
        $r = $this->espelho->inventario($user, $maquina, trim($d['pasta']), $d['arquivos'], $d['pastas'] ?? null);

        return response()->json(['pasta_id' => $r['pasta']->id, 'enviar' => $r['enviar'], 'removidos' => $r['removidos'], 'parte_bytes' => Espelho::PARTE_BYTES,
            'miniaturas' => $r['miniaturas']]);
    }

    public function parte(Request $request, int $pasta): JsonResponse
    {
        [$user] = $this->conta($request);
        $p = DocumentoPasta::where('user_id', $user->id)->findOrFail($pasta);
        $d = $request->validate([
            'caminho' => ['required', 'string', 'max:600'],
            'tamanho' => ['required', 'integer', 'min:0'],
            'modificado' => ['required', 'string', 'max:40'],
            'sha256' => ['required', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
            'parte' => ['required', 'integer', 'min:0', 'max:100'],
            'partes' => ['required', 'integer', 'min:1', 'max:100'],
            'arquivo' => ['required', 'file', 'max:'.(int) (Espelho::PARTE_BYTES / 1024 + 1)],
        ]);
        $doc = $this->espelho->receberParte($p, $d, $request->file('arquivo'));

        return response()->json(['ok' => true, 'completo' => $doc !== null, 'documento' => $doc?->id]);
    }

    /** A miniatura de PDF ou vídeo que a máquina gerou (ou o aviso de que não deu). */
    public function miniatura(Request $request, int $pasta, Miniaturas $miniaturas): JsonResponse
    {
        [$user] = $this->conta($request);
        $p = DocumentoPasta::where('user_id', $user->id)->findOrFail($pasta);
        $d = $request->validate([
            'caminho' => ['required', 'string', 'max:600'],
            'sha256' => ['required', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
            'arquivo' => ['nullable', 'file', 'max:2048'],
        ]);
        $doc = $p->documentos()->where('caminho_hash', sha1((string) Espelho::caminhoSeguro($d['caminho'])))->where('sha256', strtolower($d['sha256']))->firstOrFail();
        $ok = $miniaturas->receber($doc, $request->hasFile('arquivo') ? (string) file_get_contents($request->file('arquivo')->getRealPath()) : null);

        return response()->json(['ok' => $ok]);
    }

    /** As alterações pedidas pelo Matriz que esta máquina aplica no OneDrive (documentos-mao-dupla). */
    public function operacoes(Request $request, Alteracoes $alteracoes): JsonResponse
    {
        [$user] = $this->conta($request);

        return response()->json(['operacoes' => $alteracoes->pendentes($user)->map(fn (DocumentoOperacao $o) => [
            'id' => $o->id, 'pasta' => $o->pasta->nome, 'tipo' => $o->tipo, 'caminho' => $o->caminho, 'destino' => $o->destino,
            'tamanho' => $o->tamanho, 'sha256' => $o->sha256,
        ])->values()]);
    }

    public function arquivoDaOperacao(Request $request, int $operacao): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $op = $this->operacao($request, $operacao);
        abort_unless($op->tipo === 'enviar' && $op->arquivo && Storage::disk(Documento::DISCO)->exists($op->arquivo), 404);

        return Storage::disk(Documento::DISCO)->download($op->arquivo, 'arquivo.bin', ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store, private']);
    }

    public function concluirOperacao(Request $request, int $operacao, Alteracoes $alteracoes): JsonResponse
    {
        $op = $this->operacao($request, $operacao);
        $d = $request->validate([
            'situacao' => ['required', 'in:feita,falhou'],
            'erro' => ['nullable', 'string', 'max:300'],
            'caminho_final' => ['nullable', 'string', 'max:600'],
            'modificado' => ['nullable', 'string', 'max:40'],
        ]);
        $alteracoes->concluir($op, $d);

        return response()->json(['ok' => true]);
    }

    private function operacao(Request $request, int $id): DocumentoOperacao
    {
        [$user] = $this->conta($request);
        $op = DocumentoOperacao::with('pasta')->findOrFail($id);
        abort_unless($op->pasta->user_id === $user->id && $op->situacao === 'pendente', 404);

        return $op;
    }

    /** @return array{0: User, 1: string} */
    private function conta(Request $request): array
    {
        $user = $request->user();
        abort_unless($user->ativo && ! $user->temEscopoDeRevenda(), 403);
        $maquina = (string) $request->input('maquina', '');
        abort_unless(preg_match('/^[A-Za-z0-9._-]{1,64}$/', $maquina) && CentralIaRunner::where('user_id', $user->id)->where('maquina', $maquina)->exists(), 404);

        return [$user, $maquina];
    }
}
