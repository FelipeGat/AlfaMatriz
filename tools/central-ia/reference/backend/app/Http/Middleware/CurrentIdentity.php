<?php

namespace App\Http\Middleware;

use App\Services\Identity\Access;
use Closure;
use Illuminate\Http\Request;

class CurrentIdentity
{
    public function handle(Request $request, Closure $next, string $operation)
    {
        $epoch = $request->session()->get('central.identity_epoch');
        abort_unless(is_int($epoch), 403, 'access_denied');
        app(Access::class)->authorize($request->user(), $operation, $epoch);

        return $next($request);
    }
}
