<?php

namespace App\Http\Controllers;

use App\Services\Integracao\DivergenciaService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DivergenciaController extends Controller
{
    public function __construct(private readonly DivergenciaService $divergencias) {}

    public function index(Request $request): View
    {
        $competencia = preg_match('/^\d{4}-\d{2}$/', (string) $request->competencia)
            ? $request->competencia
            : now()->format('Y-m');

        $blocos = $this->divergencias->apurar($competencia);

        return view('integracao.divergencias', [
            'competencia' => $competencia,
            'blocos' => $blocos,
            'total' => collect($blocos)->sum(fn ($linhas) => $linhas->count()),
            'competenciasRecentes' => collect(range(0, 5))
                ->map(fn ($i) => now()->subMonths($i)->format('Y-m')),
        ]);
    }
}
