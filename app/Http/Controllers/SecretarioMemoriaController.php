<?php

namespace App\Http\Controllers;

use App\Services\Secretario\Memoria\BuscaNaMemoria;
use App\Services\Secretario\Memoria\ConsultaDeMemoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Secretário → Memória (docs/specs/secretario-memoria, US4): a busca no
 * histórico da própria conta, por fonte, cada linha com link para o original.
 * Só lê; a síntese pela IA é um pedido à máquina da pessoa, nunca automático.
 */
class SecretarioMemoriaController extends Controller
{
    public function index(Request $request, BuscaNaMemoria $busca): View
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 120);
        $termos = BuscaNaMemoria::termos($q);
        $resultados = $termos ? $busca->buscar($request->user(), $termos) : collect();

        return view('secretario.memoria', [
            'busca' => $q,
            'termos' => $termos,
            'total' => $resultados->count(),
            'grupos' => BuscaNaMemoria::porFonte($resultados),
        ]);
    }

    public function sintese(Request $request, ConsultaDeMemoria $consulta): RedirectResponse
    {
        $q = mb_substr(trim((string) $request->validate(['q' => ['required', 'string', 'max:120']])['q']), 0, 120);

        return redirect()->route('secretario.memoria', ['q' => $q])->with('status', $consulta->sintese($request->user(), $q));
    }
}
