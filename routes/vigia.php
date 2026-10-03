<?php

use App\Http\Controllers\VigiaController;
use Illuminate\Support\Facades\Route;

/*
 * A porta do vigia de logs (#219), fora do grupo `web` como o webhook do
 * GitHub (`routes/github.php`): quem chama é um script no cron de outro
 * servidor, sem sessão, cookie nem CSRF. A porta é o token do sistema
 * (`alfa:vigia-token`), conferido no controller; o `throttle` é o freio para
 * quem bater nela sem ele. Um servidor manda um lote por hora e por fonte —
 * 60 por minuto é folga de sobra para o legítimo.
 */
Route::post('api/vigia/erros', VigiaController::class)
    ->middleware('throttle:60,1')
    ->name('vigia.erros');
