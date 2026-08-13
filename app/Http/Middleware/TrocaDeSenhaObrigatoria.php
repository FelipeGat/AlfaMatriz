<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quem entrou com uma senha entregue por outra pessoa troca antes de usar o
 * painel.
 *
 * A tela de usuários gera a senha e a mostra uma única vez, para o
 * administrador repassar — o que significa que ela passa por um canal que não
 * é da pessoa (mensagem, papel, voz). O `primeiro_acesso` é o que garante que
 * essa senha compartilhada tenha vida curta.
 *
 * As isenções não são conveniência: sem elas, a própria tela de troca seria
 * redirecionada para si mesma e o painel viraria um laço do qual não se sai
 * nem saindo — por isso `logout` também está na lista.
 */
class TrocaDeSenhaObrigatoria
{
    public function handle(Request $request, Closure $next): Response
    {
        $isento = $request->routeIs('senha.primeiro-acesso', 'senha.primeiro-acesso.update', 'logout', 'healthz');

        if ($request->user()?->primeiro_acesso && ! $isento) {
            return redirect()->route('senha.primeiro-acesso');
        }

        return $next($request);
    }
}
