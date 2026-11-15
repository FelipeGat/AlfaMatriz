<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Primeiro da fila: sem ele, uma chamada sem `Accept: application/json` e sem
 * token recebe um redirecionamento ao login em vez do 401 — e quem consome esta
 * API é um programa, que não segue tela de login.
 */
class ExecutivoSoJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
