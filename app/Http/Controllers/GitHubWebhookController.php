<?php

namespace App\Http\Controllers;

use App\Services\ReferenciasDoGitHub;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * A porta do GitHub para o quadro (#211): `POST /github/webhook`.
 *
 * É a ÚNICA rota do sistema feita para ser chamada de fora sem login, e por
 * isso fica fora do grupo `web` (`routes/github.php`): sem sessão, sem cookie
 * e sem CSRF — o GitHub não tem nenhum dos três. Quem prova a origem é a
 * assinatura `X-Hub-Signature-256`, um HMAC SHA-256 do corpo com o segredo
 * que só o GitHub e o `.env` conhecem (`GITHUB_WEBHOOK_SECRET`).
 *
 * Desenhada para ser a única coisa exposta quando o dono decidir abrir a porta
 * (hoje produção e staging só atendem pela tailnet, e o GitHub não chega lá):
 * um túnel ou proxy pode publicar só este caminho, e nada nele depende de o
 * resto do painel estar alcançável.
 */
class GitHubWebhookController extends Controller
{
    public function __invoke(Request $request, ReferenciasDoGitHub $referencias): JsonResponse
    {
        if (! $this->assinaturaConfere($request)) {
            return response()->json(['message' => 'Assinatura inválida.'], 403);
        }

        $evento = (string) $request->header('X-GitHub-Event', '');

        if ($evento === 'ping') {
            return response()->json(['message' => 'pong']);
        }

        $carga = $this->carga($request);

        if ($carga === null) {
            return response()->json(['message' => 'Corpo ilegível.'], 400);
        }

        $mexidas = $referencias->tratar($evento, $carga);

        // O id da entrega no log: é o que o GitHub mostra na tela de
        // reenvio, e é a única ponte entre "não apareceu no quadro" e o que
        // de fato chegou aqui.
        if ($mexidas > 0) {
            Log::info('GitHub ligou referências ao quadro', [
                'evento' => $evento,
                'entrega' => $request->header('X-GitHub-Delivery'),
                'referencias' => $mexidas,
            ]);
        }

        return response()->json(['message' => 'ok', 'referencias' => $mexidas]);
    }

    /**
     * O corpo confere com a assinatura e com o segredo daqui?
     *
     * Sem segredo configurado, recusa TUDO: aceitar sem conferir seria deixar
     * qualquer um na internet escrever no quadro, e um `.env` esquecido não
     * pode ser o que abre a porta. A comparação é `hash_equals` — em tempo
     * constante, para o tempo de resposta não ir soprando o HMAC certo.
     */
    private function assinaturaConfere(Request $request): bool
    {
        $segredo = (string) config('services.github.webhook_secret');
        $assinatura = (string) $request->header('X-Hub-Signature-256', '');

        if ($segredo === '' || $assinatura === '') {
            return false;
        }

        $esperada = 'sha256='.hash_hmac('sha256', $request->getContent(), $segredo);

        return hash_equals($esperada, $assinatura);
    }

    /**
     * O payload, nos dois formatos que o GitHub oferece ao configurar o
     * webhook: JSON puro ou formulário com o JSON em `payload`. Quem
     * configurar o repositório escolhendo o padrão errado não deveria
     * descobrir só pelo quadro mudo.
     */
    private function carga(Request $request): ?array
    {
        $bruto = str_contains((string) $request->header('Content-Type'), 'application/x-www-form-urlencoded')
            ? (string) $request->input('payload', '')
            : $request->getContent();

        $carga = json_decode($bruto, true);

        return is_array($carga) ? $carga : null;
    }
}
