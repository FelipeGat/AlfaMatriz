<?php

use App\Http\Controllers\CadastroAuxiliarController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\CentroControleController;
use App\Http\Controllers\CentroCustoController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CobrancaController;
use App\Http\Controllers\ContaController;
use App\Http\Controllers\ContaFinanceiraController;
use App\Http\Controllers\ContaFixaPagarController;
use App\Http\Controllers\ContaPagarController;
use App\Http\Controllers\FaturamentoController;
use App\Http\Controllers\FornecedorController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\PainelController;
use App\Http\Controllers\PrecoAtacadoController;
use App\Http\Controllers\ProdutoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RevendaController;
use App\Http\Controllers\SaudeController;
use App\Http\Controllers\SistemaController;
use App\Http\Controllers\SubcategoriaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->user()?->temEscopoDeRevenda()) {
        return redirect()->route('clientes.index');
    }

    return redirect()->route('centro-controle');
});

// Fora do grupo autenticado de propósito: o deploy confere a saúde antes de
// existir qualquer sessão.
Route::get('/healthz', SaudeController::class)->name('healthz');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/centro-controle', [CentroControleController::class, 'index'])->name('centro-controle')
        ->middleware('permissao:dashboard');
    Route::get('/dashboard', [PainelController::class, 'index'])->name('dashboard')
        ->middleware('permissao:dashboard');
    Route::get('/comercial', [PainelController::class, 'comercial'])->name('comercial')
        ->middleware('permissao:dashboard');

    Route::get('produtos', [ProdutoController::class, 'index'])->name('produtos.index')
        ->middleware('permissao:sistemas');
    Route::put('produtos/{sistema}', [ProdutoController::class, 'update'])->name('produtos.update')
        ->middleware('permissao:sistemas');

    Route::get('leads', [LeadController::class, 'index'])->name('leads.index')
        ->middleware('permissao:leads');
    Route::post('leads', [LeadController::class, 'store'])->name('leads.store')
        ->middleware('permissao:leads');
    Route::put('leads/{lead}', [LeadController::class, 'update'])->name('leads.update')
        ->middleware('permissao:leads');
    Route::post('leads/{lead}/mover', [LeadController::class, 'mover'])->name('leads.mover')
        ->middleware('permissao:leads');
    Route::delete('leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy')
        ->middleware('permissao:leads');

    Route::resource('revendas', RevendaController::class)
        ->middleware('permissao:revendas');
    Route::resource('clientes', ClienteController::class)
        ->middleware('permissao:clientes');

    Route::resource('sistemas', SistemaController::class)->only(['index', 'edit', 'update'])
        ->middleware('permissao:sistemas');
    Route::post('sistemas/{sistema}/precos', [PrecoAtacadoController::class, 'store'])->name('precos.store')
        ->middleware('permissao:sistemas');
    Route::delete('precos/{preco}', [PrecoAtacadoController::class, 'destroy'])->name('precos.destroy')
        ->middleware('permissao:sistemas');

    Route::get('faturamento', [FaturamentoController::class, 'index'])->name('faturamento.index')
        ->middleware('permissao:faturamento');
    Route::post('faturamento/gerar', [FaturamentoController::class, 'gerar'])->name('faturamento.gerar')
        ->middleware('permissao:faturamento');

    Route::resource('cobrancas', CobrancaController::class)
        ->middleware('permissao:cobrancas');
    Route::post('cobrancas/{cobranca}/baixar', [CobrancaController::class, 'baixar'])->name('cobrancas.baixar')
        ->middleware('permissao:cobrancas');
    Route::post('cobrancas/baixar-em-massa', [CobrancaController::class, 'baixarEmMassa'])->name('cobrancas.baixarEmMassa')
        ->middleware('permissao:cobrancas');
    Route::get('cobrancas/{cobranca}/anexos', [CobrancaController::class, 'listarAnexos'])->name('cobrancas.anexos.listar')
        ->middleware('permissao:cobrancas');
    Route::post('cobrancas/{cobranca}/anexos', [CobrancaController::class, 'storeAnexo'])->name('cobrancas.anexos.upload')
        ->middleware('permissao:cobrancas');
    Route::get('cobrancas/anexos/{anexo}/download', [CobrancaController::class, 'downloadAnexo'])->name('cobrancas.anexos.download')
        ->middleware('permissao:cobrancas');
    Route::delete('cobrancas/anexos/{anexo}', [CobrancaController::class, 'destroyAnexo'])->name('cobrancas.anexos.destroy')
        ->middleware('permissao:cobrancas');

    Route::resource('contas-pagar', ContaPagarController::class)->except(['show'])
        ->parameters(['contas-pagar' => 'conta_pagar'])
        ->middleware('permissao:contas_pagar');
    Route::post('contas-pagar/{conta_pagar}/baixar', [ContaPagarController::class, 'baixar'])->name('contas-pagar.baixar')
        ->middleware('permissao:contas_pagar');
    Route::post('contas-pagar/baixar-em-massa', [ContaPagarController::class, 'baixarEmMassa'])->name('contas-pagar.baixarEmMassa')
        ->middleware('permissao:contas_pagar');
    Route::get('contas-pagar/{conta_pagar}/anexos', [ContaPagarController::class, 'listarAnexos'])->name('contas-pagar.anexos.listar')
        ->middleware('permissao:contas_pagar');
    Route::post('contas-pagar/{conta_pagar}/anexos', [ContaPagarController::class, 'storeAnexo'])->name('contas-pagar.anexos.upload')
        ->middleware('permissao:contas_pagar');
    Route::get('contas-pagar/anexos/{anexo}/download', [ContaPagarController::class, 'downloadAnexo'])->name('contas-pagar.anexos.download')
        ->middleware('permissao:contas_pagar');
    Route::delete('contas-pagar/anexos/{anexo}', [ContaPagarController::class, 'destroyAnexo'])->name('contas-pagar.anexos.destroy')
        ->middleware('permissao:contas_pagar');

    Route::resource('contas-fixas-pagar', ContaFixaPagarController::class)->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['contas-fixas-pagar' => 'conta_fixa_pagar'])
        ->middleware('permissao:contas_pagar');
    Route::post('contas-fixas-pagar/gerar', [ContaFixaPagarController::class, 'gerar'])->name('contas-fixas-pagar.gerar')
        ->middleware('permissao:contas_pagar');
    Route::post('contas-fixas-pagar/{conta_fixa_pagar}/pausar', [ContaFixaPagarController::class, 'pausar'])->name('contas-fixas-pagar.pausar')
        ->middleware('permissao:contas_pagar');

    Route::resource('contas-financeiras', ContaFinanceiraController::class)->except(['show'])
        ->parameters(['contas-financeiras' => 'conta_financeira'])
        ->middleware('permissao:financeiro');
    Route::get('contas-financeiras/{conta_financeira}/extrato', [ContaFinanceiraController::class, 'extrato'])->name('contas-financeiras.extrato')
        ->middleware('permissao:financeiro');

    Route::get('cadastros-auxiliares', [CadastroAuxiliarController::class, 'index'])->name('cadastros-auxiliares.index')
        ->middleware('permissao:financeiro');
    Route::resource('centros-custo', CentroCustoController::class)->only(['store', 'destroy'])
        ->parameters(['centros-custo' => 'centro_custo'])
        ->middleware('permissao:financeiro');
    Route::resource('categorias', CategoriaController::class)->only(['store', 'destroy'])
        ->middleware('permissao:financeiro');
    Route::resource('subcategorias', SubcategoriaController::class)->only(['store', 'destroy'])
        ->middleware('permissao:financeiro');
    Route::resource('contas', ContaController::class)->only(['store', 'destroy'])
        ->middleware('permissao:financeiro');
    Route::resource('fornecedores', FornecedorController::class)->only(['index', 'store', 'destroy'])
        ->parameters(['fornecedores' => 'fornecedor'])
        ->middleware('permissao:financeiro');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
