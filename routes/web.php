<?php

use App\Http\Controllers\CadastroAuxiliarController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\CentroCustoController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CobrancaController;
use App\Http\Controllers\ContaController;
use App\Http\Controllers\ContaFinanceiraController;
use App\Http\Controllers\ContaFixaPagarController;
use App\Http\Controllers\ContaPagarController;
use App\Http\Controllers\FaturamentoController;
use App\Http\Controllers\FornecedorController;
use App\Http\Controllers\PainelController;
use App\Http\Controllers\PrecoAtacadoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RevendaController;
use App\Http\Controllers\SistemaController;
use App\Http\Controllers\SubcategoriaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [PainelController::class, 'index'])->name('dashboard');
    Route::get('/comercial', [PainelController::class, 'comercial'])->name('comercial');

    Route::resource('revendas', RevendaController::class);
    Route::resource('clientes', ClienteController::class);

    Route::resource('sistemas', SistemaController::class)->only(['index', 'edit', 'update']);
    Route::post('sistemas/{sistema}/precos', [PrecoAtacadoController::class, 'store'])->name('precos.store');
    Route::delete('precos/{preco}', [PrecoAtacadoController::class, 'destroy'])->name('precos.destroy');

    Route::get('faturamento', [FaturamentoController::class, 'index'])->name('faturamento.index');
    Route::post('faturamento/gerar', [FaturamentoController::class, 'gerar'])->name('faturamento.gerar');

    Route::resource('cobrancas', CobrancaController::class);
    Route::post('cobrancas/{cobranca}/baixar', [CobrancaController::class, 'baixar'])->name('cobrancas.baixar');

    Route::resource('contas-pagar', ContaPagarController::class)->except(['show'])
        ->parameters(['contas-pagar' => 'conta_pagar']);
    Route::post('contas-pagar/{conta_pagar}/baixar', [ContaPagarController::class, 'baixar'])->name('contas-pagar.baixar');

    Route::resource('contas-fixas-pagar', ContaFixaPagarController::class)->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['contas-fixas-pagar' => 'conta_fixa_pagar']);
    Route::post('contas-fixas-pagar/gerar', [ContaFixaPagarController::class, 'gerar'])->name('contas-fixas-pagar.gerar');

    Route::resource('contas-financeiras', ContaFinanceiraController::class)->except(['show'])
        ->parameters(['contas-financeiras' => 'conta_financeira']);
    Route::get('contas-financeiras/{conta_financeira}/extrato', [ContaFinanceiraController::class, 'extrato'])->name('contas-financeiras.extrato');

    Route::get('cadastros-auxiliares', [CadastroAuxiliarController::class, 'index'])->name('cadastros-auxiliares.index');
    Route::resource('centros-custo', CentroCustoController::class)->only(['store', 'destroy'])
        ->parameters(['centros-custo' => 'centro_custo']);
    Route::resource('categorias', CategoriaController::class)->only(['store', 'destroy']);
    Route::resource('subcategorias', SubcategoriaController::class)->only(['store', 'destroy']);
    Route::resource('contas', ContaController::class)->only(['store', 'destroy']);
    Route::resource('fornecedores', FornecedorController::class)->only(['index', 'store', 'destroy'])
        ->parameters(['fornecedores' => 'fornecedor']);

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
