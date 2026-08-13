<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Derruba a sessão de quem foi desativado ENQUANTO estava dentro.
 *
 * A recusa no `LoginRequest` só vale na próxima entrada: sem isto, desativar
 * alguém que já está com o painel aberto não tem efeito nenhum até a sessão
 * dele expirar sozinha — e desativar existe justamente para os casos em que
 * esperar não é opção.
 *
 * Percorre a sessão em vez de apagar linhas da tabela `sessions` de propósito:
 * o `SESSION_DRIVER` é `database` hoje, mas apagar linha deixaria de funcionar
 * em silêncio no dia em que ele não for.
 */
class ContaAtiva
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->ativo) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => 'Esta conta está desativada. Fale com um administrador.']);
        }

        return $next($request);
    }
}
