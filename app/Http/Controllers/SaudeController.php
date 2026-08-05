<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Checagem de saúde usada pelo script de conferência pós-deploy.
 *
 * Fica fora do login de propósito: precisa responder antes de existir sessão.
 * Por isso não devolve versão, caminho de arquivo, nem nada do negócio — só
 * "de pé" ou "com problema".
 */
class SaudeController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $bancoOk = true;

        try {
            DB::connection()->getPdo();
            DB::select('select 1');
        } catch (\Throwable) {
            // A mensagem da exceção pode conter host, usuário e caminho —
            // nada disso sai numa rota pública.
            $bancoOk = false;
        }

        return response()->json([
            'app' => 'ok',
            'banco' => $bancoOk ? 'ok' : 'erro',
        ], $bancoOk ? 200 : 503);
    }
}
