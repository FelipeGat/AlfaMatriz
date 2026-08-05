<?php

namespace App\Http\Controllers;

use App\Models\CentroCusto;
use Illuminate\Http\Request;

class CentroCustoController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate(['nome' => 'required|string|max:255']);

        CentroCusto::create($data);

        return back()->with('status', 'Centro de custo cadastrado.');
    }

    public function destroy(CentroCusto $centro_custo)
    {
        $centro_custo->delete();

        return back()->with('status', 'Centro de custo removido.');
    }
}
