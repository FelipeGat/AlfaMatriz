<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autoriza por perfil: o recurso vem da rota (ex.: middleware
 * `permissao:clientes`) e a ação é inferida do verbo HTTP — GET lê,
 * POST/PUT/PATCH inclui, DELETE exclui. Também aceita `permissao:clientes,incluir`
 * para fixar a ação.
 *
 * Quem não tem a permissão devolve 403.
 */
class ChecarPermissao
{
    public function handle(Request $request, Closure $next, string $recurso, ?string $acao = null): Response
    {
        $acao ??= match ($request->method()) {
            'GET', 'HEAD' => 'ler',
            'POST', 'PUT', 'PATCH' => 'incluir',
            'DELETE' => 'excluir',
            default => 'ler',
        };

        if (! $request->user()?->canPermissao($recurso, $acao)) {
            abort(403, 'Você não tem permissão para executar esta ação.');
        }

        return $next($request);
    }
}
