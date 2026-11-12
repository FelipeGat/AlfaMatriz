<?php

namespace App\Http\Controllers;

use App\Support\ConversasDeExemplo;
use Illuminate\View\View;

/**
 * Protótipo da tela Conversas (T-602) — só GET, só desenho.
 *
 * Não há rota de escrita: enviar, anexar e "levar para a tarefa" acontecem no
 * navegador e somem ao recarregar. Assim o protótipo não precisa de tabela
 * nem de migração, e desligá-lo não deixa nada para trás.
 *
 * A rota existe sempre, e é o controller que responde 404 com a chave
 * desligada: registrar a rota condicionalmente amarraria o comportamento ao
 * cache de rotas e impediria o teste de ligar a chave.
 */
class ConversasPrototipoController extends Controller
{
    public function index(): View
    {
        abort_unless(config('alfa.conversas_prototipo'), 404);

        return view('conversas.prototipo', [
            'conversas' => ConversasDeExemplo::todas(),
            'chegada' => ConversasDeExemplo::chegada(),
        ]);
    }
}
