<?php

use App\Http\Controllers\SuporteWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * O webhook do número de suporte na WhatsApp Cloud API (docs/specs/suporte-pedro-whatsapp),
 * fora do grupo `web` pelo mesmo motivo do GitHub: a Meta chama sem login; a
 * assinatura no controller é a porta e o `throttle`, o freio.
 */
Route::get('suporte/whatsapp/webhook', [SuporteWebhookController::class, 'verificar'])->middleware('throttle:60,1')->name('suporte.webhook.verificar');
Route::post('suporte/whatsapp/webhook', [SuporteWebhookController::class, 'receber'])->middleware('throttle:600,1')->name('suporte.webhook');
