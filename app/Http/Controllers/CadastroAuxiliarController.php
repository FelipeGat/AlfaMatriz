<?php

namespace App\Http\Controllers;

use App\Models\CentroCusto;
use App\Models\Categoria;
use App\Models\Fornecedor;

class CadastroAuxiliarController extends Controller
{
    public function index()
    {
        $centrosCusto = CentroCusto::orderBy('nome')->get();
        $categorias = Categoria::with('subcategorias.contas')->orderBy('tipo')->orderBy('nome')->get();
        $fornecedores = Fornecedor::orderBy('razao_social')->get();

        return view('cadastros-auxiliares.index', compact('centrosCusto', 'categorias', 'fornecedores'));
    }
}
