<?php

use App\Http\Controllers\GitHubWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * O webhook do GitHub (#211), fora do grupo `web` de propósito: sem sessão,
 * cookie nem CSRF, que o GitHub não tem. A assinatura HMAC no controller é a
 * porta, e o `throttle` o freio para quem bater nela sem o segredo.
 *
 * Arquivo próprio, e não uma linha no `web.php`, para a exceção ficar
 * visível: é a única rota feita para a internet chamar sem login.
 */
Route::post('github/webhook', GitHubWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('github.webhook');
