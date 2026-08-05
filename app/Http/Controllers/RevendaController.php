<?php

namespace App\Http\Controllers;

use App\Models\Revenda;
use Illuminate\Http\Request;

class RevendaController extends Controller
{
    public function index()
    {
        $revendas = Revenda::withCount('clientes')->orderBy('nome')->paginate(15);

        return view('revendas.index', compact('revendas'));
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
