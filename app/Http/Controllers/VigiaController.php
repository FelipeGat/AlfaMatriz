<?php

namespace App\Http\Controllers;

use App\Models\Sistema;
use App\Services\Vigia\VigiaDeLogs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * A porta do vigia de logs (#219): `POST /api/vigia/erros`.
 *
 * Quem chama é o `deploy/vigia-logs/enviar-erros.sh`, no cron do servidor de
 * cada sistema. Fora do grupo `web` (`routes/vigia.php`), como o webhook do
 * GitHub: sem sessão nem CSRF. Quem prova a origem é o token do SISTEMA —
 * `Authorization: Bearer <id>|<segredo>`, emitido por `alfa:vigia-token` —,
 * e é ele que diz de qual sistema é o erro: o corpo não escolhe o sistema, e
 * um servidor do AlfaGym não consegue abrir bug no AlfaControl.
 *
 * Os limites: o corpo inteiro até 4 MB e o lote até 500 erros — acima disso,
 * 413, e o script divide. Campo comprido demais é CORTADO, e não recusado
 * (`VigiaDeLogs::cortar`): um stack de 300 linhas não pode fazer o erro sumir.
 */
class VigiaController extends Controller
{
    public const LIMITE_DO_CORPO = 4 * 1024 * 1024;

    public function __invoke(Request $request, VigiaDeLogs $vigia): JsonResponse
    {
        $sistema = $this->sistemaDoToken($request);

        if (! $sistema) {
            return response()->json(['message' => 'Token do vigia inválido.'], 401);
        }

        if (strlen($request->getContent()) > self::LIMITE_DO_CORPO) {
            return response()->json(['message' => 'Lote grande demais: o limite é 4 MB. Divida o envio.'], 413);
        }

        // Decodificado aqui, e não pelo `$request->json()`: log carrega byte
        // que não é UTF-8 (nome de arquivo, payload binário), e o
        // `json_decode` padrão recusaria o lote INTEIRO por um caractere.
        $carga = json_decode($request->getContent(), true, 64, JSON_INVALID_UTF8_SUBSTITUTE);

        if (! is_array($carga)) {
            return response()->json(['message' => 'Corpo ilegível: esperava JSON.'], 400);
        }

        if (is_array($carga['erros'] ?? null) && count($carga['erros']) > VigiaDeLogs::MAX_ERROS_POR_LOTE) {
            return response()->json([
                'message' => 'Lote grande demais: o limite é '.VigiaDeLogs::MAX_ERROS_POR_LOTE.' erros por envio. Divida o envio.',
            ], 413);
        }

        $validacao = Validator::make($carga, [
            'ambiente' => 'required|in:'.implode(',', array_keys(VigiaDeLogs::AMBIENTES)),
            'origem' => 'nullable|string|max:150',
            'erros' => 'required|array|min:1',
            'erros.*' => 'required|array',
            'erros.*.quando' => 'nullable|string|max:64',
            'erros.*.nivel' => 'nullable|string',
            'erros.*.mensagem' => 'required|string',
            'erros.*.excecao' => 'nullable|string',
            'erros.*.trecho' => 'nullable|string',
        ]);

        if ($validacao->fails()) {
            return response()->json(['message' => 'Lote inválido.', 'errors' => $validacao->errors()], 422);
        }

        $resumo = $vigia->receber($sistema, $carga['ambiente'], $carga['origem'] ?? null, array_values($carga['erros']));

        return response()->json(['message' => 'ok'] + $resumo);
    }

    /**
     * O sistema dono do token — ou nulo.
     *
     * O token tem a forma `<id do sistema>|<segredo>`, como o do Sanctum: o id
     * acha a linha sem varrer a tabela, e o segredo é comparado pelo HASH, com
     * `hash_equals`, em tempo constante — o tempo da resposta não vai soprando
     * quantos caracteres acertaram. Sistema desativado não manda erro.
     */
    private function sistemaDoToken(Request $request): ?Sistema
    {
        $token = (string) $request->bearerToken();

        if (! preg_match('/^(\d+)\|(.+)$/', $token, $partes)) {
            return null;
        }

        $sistema = Sistema::find((int) $partes[1]);

        if (! $sistema || ! $sistema->ativo || blank($sistema->vigia_token_hash)) {
            return null;
        }

        return hash_equals($sistema->vigia_token_hash, hash('sha256', $partes[2])) ? $sistema : null;
    }
}
