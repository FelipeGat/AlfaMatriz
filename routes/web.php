<?php

use App\Http\Controllers\CadastroAuxiliarController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\CentroControleController;
use App\Http\Controllers\CentroCustoController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CobrancaController;
use App\Http\Controllers\ConferenciaController;
use App\Http\Controllers\ContaController;
use App\Http\Controllers\ContaFinanceiraController;
use App\Http\Controllers\ContaFixaPagarController;
use App\Http\Controllers\ContaPagarController;
use App\Http\Controllers\DivergenciaController;
use App\Http\Controllers\FaturamentoController;
use App\Http\Controllers\FornecedorController;
use App\Http\Controllers\IntegracaoClienteController;
use App\Http\Controllers\IntegracaoController;
use App\Http\Controllers\IntegracaoContratoController;
use App\Http\Controllers\IntegracaoLicencaController;
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
    return redirect()->route('centro-controle');
});

// Fora do grupo autenticado de propósito: o deploy confere a saúde antes de
// existir qualquer sessão.
Route::get('/healthz', SaudeController::class)->name('healthz');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/centro-controle', [CentroControleController::class, 'index'])->name('centro-controle');
    Route::get('/dashboard', [PainelController::class, 'index'])->name('dashboard');
    Route::get('/comercial', [PainelController::class, 'comercial'])->name('comercial');

    Route::get('produtos', [ProdutoController::class, 'index'])->name('produtos.index');
    Route::put('produtos/{sistema}', [ProdutoController::class, 'update'])->name('produtos.update');

    Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
    Route::post('leads', [LeadController::class, 'store'])->name('leads.store');
    Route::put('leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
    Route::post('leads/{lead}/mover', [LeadController::class, 'mover'])->name('leads.mover');
    Route::delete('leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy');

    Route::resource('revendas', RevendaController::class);
    Route::resource('clientes', ClienteController::class);

    Route::resource('sistemas', SistemaController::class)->only(['index', 'edit', 'update']);
    Route::post('sistemas/{sistema}/precos', [PrecoAtacadoController::class, 'store'])->name('precos.store');
    Route::delete('precos/{preco}', [PrecoAtacadoController::class, 'destroy'])->name('precos.destroy');

    Route::get('faturamento', [FaturamentoController::class, 'index'])->name('faturamento.index');
    Route::post('faturamento/gerar', [FaturamentoController::class, 'gerar'])->name('faturamento.gerar');

    // Integração com os sistemas da casa. Só leitura por enquanto: liberar
    // licença, provisionar cliente e pôr em manutenção entram quando a matriz
    // passar a ser dona do cadastro.
    Route::prefix('integracao')->name('integracao.')->group(function () {
        Route::get('/', [IntegracaoController::class, 'index'])->name('index');
        Route::post('{sistema}/testar-conexao', [IntegracaoController::class, 'testarConexao'])->name('testar');

        Route::get('conferencia', [ConferenciaController::class, 'index'])->name('conferencia');
        Route::post('conferencia/{sistemaCliente}/vincular', [ConferenciaController::class, 'vincularCliente'])->name('conferencia.vincular');
        Route::post('conferencia/{sistemaRevenda}/vincular-revenda', [ConferenciaController::class, 'vincularRevenda'])->name('conferencia.vincularRevenda');
        Route::post('conferencia/{sistema}/corte', [ConferenciaController::class, 'aplicarCorte'])->name('conferencia.corte');

        Route::get('clientes', [IntegracaoClienteController::class, 'index'])->name('clientes');
        Route::get('licencas', [IntegracaoLicencaController::class, 'index'])->name('licencas');
        Route::get('contratos', [IntegracaoContratoController::class, 'index'])->name('contratos');
        Route::get('contratos/exportar', [IntegracaoContratoController::class, 'exportar'])->name('contratos.exportar');
        Route::get('divergencias', [DivergenciaController::class, 'index'])->name('divergencias');
    });

    Route::resource('cobrancas', CobrancaController::class);
    Route::post('cobrancas/{cobranca}/baixar', [CobrancaController::class, 'baixar'])->name('cobrancas.baixar');
    Route::post('cobrancas/baixar-em-massa', [CobrancaController::class, 'baixarEmMassa'])->name('cobrancas.baixarEmMassa');
    Route::get('cobrancas/{cobranca}/anexos', [CobrancaController::class, 'listarAnexos'])->name('cobrancas.anexos.listar');
    Route::post('cobrancas/{cobranca}/anexos', [CobrancaController::class, 'storeAnexo'])->name('cobrancas.anexos.upload');
    Route::get('cobrancas/anexos/{anexo}/download', [CobrancaController::class, 'downloadAnexo'])->name('cobrancas.anexos.download');
    Route::delete('cobrancas/anexos/{anexo}', [CobrancaController::class, 'destroyAnexo'])->name('cobrancas.anexos.destroy');

    Route::resource('contas-pagar', ContaPagarController::class)->except(['show'])
        ->parameters(['contas-pagar' => 'conta_pagar']);
    Route::post('contas-pagar/{conta_pagar}/baixar', [ContaPagarController::class, 'baixar'])->name('contas-pagar.baixar');
    Route::post('contas-pagar/baixar-em-massa', [ContaPagarController::class, 'baixarEmMassa'])->name('contas-pagar.baixarEmMassa');
    Route::get('contas-pagar/{conta_pagar}/anexos', [ContaPagarController::class, 'listarAnexos'])->name('contas-pagar.anexos.listar');
    Route::post('contas-pagar/{conta_pagar}/anexos', [ContaPagarController::class, 'storeAnexo'])->name('contas-pagar.anexos.upload');
    Route::get('contas-pagar/anexos/{anexo}/download', [ContaPagarController::class, 'downloadAnexo'])->name('contas-pagar.anexos.download');
    Route::delete('contas-pagar/anexos/{anexo}', [ContaPagarController::class, 'destroyAnexo'])->name('contas-pagar.anexos.destroy');

    Route::resource('contas-fixas-pagar', ContaFixaPagarController::class)->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['contas-fixas-pagar' => 'conta_fixa_pagar']);
    Route::post('contas-fixas-pagar/gerar', [ContaFixaPagarController::class, 'gerar'])->name('contas-fixas-pagar.gerar');
    Route::post('contas-fixas-pagar/{conta_fixa_pagar}/pausar', [ContaFixaPagarController::class, 'pausar'])->name('contas-fixas-pagar.pausar');

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
