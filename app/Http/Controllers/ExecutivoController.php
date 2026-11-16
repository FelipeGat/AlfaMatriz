<?php

namespace App\Http\Controllers;

use App\Services\Executivo\Acompanhamentos;
use App\Services\Executivo\Blocos;
use App\Services\Executivo\Envelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            dados: ['blocos' => array_map(fn ($b) => ['nome' => $b, 'rota' => url('/api/executivo/'.$b)], Blocos::NOMES)],
        ));
    }

    public function bloco(Request $request, Blocos $blocos, string $bloco): JsonResponse
    {
        abort_unless(in_array($bloco, Blocos::NOMES, true), 404);

        if ($bloco === 'acompanhamentos') {
            $parametros = $request->validate([
                'secao' => ['nullable', 'string', 'in:'.implode(',', Acompanhamentos::SECOES)],
                'pagina' => ['nullable', 'integer', 'min:1'],
                'limite' => ['nullable', 'integer', 'min:1', 'max:'.Acompanhamentos::LIMITE_MAXIMO],
            ]);

            return response()->json($blocos->montar($bloco, $request->user(), $parametros));
        }

        $parametros = in_array($bloco, ['agenda', 'financeiro'], true) ? $request->validate([
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:de'],
            'referencia' => $bloco === 'financeiro' ? ['nullable', 'date_format:Y-m-d'] : ['prohibited'],
        ]) : [];

        return response()->json($blocos->montar($bloco, $request->user(), $parametros));
    }
}
