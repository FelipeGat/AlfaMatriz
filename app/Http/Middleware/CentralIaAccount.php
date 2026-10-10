<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CentralIaAccount
{
    public function handle(Request $request, Closure $next)
    {
        // Both the stable owner identity and native permissions are required, even for admins.
        $user = $request->user()?->fresh();
        if (! app(\App\Services\CentralOwnerPolicy::class)->allows($user) || ! $user || ! $user->ativo || $user->primeiro_acesso) {
            $request->session()->forget('central_ia.backend');

            if (! $request->expectsJson()) abort(403, 'access_denied');
            return response()->json(['message' => 'Sua conta precisa de autorização para continuar.'], 403)->header('Cache-Control', 'no-store, private');
        }

        return $next($request);
    }
}
