<?php

namespace App\Http\Controllers;

use App\Models\Conta;
use Illuminate\Http\Request;

class ContaController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'subcategoria_id' => 'required|exists:subcategorias,id',
            'nome' => 'required|string|max:255',
        ]);

        Conta::create($data);

        return back()->with('status', 'Conta cadastrada.');
    }

    public function destroy(Conta $conta)
    {
        $conta->delete();

        return back()->with('status', 'Conta removida.');
    }
}
