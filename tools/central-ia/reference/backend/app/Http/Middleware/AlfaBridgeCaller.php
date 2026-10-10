<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AlfaBridgeCaller
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('central.alfa_backend_enabled'), 404);
        abort_unless($request->isSecure(), 403, 'secure_transport_required');
        $file = config('central.alfa_backend_key_file');
        abort_unless(is_string($file) && is_readable($file), 503, 'bridge_configuration_unavailable');
        $key = trim(file_get_contents($file));
        abort_unless(strlen($key) >= 32, 503, 'bridge_configuration_unavailable');
        abort_unless(is_string($request->bearerToken()) && hash_equals($key, $request->bearerToken()), 401);

        return $next($request);
    }
}
