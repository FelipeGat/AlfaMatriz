<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // O Tailscale Funnel termina o TLS e entrega a requisição em HTTP
        // pela interface local. Sem confiar nesse proxy, o Laravel enxerga
        // "http" e devolve links e cookie de sessão inseguros — o login
        // quebra na URL pública.
        $middleware->trustProxies(at: [
            '127.0.0.1',
            '::1',
        ]);

        $middleware->alias([
            'permissao' => \App\Http\Middleware\ChecarPermissao::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
