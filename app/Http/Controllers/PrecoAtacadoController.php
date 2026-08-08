<?php

namespace App\Http\Controllers;

use App\Models\PrecoAtacado;
use App\Models\Sistema;
use Illuminate\Http\Request;

class PrecoAtacadoController extends Controller
{
    public function store(Request $request, Sistema $sistema)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'revenda_id' => 'nullable|exists:revendas,id',
            'nome' => 'required|string|max:255',
            'preco_base' => 'required|numeric|min:0',
            'unidades_inclusas' => 'nullable|integer|min:0',
            'valor_excedente_unidade' => 'nullable|numeric|min:0',
            'limite_unidades' => 'nullable|integer|min:1',
            'ordem' => 'nullable|integer|min:0',
            'vigencia_inicio' => 'required|date',
            'vigencia_fim' => 'nullable|date|after_or_equal:vigencia_inicio',
        ]);

        $data['sistema_id'] = $sistema->id;
        $data['ordem'] = $data['ordem'] ?? ((int) $sistema->precosAtacado()->max('ordem') + 1);

        PrecoAtacado::create($data);

        return redirect()->route('sistemas.edit', $sistema)->with('status', 'Tier de atacado cadastrado.');
    }

    public function destroy(PrecoAtacado $preco)
    {
        $this->bloquearVisaoDaMatriz();

        $sistema = $preco->sistema;
        $preco->delete();

        return redirect()->route('sistemas.edit', $sistema)->with('status', 'Tier de atacado removido.');
    }
}
