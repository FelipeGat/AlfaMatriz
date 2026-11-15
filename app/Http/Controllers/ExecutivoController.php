<?php

namespace App\Http\Controllers;

use App\Services\Executivo\Envelope;
use Illuminate\Http\JsonResponse;

/**
 * API Executiva: só leitura. A regra de cada bloco mora no serviço que a tela
 * já usa; aqui só se valida o pedido e se entrega o envelope.
 */
class ExecutivoController extends Controller
{
    /** O índice: quais blocos existem. */
    public function indice(): JsonResponse
    {
        return response()->json(Envelope::montar(
            fonte: 'AlfaMatriz',
            periodo: null,
            dados: ['blocos' => []],
        ));
    }

}
