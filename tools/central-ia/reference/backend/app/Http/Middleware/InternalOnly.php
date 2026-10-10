<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class InternalOnly
{
    public function handle(Request $request, Closure $next)
    {
        if (config('central.internal_only') && ! $request->is('up', 'internal/alfa/session', 'internal/alfa/history', 'internal/alfa/send')) abort(404);
        return $next($request);
    }
}
