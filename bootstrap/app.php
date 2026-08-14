<?php

use App\Http\Middleware\CabecalhosDeSeguranca;
use App\Http\Middleware\ChecarPermissao;
use App\Http\Middleware\ContaAtiva;
use App\Http\Middleware\PermissoesDaRequisicao;
use App\Http\Middleware\TrocaDeSenhaObrigatoria;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

        // No FIM do grupo `web`, depois de a sessão ter dito quem é a pessoa:
        // é o que garante que o cache de permissão da conta valha por uma
        // requisição e não mais que isso. Ver `PermissoesDaRequisicao`.
        $middleware->appendToGroup('web', PermissoesDaRequisicao::class);

        // Toda tela do painel, autenticada ou não — por isso no grupo `web`
        // e não atrás de `auth`. Ver `CabecalhosDeSeguranca`.
        $middleware->appendToGroup('web', CabecalhosDeSeguranca::class);

        $middleware->alias([
            'permissao' => ChecarPermissao::class,
            'conta-ativa' => ContaAtiva::class,
            'senha-em-dia' => TrocaDeSenhaObrigatoria::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Tela parada tempo demais envelhece a sessão, e com ela o token do
        // formulário: o envio chega com um token que não vale mais e o Laravel
        // devolve a página "419 Page Expired", de onde não se sai — foi preciso
        // fechar a aba para conseguir entrar. Aqui o beco vira uma volta ao
        // login, já com token novo e dizendo o que aconteceu.
        //
        // O gancho é no HttpException, e não no TokenMismatchException: o
        // Laravel troca um pelo outro antes de consultar estes callbacks.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || ! $e->getPrevious() instanceof TokenMismatchException) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Sua sessão expirou. Atualize a página e tente de novo.',
                ], 419);
            }

            return redirect()->route('login')
                ->withInput($request->except('password', '_token'))
                ->withErrors(['sessao' => 'Sua sessão expirou por inatividade. Entre novamente.']);
        });
    })->create();
