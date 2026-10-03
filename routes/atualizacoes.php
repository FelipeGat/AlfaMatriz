<?php

use App\Http\Controllers\AtualizacaoController;
use Illuminate\Support\Facades\Route;

/*
 * A porta do `deploy/publicar-changelog.sh` (#225), fora do grupo `web` como
 * o vigia e o webhook do GitHub: quem chama é um script no Mac de quem
 * publica, sem sessão, cookie nem CSRF.
 *
 * O token é PESSOAL (Sanctum, `alfa:changelog-token`), como o do MCP, porque o
 * changelog tem autor — e com a capacidade `changelog` e mais nenhuma: quem o
 * tiver registra changelog em nome de quem o emitiu, e não fala com o quadro.
 * Exposta só onde o app já é exposto: produção e staging atendem pela tailnet.
 *
 * 120 por minuto: a importação dos changelogs antigos manda ~70 de uma vez, e
 * o freio é para um script em loop, não para ela.
 */
Route::post('api/atualizacoes', AtualizacaoController::class)
    ->middleware(['auth:sanctum', 'abilities:changelog', 'throttle:120,1'])
    ->name('atualizacoes.registrar');
