<?php

use App\Http\Controllers\CentralIaMaquinasController;
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
        Route::post('execucoes/{execucao}/previa', [CentralIaRunnerController::class, 'previa'])->whereNumber('execucao')->name('central-ia.runner.previa');
        Route::post('execucoes/{execucao}/confirmacao', [CentralIaRunnerController::class, 'confirmacao'])->whereNumber('execucao')->name('central-ia.runner.confirmacao');
        Route::post('execucoes/{execucao}/conclusao', [CentralIaRunnerController::class, 'concluir'])
            ->whereNumber('execucao')->name('central-ia.runner.concluir');
        // Secretário, Etapa 4: a máquina da pessoa transcreve as reuniões dela.
        Route::post('transcricoes/proxima', [\App\Http\Controllers\SecretarioRunnerController::class, 'proxima'])->name('secretario.runner.proxima');
        Route::get('transcricoes/{reuniao}/audio', [\App\Http\Controllers\SecretarioRunnerController::class, 'audio'])->whereNumber('reuniao')->name('secretario.runner.audio');
        Route::post('transcricoes/{reuniao}/progresso', [\App\Http\Controllers\SecretarioRunnerController::class, 'progresso'])->whereNumber('reuniao')->name('secretario.runner.progresso');
        Route::post('transcricoes/{reuniao}', [\App\Http\Controllers\SecretarioRunnerController::class, 'resultado'])->whereNumber('reuniao')->name('secretario.runner.resultado');
        // Etapa 5: o retrato das pendências do Gestor, lido pela máquina (só leitura).
        Route::post('gestor', [\App\Http\Controllers\SecretarioRunnerController::class, 'gestor'])->name('secretario.runner.gestor');
        Route::post('agenda-google', [\App\Http\Controllers\SecretarioRunnerController::class, 'agenda'])->name('secretario.runner.agenda');
        // Pastas de documentos espelhadas do OneDrive (docs/specs/documentos-espelhados).
        Route::post('documentos/inventario', [\App\Http\Controllers\DocumentosRunnerController::class, 'inventario'])->name('documentos.runner.inventario');
        Route::post('documentos/operacoes', [\App\Http\Controllers\DocumentosRunnerController::class, 'operacoes'])->name('documentos.runner.operacoes');
        Route::get('documentos/operacoes/{operacao}/arquivo', [\App\Http\Controllers\DocumentosRunnerController::class, 'arquivoDaOperacao'])->whereNumber('operacao')->name('documentos.runner.operacao-arquivo');
        Route::post('documentos/operacoes/{operacao}', [\App\Http\Controllers\DocumentosRunnerController::class, 'concluirOperacao'])->whereNumber('operacao')->name('documentos.runner.operacao-concluir');
        Route::post('documentos/{pasta}/miniaturas', [\App\Http\Controllers\DocumentosRunnerController::class, 'miniatura'])->whereNumber('pasta')->name('documentos.runner.miniatura');
        Route::post('documentos/{pasta}/partes', [\App\Http\Controllers\DocumentosRunnerController::class, 'parte'])->whereNumber('pasta')->name('documentos.runner.parte');
    });

/*
 * "Conectar minha máquina" (T-374), a metade do terminal. O instalador e os
 * arquivos do runner não têm segredo (são o que está no repositório) e saem
 * sem login: quem cola o comando ainda não tem token. O que dá acesso é o
 * código de uso único, trocado por token em `instalacao` — e essa porta tem
 * teto baixo por endereço, contra quem tentasse adivinhar código.
 */
Route::get('central-ia/instalar.sh', [CentralIaMaquinasController::class, 'instalador'])
    ->middleware('throttle:30,1,central-ia-instalador')->name('central-ia.instalador');
Route::get('central-ia/runner/arquivos/{arquivo}', [CentralIaMaquinasController::class, 'arquivo'])
    // `.php` só com `.txt` no fim: o nginx manda todo endereço terminado em
    // `.php` ao PHP-FPM, e o pedido nem chegaria aqui (404 no instalador).
    ->where('arquivo', '[a-z_-]+\.(mjs|sh|py|php\.txt)')->middleware('throttle:120,1,central-ia-instalador')->name('central-ia.runner.arquivo');
Route::post('api/central-ia/instalacao', [CentralIaMaquinasController::class, 'trocarCodigo'])
    ->middleware('throttle:10,1,central-ia-instalacao')->name('central-ia.instalacao.trocar');
