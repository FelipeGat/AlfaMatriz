<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CentralTechnicalCaller
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('central_ia.enabled'), 404);
        abort_unless($request->isSecure(), 403, 'secure_transport_required');
        $file = config('central_ia.key_file');
        abort_unless(is_string($file) && is_readable($file), 503, 'identity_bridge_unavailable');
        $key = trim(file_get_contents($file));
        abort_unless(strlen($key) >= 32, 503, 'identity_bridge_unavailable');
        abort_unless(is_string($request->bearerToken()) && hash_equals($key, $request->bearerToken()), 401, 'technical_auth_required');

        return $next($request);
    }
}
