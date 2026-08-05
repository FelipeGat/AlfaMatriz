<?php

namespace App\Providers;

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
    }
}
