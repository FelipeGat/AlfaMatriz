<?php

use App\Http\Controllers\Auth\PrimeiroAcessoController;
use App\Http\Controllers\AuditoriaController;
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
use App\Http\Controllers\NotificacaoController;
use App\Http\Controllers\PainelController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\PrecoAtacadoController;
use App\Http\Controllers\ProdutoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RevendaController;
use App\Http\Controllers\SaudeController;
use App\Http\Controllers\SistemaController;
use App\Http\Controllers\SubcategoriaController;
use App\Http\Controllers\TarefaController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

// A raiz não é tela: encaminha para a inicial de QUEM ABRE. Ela mandava todo
// mundo que não fosse revenda ao Centro de Controle, e quem não tem `dashboard`
// — o perfil comercial — recebia 403 ao digitar o endereço do painel. É o
// último dos quatro desvios a perguntar a `telaInicial()`, que existe
// exatamente para nenhum deles responder por conta própria.
//
// Visitante deslogado vai direto ao login, e não ao Centro de Controle para de
// lá ser expulso: o destino é o mesmo, com um salto a menos.
Route::get('/', fn () => redirect()->to(auth()->user()?->telaInicial() ?? route('login')));

// Fora do grupo autenticado de propósito: o deploy confere a saúde antes de
// existir qualquer sessão.
Route::get('/healthz', SaudeController::class)->name('healthz');

// Token fresco para as telas de fora (login, senha esquecida): elas ficam
// abertas por horas e o token embutido no HTML vence junto com a sessão.
// Buscar aqui renova os dois de uma vez, porque a resposta também recria o
// cookie de sessão. Só faz sentido antes de entrar — sessão de quem já está
// dentro deve expirar mesmo.
Route::get('/csrf-token', fn () => response()->json(['token' => csrf_token()]))
    ->middleware('guest')
    ->name('csrf-token');

