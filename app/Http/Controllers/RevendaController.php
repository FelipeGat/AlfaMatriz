<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Revenda;
use App\Services\IndicadoresService;
use Illuminate\Http\Request;

class RevendaController extends Controller
{
    public function index(IndicadoresService $indicadores)
    {
        $revendas = Revenda::withCount('clientes')->orderBy('nome')->paginate(15);

        // Resumo do topo. Os contadores saem da mesma origem dos painéis —
        // é o que faz o número bater entre as telas.
        $revendasAtivas = $indicadores->revendasAtivas();
        $clientesEmRevenda = Cliente::where('ativo', true)->whereNotNull('revenda_id')->count();
        $mrrRevendas = $indicadores->mrr();
        $ticketMedio = $revendasAtivas > 0 ? $mrrRevendas / $revendasAtivas : 0.0;

        return view('revendas.index', compact(
            'revendas', 'revendasAtivas', 'clientesEmRevenda', 'mrrRevendas', 'ticketMedio'
        ));
    }

    public function create()
    {
        return view('revendas.create');
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['ativo'] = $request->boolean('ativo');

        Revenda::create($data);

        return redirect()->route('revendas.index')->with('status', 'Revenda cadastrada com sucesso.');
    }

    public function edit(Revenda $revenda)
    {
        return view('revendas.edit', compact('revenda'));
    }

    public function update(Request $request, Revenda $revenda)
    {
        $data = $this->validated($request, $revenda->id);
        $data['ativo'] = $request->boolean('ativo');

        $revenda->update($data);

        return redirect()->route('revendas.index')->with('status', 'Revenda atualizada com sucesso.');
    }

    public function destroy(Revenda $revenda)
    {
        $revenda->delete();

        return redirect()->route('revendas.index')->with('status', 'Revenda removida.');
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'nome' => 'required|string|max:255',
            'cnpj' => 'nullable|string|max:18|unique:revendas,cnpj,'.($ignoreId ?? 'NULL').',id',
            'contato_nome' => 'nullable|string|max:255',
            'contato_email' => 'nullable|email|max:255',
            'contato_telefone' => 'nullable|string|max:30',
        ]);
    }
}
