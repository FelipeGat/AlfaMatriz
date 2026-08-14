<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

// O cadastro público não é roteado: o painel fica numa URL pública e ninguém
// pode criar a própria conta. Contas novas saem de `php artisan alfa:criar-usuario`.
//
// A recuperação de senha por e-mail também não é roteada, de propósito: em
// produção o `MAIL_MAILER` é `log` e o `LOG_LEVEL` é `warning`, então o
// e-mail nunca sai — quem pedisse o link ficaria esperando para sempre, e se
// um dia alguém baixasse o nível do log para depurar outra coisa, cada
// pedido passaria a gravar um link de redefinição válido, por uma hora, num
// arquivo de texto. Quem esquece a senha depende de outra pessoa: o admin
// repõe pela tela de usuários e marca `primeiro_acesso`, e a própria conta
// troca a senha no próximo login.
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
