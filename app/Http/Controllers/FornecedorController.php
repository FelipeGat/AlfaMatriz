<?php

namespace App\Http\Controllers;

use App\Models\Fornecedor;
use Illuminate\Http\Request;

class FornecedorController extends Controller
{
    public function index()
    {
        return redirect()->route('cadastros-auxiliares.index');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'razao_social' => 'required|string|max:255',
            'nome_fantasia' => 'nullable|string|max:255',
            'cpf_cnpj' => 'nullable|string|max:18',
            'email' => 'nullable|email|max:255',
            'telefone' => 'nullable|string|max:30',
        ]);

        Fornecedor::create($data);

        return back()->with('status', 'Fornecedor cadastrado.');
    }

    public function destroy(Fornecedor $fornecedor)
    {
        $fornecedor->delete();

        return back()->with('status', 'Fornecedor removido.');
    }
}
