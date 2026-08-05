<?php

namespace App\Http\Controllers;

use App\Models\Subcategoria;
use Illuminate\Http\Request;

class SubcategoriaController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'categoria_id' => 'required|exists:categorias,id',
            'nome' => 'required|string|max:255',
        ]);

        Subcategoria::create($data);

        return back()->with('status', 'Subcategoria cadastrada.');
    }

    public function destroy(Subcategoria $subcategoria)
    {
        $subcategoria->delete();

        return back()->with('status', 'Subcategoria removida.');
    }
}
