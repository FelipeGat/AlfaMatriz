<?php

namespace App\Http\Controllers;

use App\Models\Sistema;
use Illuminate\Http\Request;

class SistemaController extends Controller
{
    public function index()
    {
        $sistemas = Sistema::withCount('clientes')
            ->with(['precosAtacado' => fn ($q) => $q->whereNull('revenda_id')->orderBy('ordem')])
            ->orderBy('categoria')
            ->orderBy('nome')
            ->get();

        return view('sistemas.index', compact('sistemas'));
    }

    public function edit(Sistema $sistema)
    {
        $sistema->load(['precosAtacado' => fn ($q) => $q->orderBy('ordem'), 'precosAtacado.revenda']);
        $revendas = \App\Models\Revenda::orderBy('nome')->get();

        return view('sistemas.edit', compact('sistema', 'revendas'));
    }

    public function update(Request $request, Sistema $sistema)
    {
        $data = $request->validate([
            'categoria' => 'required|in:saas,crm',
            'unidade_cobranca' => 'required|string|max:255',
            'base_url' => 'nullable|url|max:255',
            'token' => 'nullable|string',
        ]);

        $data['ativo'] = $request->boolean('ativo');

        $sistema->update($data);

        return redirect()->route('produtos.index')->with('status', 'Sistema atualizado com sucesso.');
    }
}
