<?php

namespace App\Providers;

use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Em produção o painel só existe atrás do Funnel, sempre em HTTPS.
        // Forçar o esquema evita conteúdo misto se algum link for gerado
        // antes de o cabeçalho do proxy ser lido.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Quem já está autenticado e abre /login é desviado — e o desvio padrão
        // do Laravel é a rota `dashboard`, que perfil estreito não alcança: o
        // desvio entregava 403. O destino passa a ser a primeira tela que a
        // conta realmente abre, a mesma que o login usa.
        RedirectIfAuthenticated::redirectUsing(
            fn (Request $request) => $request->user()?->telaInicial() ?? route('login', absolute: false)
        );
    }
}
