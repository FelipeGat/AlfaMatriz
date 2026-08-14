<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emite pelo próprio aplicativo os cabeçalhos de segurança que hoje só o
 * nginx do container põe — e por isso nenhum teste alcança. Fora do nginx
 * (desenvolvimento, ensaio, suíte) a resposta saía sem nenhum deles.
 *
 * A CSP aqui NÃO fecha XSS: `script-src` carrega `'unsafe-inline'` e
 * `'unsafe-eval'` porque o Alpine avalia expressão de `x-data`/`x-on` com
 * `new Function`, e sem os dois o quadro de tarefas para de funcionar
 * (ASM-061). O que ela DE FATO fecha é o destino — carregamento e envio de
 * dado presos a `'self'` — não a execução de script inline injetado.
 * `img-src` inclui `data:` pelas miniaturas de anexo e ícones embutidos.
 *
 * `Strict-Transport-Security` só sai em produção: fora dela (HTTP local, CI)
 * prometer HTTPS pra sempre pelo navegador quebraria o próprio acesso ao
 * ambiente sem certificado.
 */
class CabecalhosDeSeguranca
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "connect-src 'self'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
        ]));

        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');

        if (app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
