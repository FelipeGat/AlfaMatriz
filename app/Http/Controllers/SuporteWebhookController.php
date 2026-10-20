<?php

namespace App\Http\Controllers;

use App\Services\Suporte\CanalWhatsApp;
use App\Services\Suporte\Pedro;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A porta da Meta para o número de suporte (docs/specs/suporte-pedro-whatsapp).
 * Fora do grupo `web`, como o webhook do GitHub: a Meta não tem sessão nem CSRF;
 * a assinatura com o app secret é a porta.
 */
class SuporteWebhookController extends Controller
{
    /** O aperto de mão da Meta: devolve o desafio se o verify token bater. O PHP troca "hub.x" por "hub_x". */
    public function verificar(Request $request): Response
    {
        $esperado = (string) config('services.whatsapp_suporte.verify_token');
        abort_unless($esperado !== '' && $request->query('hub_mode') === 'subscribe' && hash_equals($esperado, (string) $request->query('hub_verify_token')), 403);

        return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
    }

    public function receber(Request $request, CanalWhatsApp $canal, Pedro $pedro): Response
    {
        $corpo = $request->getContent();
        abort_unless($canal->assinaturaValida($corpo, $request->header('X-Hub-Signature-256')), 403);
        foreach ($canal->receber((array) json_decode($corpo, true)) as $conversaId) {
            $pedro->enfileirar($conversaId);
        }

        // 200 sempre que a assinatura bate: erro aqui faria a Meta reentregar o mesmo evento por horas.
        return response('', 200);
    }
}
