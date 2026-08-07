<?php

namespace App\Http\Controllers;

use App\Models\Sistema;
use App\Models\SistemaLicenca;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * As licenças de todos os sistemas.
 *
 * Só leitura por enquanto: liberar, renovar e bloquear entram quando a matriz
 * passar a ser dona do cadastro. Uma tela com botões que não fazem nada seria
 * pior que uma tela sem botões.
 */
class IntegracaoLicencaController extends Controller
{
    public function index(Request $request): View
    {
        $dias = (int) config('integracao.dias_para_licenca_vencendo', 30);
        $sistemas = Sistema::where('categoria', 'saas')->orderBy('nome')->get();

        $faixas = [
            'pendentes' => fn ($q) => $q->where('status', 'pendente'),
            'vencendo' => fn ($q) => $q->vencendoEm($dias),
            'vencidas' => fn ($q) => $q->vencidas(),
            'ativas' => fn ($q) => $q->where('status', 'ativa'),
        ];

        $faixa = array_key_exists((string) $request->faixa, $faixas) ? $request->faixa : 'vencendo';

        $base = fn () => SistemaLicenca::query()
            ->presentes()
            ->when($request->sistema, fn ($q, $id) => $q->where('sistema_id', $id));

        $contagens = [];
        foreach ($faixas as $nome => $filtro) {
            $contagens[$nome] = $filtro($base())->count();
        }

        $licencas = $faixas[$faixa]($base())
            ->with(['sistema', 'sistemaCliente.cliente'])
            ->orderByRaw('fim_em is null, fim_em asc')
            ->paginate(50)
            ->withQueryString();

        return view('integracao.licencas', [
            'sistemas' => $sistemas,
            'licencas' => $licencas,
            'contagens' => $contagens,
            'faixa' => $faixa,
            'dias' => $dias,
            'filtros' => $request->only(['sistema', 'faixa']),
            'atualizadoEm' => $sistemas->max('sincronizado_em'),
        ]);
    }
}
