<?php

use App\Http\Controllers\MonitorController;
use Illuminate\Support\Facades\Route;

/*
 * A porta do AlfaMonitor (#394), fora do grupo `web` como o vigia e o webhook
 * do GitHub: quem chama é o webhook do monitor, sem sessão, cookie nem CSRF.
 * A porta é o token (`alfa:monitor-token`), conferido no controller; o
 * `throttle` é o freio. Quando muitos monitores caem juntos o próprio monitor
 * segura o alerta, então 120 por minuto é folga — e o 429 só adia: o monitor
 * tenta de novo.
 */
Route::post('api/monitor/eventos', MonitorController::class)
    ->middleware('throttle:120,1')
    ->name('monitor.eventos');
