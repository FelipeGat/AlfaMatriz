<?php

namespace App\Http\Controllers;

use App\Models\CentralIaRunner;
use App\Models\DocumentoPasta;
use App\Models\User;
use App\Services\Documentos\Espelho;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        ]);
        $r = $this->espelho->inventario($user, $maquina, trim($d['pasta']), $d['arquivos']);

        return response()->json(['pasta_id' => $r['pasta']->id, 'enviar' => $r['enviar'], 'removidos' => $r['removidos'], 'parte_bytes' => Espelho::PARTE_BYTES]);
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
