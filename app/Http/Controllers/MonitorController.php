<?php

namespace App\Http\Controllers;

use App\Models\MonitorAlerta;
use App\Models\Sistema;
use App\Services\Monitor\AlertasDoMonitor;
use App\Services\Monitor\ApiDoMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * A porta do AlfaMonitor (#394): `POST /api/monitor/eventos`.
 *
 * Quem chama é o webhook do monitor, um alerta por chamada. Fora do grupo
 * `web` (`routes/monitor.php`), como o vigia. Quem prova a origem é o
 * cabeçalho `X-AlfaMonitor-Token` (`<id do sistema>|<segredo>`, emitido por
 * `alfa:monitor-token`) — o nome do cabeçalho é o que o monitor já manda.
 *
 * As respostas seguem o que o monitor faz com elas: tudo que não é 2xx entra
 * nas novas tentativas (30 s … 6 h). Por isso o que NUNCA vai passar — kind
 * fora do escopo, versão de contrato que este código não conhece — volta 202
 * e é descartado: um erro faria o monitor insistir por 8 horas à toa. O 422
 * fica para o corpo quebrado, que é defeito a consertar e aparece como
 * "falhou" no painel do monitor.
 */
class MonitorController extends Controller
{
    public const LIMITE_DO_CORPO = 64 * 1024;

    public const VERSAO_DO_CONTRATO = 1;

    public function __invoke(Request $request, AlertasDoMonitor $alertas, ApiDoMonitor $api): JsonResponse
    {
        if (! $this->tokenValido((string) $request->header('X-AlfaMonitor-Token'))) {
            return response()->json(['message' => 'Token do AlfaMonitor inválido.'], 401);
        }

        if (strlen($request->getContent()) > self::LIMITE_DO_CORPO) {
            return response()->json(['message' => 'Evento grande demais: o limite é 64 KB.'], 413);
        }

        $evento = json_decode($request->getContent(), true, 16, JSON_INVALID_UTF8_SUBSTITUTE);

        if (! is_array($evento)) {
            return response()->json(['message' => 'Corpo ilegível: esperava JSON.'], 400);
        }

        if (($evento['v'] ?? null) !== self::VERSAO_DO_CONTRATO) {
            // Sem `v` é o monitor de antes dos campos novos: sem `event_id`,
            // não há como saber se é repetido. Com outro `v`, o contrato mudou
            // antes deste código — o log diz, e o monitor não fica tentando.
            Log::warning('AlfaMonitor: evento descartado por versão de contrato desconhecida.', [
                'v' => $evento['v'] ?? null,
                'kind' => $evento['kind'] ?? null,
                'entity' => $evento['entity'] ?? null,
            ]);

            return response()->json(['message' => 'ignorado', 'motivo' => 'versao'], 202);
        }

        if (! array_key_exists($evento['kind'] ?? null, MonitorAlerta::KINDS)) {
            return response()->json(['message' => 'ignorado', 'motivo' => 'kind'], 202);
        }

        $validacao = Validator::make($evento, AlertasDoMonitor::regras());

        if ($validacao->fails()) {
            return response()->json(['message' => 'Evento inválido.', 'errors' => $validacao->errors()], 422);
        }

        $resultado = $alertas->receber($validacao->validated());

        // O alerta mudou o que as telas do Monitor mostram (Atenção, servidor,
        // incidentes): a próxima atualização delas vai ao monitor em vez de
        // servir o cache de até 30 s.
        $api->invalidar();

        return response()->json(['message' => $resultado]);
    }

    /**
     * Mesma conferência do vigia (`VigiaController::sistemaDoToken`): o id
     * acha a linha, o segredo é comparado pelo hash, em tempo constante.
     */
    private function tokenValido(string $token): bool
    {
        if (! preg_match('/^(\d+)\|(.+)$/', $token, $partes)) {
            return false;
        }

        $sistema = Sistema::find((int) $partes[1]);

        if (! $sistema || ! $sistema->ativo || blank($sistema->monitor_token_hash)) {
            return false;
        }

        return hash_equals($sistema->monitor_token_hash, hash('sha256', $partes[2]));
    }
}
