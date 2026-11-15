<?php

namespace App\Http\Middleware;

use App\Models\Auditoria;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * O rastro de TODA chamada à API Executiva, inclusive as recusadas.
 *
 * Fica antes de `auth:sanctum` e do `throttle` de propósito: quem tenta entrar
 * sem token, com token inventado, com o token do MCP ou além do limite é
 * justamente quem a auditoria existe para mostrar, e uma auditoria que só vê
 * as chamadas que passaram não mostra nada disso. Grava rota, status, IP (a
 * própria tabela cuida dele) e o número do token quando há um — nunca o valor
 * do token nem o conteúdo respondido.
 */
class ExecutivoAuditoria
{
    public function handle(Request $request, Closure $next): Response
    {
        $resposta = $next($request);

        $usuario = $request->user();
        $token = $usuario?->currentAccessToken();

        Auditoria::registrar(
            'executivo',
            $resposta->getStatusCode() < 400 ? 'consultou' : 'recusou',
            null,
            $request->method().' /'.$request->path().' → '.$resposta->getStatusCode()
                .($token && isset($token->id) ? ' (token #'.$token->id.')' : ($usuario ? '' : ' (sem identificação)')),
            null,
            $usuario ?? 'API Executiva (sem identificação)',
        );

        return $resposta;
    }
}
