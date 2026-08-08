<?php

namespace App\Http\Controllers;

use App\Models\CentroCusto;
use App\Models\Categoria;
use App\Models\Fornecedor;

class CadastroAuxiliarController extends Controller
{
    public function __construct()
    {
        $this->bloquearVisaoDaMatriz();
    }

    public function index()
    {
        $centrosCusto = CentroCusto::withCount('contasPagar')->orderBy('nome')->get();
        $categorias = Categoria::with(['subcategorias.contas' => function ($query) {
            $query->withCount('contasPagar');
        }])->orderBy('tipo')->orderBy('nome')->get();
        $fornecedores = Fornecedor::withCount('contasPagar')->orderBy('razao_social')->get();

        return view('cadastros-auxiliares.index', compact('centrosCusto', 'categorias', 'fornecedores'));
    }
}
