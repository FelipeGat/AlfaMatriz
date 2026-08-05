<?php

namespace App\Http\Controllers;

use App\Models\Cobranca;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\FaturamentoService;
use Illuminate\Http\Request;

class FaturamentoController extends Controller
{
    public function index(Request $request)
    {
        $competencia = $request->input('competencia', now()->format('Y-m'));

        $revendas = Revenda::where('ativo', true)->orderBy('nome')->get();

        $preview = $revendas->map(function (Revenda $revenda) use ($competencia) {
            $sistemas = Sistema::where('ativo', true)
                ->whereHas('clientes', fn ($q) => $q->where('revenda_id', $revenda->id)->where('clientes.ativo', true)->where('cliente_sistema.ativo', true))
                ->get();

            $linhas = $sistemas->map(function (Sistema $sistema) use ($revenda) {
                $clientesAtivos = $sistema->clientes()
                    ->where('revenda_id', $revenda->id)
                    ->where('clientes.ativo', true)
                    ->where('cliente_sistema.ativo', true)
                    ->get(['clientes.id', 'clientes.nome']);

                $qtd = $clientesAtivos->count();
                $tier = $sistema->tierParaVolume($qtd, $revenda->id);

                return [
                    'sistema' => $sistema->nome,
                    'clientes_ativos' => $qtd,
                    'clientes' => $clientesAtivos->pluck('nome'),
                    'tier' => $tier?->nome,
                    'valor' => $tier?->calcularMensalidade($qtd),
                ];
            });

            return [
                'revenda' => $revenda,
                'linhas' => $linhas,
                'total' => $linhas->sum('valor'),
            ];
        })->filter(fn ($r) => $r['linhas']->isNotEmpty());

        $cobrancasGeradas = Cobranca::where('tipo', 'locacao_sistema')
            ->where('competencia', $competencia)
            ->with('revenda')
            ->get();

        return view('faturamento.index', compact('competencia', 'preview', 'cobrancasGeradas'));
    }

    public function gerar(Request $request, FaturamentoService $service)
    {
        $competencia = $request->input('competencia', now()->format('Y-m'));

        $resultado = $service->gerarParaCompetencia($competencia);

        $cobrancasGeradas = collect($resultado)->filter(fn ($r) => isset($r['cobranca_id']))->count();

        return redirect()->route('faturamento.index', ['competencia' => $competencia])
            ->with('status', "Faturamento da competência {$competencia} gerado: {$cobrancasGeradas} cobrança(s) consolidada(s) criada(s).");
    }
}
