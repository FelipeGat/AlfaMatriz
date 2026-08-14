<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Abre cada requisição com a permissão por resolver.
 *
 * `User::canPermissao()` guarda o que a conta pode na própria instância, para
 * não perguntar ao banco 17 vezes por página. O tempo de vida desse cache tem
 * de ser a REQUISIÇÃO: permissão que sobrevive a ela é permissão que não se
 * revoga — quem perde acesso continuaria entrando.
 *
 * Numa requisição HTTP comum isso já acontece sozinho, porque o guard monta um
 * objeto novo a cada vez. Mas "acontece sozinho" é uma suposição sobre o
 * framework, não uma garantia: em qualquer processo que segure o mesmo objeto
 * entre requisições — a suíte com `actingAs`, um servidor persistente — o
 * cache envelheceria em silêncio, e o sintoma seria acesso indevido, que é o
 * pior lugar para se descobrir uma suposição errada.
 *
 * Por isso o esquecimento é EXPLÍCITO e mecânico, no começo de toda requisição.
 * Custa uma atribuição.
 *
 * Vai no fim do grupo `web` de propósito: antes da sessão iniciar não há
 * usuário para esquecer.
 */
class PermissoesDaRequisicao
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->user()?->esquecerPermissoes();

        return $next($request);
    }
}
