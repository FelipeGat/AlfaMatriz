<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class IdentityRequestScope
{
    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set('central.identity_request_scope', true);
        try {
            return $next($request);
        } finally {
            $request->attributes->remove('central.identity_request_scope');
            $request->attributes->remove('central.identity_request_snapshots');
        }
    }
}
