<?php

namespace App\Http\Middleware;

use App\Mcp\Servers\AlfaMatrizServer;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Depois do token: a conta certa, e o rastro de cada chamada.
 *
 * O token Sanctum só diz DE QUEM é; aqui se confere que é da conta que a API
 * Executiva serve (`services.executivo.conta`), com as mesmas portas da tela
 * (conta desativada, revenda e VixCard não entram). Um token emitido por engano
 * para outra pessoa, ou uma conta que perdeu o acesso depois, para de valer na
 * hora, sem esperar os 30 dias.
 *
 * Nenhuma rota recebe `user_id` nem `usuario`: o escopo é o dono do token. Quem
 * manda esses campos está tentando escolher de quem são os dados, e a resposta
 * é recusar, não ignorar em silêncio.
 *
 * A auditoria é do `ExecutivoAuditoria`, que fica antes de tudo para ver
 * também as recusas.
 */
class ExecutivoDaConta
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        try {
            if (! $usuario || strcasecmp((string) $usuario->email, (string) config('services.executivo.conta')) !== 0) {
                throw new RuntimeException('Esta conta não tem acesso à API Executiva.');
            }

            AlfaMatrizServer::conferir($usuario);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        if ($request->hasAny(['user_id', 'usuario', 'usuario_id', 'user'])) {
            return response()->json([
                'message' => 'Esta API não aceita escolher a conta: ela serve só a conta do dono do token.',
            ], 422);
        }

        return $next($request);
    }
}