// `conta-ativa` e `senha-em-dia` no grupo inteiro, e não rota a rota: as duas
// regras valem para TUDO que está atrás do login, e uma lista de rotas seria
// uma lista para alguém esquecer de completar na próxima tela.
Route::middleware(['auth', 'verified', 'conta-ativa', 'senha-em-dia'])->group(function () {
    // Troca obrigatória de senha. Isenta do `senha-em-dia` por nome, dentro do
    // próprio middleware — no grupo por causa das outras duas checagens.
    Route::get('primeiro-acesso', [PrimeiroAcessoController::class, 'edit'])
        ->name('senha.primeiro-acesso');
    Route::put('primeiro-acesso', [PrimeiroAcessoController::class, 'update'])
        ->name('senha.primeiro-acesso.update');

    // O sino não tem tela: o painel viaja com a sidebar, em todas as telas.
    // A única ação que chega ao servidor é dar por lido.
    Route::post('notificacoes/lidas', [NotificacaoController::class, 'marcarLidas'])
        ->name('notificacoes.lidas');

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

    Route::get('tarefas', [TarefaController::class, 'index'])->name('tarefas.index')
        ->middleware('permissao:tarefas');
    Route::get('tarefas/historico', [TarefaController::class, 'historico'])->name('tarefas.historico')
        ->middleware('permissao:tarefas');
    Route::post('tarefas', [TarefaController::class, 'store'])->name('tarefas.store')
        ->middleware('permissao:tarefas');
    Route::put('tarefas/{tarefa}', [TarefaController::class, 'update'])->name('tarefas.update')
        ->middleware('permissao:tarefas');
    Route::post('tarefas/{tarefa}/mover', [TarefaController::class, 'mover'])->name('tarefas.mover')
        ->middleware('permissao:tarefas');
    // Travar não é mover: a tarefa fica na etapa e só ganha a marca. Por isso
    // rota própria, e não um destino de `tarefas.mover` — o bloqueio saiu do
    // mapa de transições justamente para parar de fingir que era etapa.
    Route::post('tarefas/{tarefa}/bloquear', [TarefaController::class, 'bloquear'])
        ->name('tarefas.bloquear')
        ->middleware('permissao:tarefas');
    // Perguntar também não é mover, e não é bloquear: o PR continua aberto e a
    // tarefa continua no WIP. Rota própria pela mesma razão do bloqueio — o que
    // muda é de quem é a vez, não onde a tarefa está.
    Route::post('tarefas/{tarefa}/conversar', [TarefaController::class, 'conversar'])
        ->name('tarefas.conversar')
        ->middleware('permissao:tarefas');
    // Não há rota de criar comentário: ele viaja no `tarefas.update`, no mesmo
    // envio do cadastro. Corrigir e apagar continuam sendo caminho próprio —
    // mexem no que já foi publicado, e não podem ir de carona no salvar.
    //
    // Sem o id da tarefa no caminho, como os anexos: o comentário já sabe de
    // quem é, e um segundo id no endereço só criaria um par para conferir.
    // Excluir apaga o registro; cancelar encerra com motivo e fica no
    // histórico. São coisas diferentes, e por isso caminhos diferentes.
    Route::delete('tarefas/{tarefa}', [TarefaController::class, 'destroy'])
        ->name('tarefas.destroy')
        ->middleware('permissao:tarefas');

    // Posicionar card dentro da coluna. Sem id na rota: o envio traz a coluna
    // inteira, porque arrastar reordena a lista toda na tela.
    Route::post('tarefas/posicionar', [TarefaController::class, 'posicionarNaColuna'])
        ->name('tarefas.posicionar')
        ->middleware('permissao:tarefas');

    // Checklist: criar e reordenar pendem da tarefa (é ela que dá a lista);
    // marcar, corrigir e remover pendem do item, que já sabe de quem é.
    Route::post('tarefas/{tarefa}/itens', [TarefaController::class, 'criarItem'])
        ->name('tarefas.itens.store')
        ->middleware('permissao:tarefas');
    Route::post('tarefas/{tarefa}/itens/ordenar', [TarefaController::class, 'ordenarItens'])
        ->name('tarefas.itens.ordenar')
        ->middleware('permissao:tarefas');
    Route::put('tarefas/itens/{item}', [TarefaController::class, 'atualizarItem'])
        ->name('tarefas.itens.update')
        ->middleware('permissao:tarefas');
    Route::delete('tarefas/itens/{item}', [TarefaController::class, 'excluirItem'])
        ->name('tarefas.itens.destroy')
        ->middleware('permissao:tarefas');

    Route::put('tarefas/comentarios/{comentario}', [TarefaController::class, 'editarComentario'])
        ->name('tarefas.comentarios.update')
        ->middleware('permissao:tarefas');
    Route::delete('tarefas/comentarios/{comentario}', [TarefaController::class, 'excluirComentario'])
        ->name('tarefas.comentarios.destroy')
        ->middleware('permissao:tarefas');

    Route::resource('revendas', RevendaController::class)
        ->middleware('permissao:revendas');
    Route::post('revendas/{revenda}/provisionar', [RevendaController::class, 'provisionar'])
        ->name('revendas.provisionar')
        ->middleware('permissao:revendas');
    // `create` e `show` ficam de fora: o cadastro de cliente acontece no modal
    // da lista (uma tela só), e `show` nunca teve método no controller — a rota
    // gerada apontaria para o nada.
    Route::resource('clientes', ClienteController::class)
        ->except(['create', 'show'])
        ->middleware('permissao:clientes');
    // A licença é de um cliente NUM sistema: o mesmo cliente pode ter licença
    // no AlfaGym e no AlfaControl, com estados diferentes. O sistema é
    // identidade do recurso, por isso vai na URL — os nomes das rotas não
    // mudam, só ganham um argumento.
    Route::post('clientes/{cliente}/sistemas/{sistema}/licenca/liberar', [ClienteController::class, 'liberarLicenca'])
        ->name('clientes.liberarLicenca')
        ->middleware('permissao:clientes');
    Route::post('clientes/{cliente}/sistemas/{sistema}/licenca/renovar', [ClienteController::class, 'renovarLicenca'])
        ->name('clientes.renovarLicenca')
        ->middleware('permissao:clientes');
    Route::post('clientes/{cliente}/sistemas/{sistema}/licenca/bloquear', [ClienteController::class, 'bloquearLicenca'])
        ->name('clientes.bloquearLicenca')
        ->middleware('permissao:clientes');
    Route::post('clientes/{cliente}/sistemas/{sistema}/licenca/desbloquear', [ClienteController::class, 'desbloquearLicenca'])
        ->name('clientes.desbloquearLicenca')
        ->middleware('permissao:clientes');

    // `create` e `store` entram no mesmo recurso, e não em rota solta, para o
    // cadastro herdar a MESMA porta que já protege preço e tier: registrar
    // sistema é decisão de quem cuida do catálogo. O verbo faz o resto — o
    // middleware lê `incluir` no POST e `ler` no GET.
    Route::resource('sistemas', SistemaController::class)->only(['index', 'create', 'store', 'edit', 'update'])
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

    Route::get('usuarios', [UsuarioController::class, 'index'])->name('usuarios.index')
        ->middleware('permissao:usuarios');
    Route::post('usuarios', [UsuarioController::class, 'store'])->name('usuarios.store')
        ->middleware('permissao:usuarios');
    Route::put('usuarios/{usuario}', [UsuarioController::class, 'update'])->name('usuarios.update')
        ->middleware('permissao:usuarios');
    Route::post('usuarios/{usuario}/senha', [UsuarioController::class, 'redefinirSenha'])->name('usuarios.senha')
        ->middleware('permissao:usuarios');
    // Ação fixada em `excluir`, e não inferida do POST: o middleware leria
    // "incluir" pelo verbo, e fechar o acesso de alguém não é incluir nada.
    Route::post('usuarios/{usuario}/ativo', [UsuarioController::class, 'alternarAtivo'])->name('usuarios.ativo')
        ->middleware('permissao:usuarios,excluir');
    Route::delete('usuarios/{usuario}', [UsuarioController::class, 'destroy'])->name('usuarios.destroy')
        ->middleware('permissao:usuarios');
    // A grade de permissões mora na segunda aba de `usuarios.index`; o perfil
    // não tem tela própria, só o salvamento.
    Route::put('perfis/{perfil}/permissoes', [PerfilController::class, 'update'])->name('perfis.permissoes')
        ->middleware('permissao:usuarios');
    // Uma rota só, e de leitura. A auditoria não tem `store`, `update` nem
    // `destroy` de propósito: o modelo recusa alteração, e um endpoint de
    // escrita seria a única porta capaz de fazê-la mentir.
    Route::get('auditoria', [AuditoriaController::class, 'index'])->name('auditoria.index')
        ->middleware('permissao:auditoria');
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
