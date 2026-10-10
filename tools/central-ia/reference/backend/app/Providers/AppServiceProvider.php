<?php

namespace App\Providers;

use App\Contracts\IdentityAuthority;
use App\Contracts\IdentityGateway;
use App\Services\Identity\AlfaIdentityAuthority;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(IdentityGateway::class, AlfaIdentityAuthority::class);
        $this->app->bind(IdentityAuthority::class, AlfaIdentityAuthority::class);
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
