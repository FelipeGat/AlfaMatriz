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

    /**
     * O repasse do Gestor, dono do 4042-4157 (Gestor: docs/specs/whatsapp-pedro):
     * o corpo cru da Meta com a assinatura ORIGINAL (conferida com o app secret
     * do número) e o segredo da integração, que também garante o X-Gestor-Estado
     * — a Meta não assina esse cabeçalho. O Pedro só fala onde o Gestor diz que
     * a conversa é nossa.
     */
    public function repasse(Request $request, CanalWhatsApp $canal, Pedro $pedro): Response
    {
        abort_unless($canal->doGestor($request->bearerToken()), 401);
        $corpo = $request->getContent();
        abort_unless($canal->assinaturaValida($corpo, $request->header('X-Hub-Signature-256')), 403);
        // Um estado por contato (wa_id): o mesmo evento pode juntar contatos. Sem o
        // estado de um contato, a mensagem só fica registrada: quem decide se a
        // conversa é do Pedro é o Gestor.
        $estados = json_decode((string) $request->header('X-Gestor-Estado'), true);
        foreach ($canal->receber((array) json_decode($corpo, true)) as $conversaId) {
            if (is_array($estados) && $canal->aplicarEstadoDoGestor($conversaId, $estados)) {
                $pedro->enfileirar($conversaId);
            }
        }

        return response('', 200);
    }
}
