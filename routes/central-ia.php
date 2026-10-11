<?php

use App\Http\Controllers\CentralIaRunnerController;
use Illuminate\Support\Facades\Route;

/*
 * A porta do runner da Central de IA, fora do grupo `web` como a do changelog
 * (`routes/atualizacoes.php`): quem chama é um programa na máquina da pessoa,
 * sem sessão nem CSRF. Token Sanctum pessoal de capacidade
 * `central-ia-runner` e mais nenhuma (`alfa:central-ia-token`).
 *
 * O runner pergunta por trabalho a cada poucos segundos; 120/min é folga.
 */
Route::prefix('api/central-ia/runner')
    ->middleware(['auth:sanctum', 'abilities:central-ia-runner', 'throttle:120,1,central-ia-runner'])
    ->group(function () {
        Route::get('anexos/{anexo}', [CentralIaRunnerController::class, 'baixarAnexo'])->whereUuid('anexo')->name('central-ia.runner.anexo');
        Route::post('batida', [CentralIaRunnerController::class, 'batida'])->name('central-ia.runner.batida');
        Route::post('proxima', [CentralIaRunnerController::class, 'proxima'])->name('central-ia.runner.proxima');
        Route::post('execucoes/{execucao}/progresso', [CentralIaRunnerController::class, 'progresso'])->whereNumber('execucao')->name('central-ia.runner.progresso');
        Route::post('execucoes/{execucao}/conclusao', [CentralIaRunnerController::class, 'concluir'])
            ->whereNumber('execucao')->name('central-ia.runner.concluir');
    });
