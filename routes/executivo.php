<?php

use App\Http\Controllers\ExecutivoController;
use Illuminate\Support\Facades\Route;

/*
 * A API Executiva da Matriz (spec `api-executiva-leitura`): o que o ChatGPT do
 * Felipe consulta, e NADA além de consultar.
 *
 * Só GET, de propósito: rota de escrita aqui seria a mesma porta do `/mcp` com
 * outro nome, e é exatamente isso que esta API existe para não ser. Um verbo
 * que não está registrado devolve 405 sozinho. O token é próprio
 * (`alfa:executivo-token`), com a capacidade `executivo:ler` e nenhuma outra —
 * o do MCP (`mcp`) não abre esta porta e este não abre aquela. Fora do grupo
 * `web`: sem sessão nem CSRF. A conta vem do dono do token e é conferida contra
 * `services.executivo.conta`; nenhuma rota aceita `user_id`.
 *
 * Exposta só onde o app já é: pela tailnet. Abrir para a internet é decisão do
 * dono e está fora desta etapa.
 */
Route::prefix('api/executivo')
    ->middleware(['executivo.auditoria', 'executivo.json', 'throttle:30,1', 'auth:sanctum', 'abilities:executivo:ler', 'executivo.conta'])
    ->group(function () {
        Route::get('/', [ExecutivoController::class, 'indice'])->name('executivo.indice');
        Route::get('/{bloco}', [ExecutivoController::class, 'bloco'])->where('bloco', '[a-z]+')->name('executivo.bloco');

        // Escrita não existe aqui. Esta rota só recusa — e existe para a tentativa
        // passar pela fila (auditoria, token, conta) em vez de morrer num 405 do
        // roteador, que nunca chega a um middleware e não deixa rastro.
        Route::match(['POST', 'PUT', 'PATCH', 'DELETE'], '/{qualquer?}', fn () => abort(405, 'Esta API é só de leitura.', ['Allow' => 'GET, HEAD']))
            ->where('qualquer', '.*');
    });
