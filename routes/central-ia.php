<?php

use App\Http\Controllers\CentralIaController;
use App\Http\Controllers\CentralIdentityController;
use App\Http\Middleware\CentralIaAccount;
use App\Http\Middleware\CentralTechnicalCaller;
use Illuminate\Support\Facades\Route;

Route::post('/central-ia/tickets', [CentralIdentityController::class, 'issue'])
    ->middleware(['auth', CentralIaAccount::class, 'conta-ativa', 'senha-em-dia', 'permissao:central_ia,ler', 'throttle:10,1']);

Route::middleware([CentralTechnicalCaller::class, 'throttle:120,1'])->prefix('internal/central-ia')->group(function () {
    Route::post('/inspect', [CentralIdentityController::class, 'inspect']);
    Route::post('/redeem', [CentralIdentityController::class, 'redeem']);
});

Route::middleware(['auth', CentralIaAccount::class, 'conta-ativa', 'senha-em-dia', 'permissao:central_ia,ler'])->group(function () {
    Route::get('/central-ia', [CentralIaController::class, 'index'])->name('central-ia.index');
    Route::get('/central-ia/messages', [CentralIaController::class, 'messages'])->middleware('throttle:60,1')->name('central-ia.messages');
    Route::post('/central-ia/messages', [CentralIaController::class, 'send'])->middleware(['permissao:central_ia,incluir', 'throttle:20,1'])->name('central-ia.send');
});
