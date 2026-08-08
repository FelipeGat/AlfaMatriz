<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use Illuminate\Http\Request;

class CategoriaController extends Controller
{
    public function __construct()
    {
        $this->bloquearVisaoDaMatriz();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nome' => 'required|string|max:255',
            'tipo' => 'required|in:receita,despesa',
        ]);

        Categoria::create($data);

        return back()->with('status', 'Categoria cadastrada.');
    }

    public function destroy(Categoria $categoria)
    {
        $categoria->delete();

        return back()->with('status', 'Categoria removida.');
    }
}
