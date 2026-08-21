<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A sessão vista pela tela — quanto tempo ainda resta, e como terminá-la.
 *
 * O painel desenha a página uma vez e ela fica aberta. A sessão, porém,
 * continua correndo no servidor: quando vence, o HTML na tela não sabe de
 * nada e continua com cara de funcionando. Estes dois pontos existem para a
 * página acompanhar o que o servidor já decidiu — ver
 * `resources/views/layouts/sessao.blade.php`, que é quem os chama.
 */
class SessaoController extends Controller
{
    /**
     * A frase dita a quem volta ao login sem ter pedido para sair.
     *
     * Mora aqui porque são DOIS caminhos até a mesma tela: o encerramento
     * deliberado por ociosidade (abaixo) e o 419 de token vencido tratado em
     * `bootstrap/app.php`. Quem chega não tem como saber por qual dos dois
     * passou, e ler duas frases diferentes para o mesmo acontecimento é o
     * tipo de detalhe que faz a pessoa desconfiar do sistema.
     */
    public const AVISO_DE_EXPIRACAO = 'Sua sessão expirou por inatividade. Entre novamente.';

    /**
     * Quanto tempo ainda resta — e, por existir, renova o prazo.
     *
     * Toda requisição autenticada empurra o vencimento da sessão para frente;
     * esta não é exceção, é o propósito dela. É o que sustenta quem passa uma
     * hora preenchendo um formulário sem nunca falar com o servidor: sem este
     * ponto, a sessão morreria por baixo de alguém que não parou um minuto.
     */
    public function estado(Request $request): JsonResponse
    {
        return response()->json([
            // Em milissegundos desde a época, que é o que o `Date.now()` do
            // navegador compara sem conversão nenhuma no meio.
            'expira_em' => now()->addMinutes((int) config('session.lifetime'))->getTimestampMs(),
        ]);
    }

    /**
     * Encerra a sessão e devolve ao login dizendo por quê.
     *
     * Chamado pela própria tela quando o relógio de ociosidade estoura, e
     * também quando um `fetch` recebe 401/419 — nesse segundo caso a sessão já
     * morreu, o token que acompanha o envio já não vale, e a resposta sai pelo
     * tratamento de 419 do `bootstrap/app.php`, que leva ao mesmo lugar com a
     * mesma frase. Os dois caminhos terminam igual de propósito: a tela não
     * precisa descobrir em qual deles está.
     */
    public function encerrar(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->withErrors(['sessao' => self::AVISO_DE_EXPIRACAO]);
    }
}
