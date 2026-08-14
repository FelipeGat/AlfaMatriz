<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autoriza por perfil: o recurso vem da rota (ex.: middleware
 * `permissao:clientes`) e a ação é inferida do verbo HTTP — GET lê, POST
 * inclui, PUT/PATCH edita, DELETE exclui. Também aceita
 * `permissao:clientes,editar` para fixar a ação.
 *
 * `PUT`/`PATCH` caíam em `incluir` junto com o `POST`, e por isso conceder
 * "cadastrar" concedia "reescrever tudo o que já está cadastrado" — a grade de
 * perfis não tinha como dizer o contrário porque a distinção não existia. A
 * separação é de 15/08/2026, a pedido do dono do produto: poder total só do
 * perfil Administrador.
 *
 * O verbo resolve o caso comum e erra num ponto que importa: **muitas edições
 * deste sistema são `POST`** — mover tarefa, mover lead, dar baixa em cobrança,
 * bloquear licença. Nenhuma delas cria coisa alguma. Por isso essas rotas fixam
 * a ação no segundo argumento em vez de deixá-la ser inferida; a inferência é
 * atalho para o caso óbvio, não a regra.
 *
 * Quem não tem a permissão devolve 403.
 */
class ChecarPermissao
{
    public function handle(Request $request, Closure $next, string $recurso, ?string $acao = null): Response
    {
        $acao ??= match ($request->method()) {
            'GET', 'HEAD' => 'ler',
            'POST' => 'incluir',
            'PUT', 'PATCH' => 'editar',
            'DELETE' => 'excluir',
            default => 'ler',
        };

        if (! $request->user()?->canPermissao($recurso, $acao)) {
            abort(403, 'Você não tem permissão para executar esta ação.');
        }

        return $next($request);
    }
}
