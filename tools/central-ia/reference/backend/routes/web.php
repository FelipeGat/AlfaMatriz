<?php

use App\Http\Controllers\AlfaBridgeController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\IdentitySessionController;
use App\Http\Controllers\PairingConfirmationController;
use App\Http\Controllers\PrivateResourcesController;
use App\Http\Controllers\TelegramPairingController;
use App\Http\Middleware\AlfaBridgeCaller;
use App\Http\Middleware\IdentityRequestScope;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::post('/identity/start', [IdentitySessionController::class, 'start'])->middleware('throttle:10,1');
    Route::post('/identity/complete', [IdentitySessionController::class, 'complete'])->middleware('throttle:10,1');
    Route::get('/login', [AuthController::class, 'form'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1,central-login:');
});
Route::middleware(['auth', IdentityRequestScope::class])->group(function () {
    Route::get('/telegram/pair/pending', [PairingConfirmationController::class, 'pending'])->middleware('central.identity:pair');
    Route::post('/telegram/pair/confirm', [PairingConfirmationController::class, 'confirm'])->middleware(['central.identity:pair', 'throttle:5,1']);
    Route::get('/messages/{message}', [PrivateResourcesController::class, 'message'])->middleware('central.identity:read');
    Route::get('/agent-runs/{run}', [PrivateResourcesController::class, 'run'])->middleware('central.identity:read');
    Route::post('/messages/{message}/run', [PrivateResourcesController::class, 'execute'])->middleware('central.identity:execute');
    Route::get('/integrations', [PrivateResourcesController::class, 'integrations'])->middleware('central.identity:integrations');
    Route::delete('/integrations/{integration}', [PrivateResourcesController::class, 'revokeIntegration'])->middleware('central.identity:integrations');
    Route::delete('/sessions/{session}', [PrivateResourcesController::class, 'revokeSession'])->middleware('central.identity:sessions');
    Route::delete('/telegram/pair', [PrivateResourcesController::class, 'revokeTelegram'])->middleware('central.identity:pair');

    Route::get('/', [ConversationController::class, 'index'])->middleware('central.identity:read');
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/messages', [ConversationController::class, 'messages'])->middleware(['central.identity:read', 'throttle:120,1,central-read:']);
    Route::post('/messages', [ConversationController::class, 'store'])->middleware(['central.identity:send', 'throttle:30,1,central-send:']);
    Route::post('/telegram/pair', [TelegramPairingController::class, 'store'])->middleware(['central.identity:pair', 'throttle:5,1,central-pair:']);
});

Route::prefix('internal/alfa')->middleware([AlfaBridgeCaller::class, IdentityRequestScope::class, 'throttle:180,1'])->group(function () {
    Route::post('/session', [AlfaBridgeController::class, 'session']);
    Route::post('/history', [AlfaBridgeController::class, 'history']);
    Route::post('/send', [AlfaBridgeController::class, 'send']);
});
