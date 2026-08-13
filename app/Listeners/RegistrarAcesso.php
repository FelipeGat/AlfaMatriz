<?php

namespace App\Listeners;

use App\Models\Auditoria;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * O rastro de quem entrou, saiu e tentou.
 *
 * Nos eventos do Laravel, e não no `AuthenticatedSessionController`, porque a
 * entrada tem mais de uma porta: o formulário de login, o "lembrar de mim" que
 * revive a sessão pelo cookie e o `Auth::login()` de qualquer comando. Só a
 * primeira passa pelo controller — as outras entrariam sem deixar linha, que é
 * justamente o tipo de entrada sobre a qual alguém vai perguntar.
 *
 * Nada aqui grava senha. Ver `recusada()`.
 */
class RegistrarAcesso
{
    public function entrou(Login $evento): void
    {
        Auditoria::registrar(
            recurso: 'acesso',
            acao: 'entrou',
            alvo: $evento->user,
            descricao: $evento->user->email,
            ator: $evento->user,
        );
    }

    public function saiu(Logout $evento): void
    {
        // A saída pode não ter quem sair: a sessão que expira sozinha e o
        // `logout` chamado sem ninguém autenticado disparam o evento com o
        // usuário nulo. Sem linha nenhuma nesse caso — "Sistema saiu" seria
        // uma frase sobre coisa nenhuma.
        if (! $evento->user) {
            return;
        }

        Auditoria::registrar(
            recurso: 'acesso',
            acao: 'saiu',
            alvo: $evento->user,
            descricao: $evento->user->email,
            // O ator vem do evento, e não do `auth()`: quando isto roda, o
            // guard já desmontou a sessão. Ler o usuário de lá funciona hoje
            // por causa da ordem interna de `SessionGuard::logout()`, e é
            // exatamente o tipo de detalhe que muda de versão sem aviso.
            ator: $evento->user,
        );
    }

    /**
     * Senha recusada.
     *
     * `$evento->credentials` traz a senha digitada, e ela NÃO entra aqui de
     * forma alguma — nem no antes/depois, nem na descrição. Senha errada num
     * sistema é quase sempre a senha CERTA de outro, e uma tabela que ninguém
     * apaga é o pior lugar do mundo para ela descansar em texto puro.
     *
     * O e-mail entra: é o que responde se alguém está batendo numa conta que
     * existe ou varrendo endereços no escuro. Ele vai no nome do ator porque
     * pode não haver conta nenhuma por trás dele.
     */
    public function recusada(Failed $evento): void
    {
        $email = (string) ($evento->credentials['email'] ?? 'desconhecido');

        Auditoria::registrar(
            recurso: 'acesso',
            acao: 'recusado',
            // O alvo é a conta QUANDO ela existe. Sem isto, a linha do tempo de
            // um usuário não mostraria as tentativas contra ele — que é
            // metade da razão de registrar a recusa.
            alvo: $evento->user,
            descricao: $evento->user ? 'conta existente' : 'nenhuma conta com este e-mail',
            ator: $email,
        );
    }

    /**
     * Estouro do limite de tentativas.
     *
     * Linha própria, e não só as recusas que a antecederam: as recusas contam
     * o que foi tentado, esta conta o que o sistema FEZ a respeito. É também a
     * única que explica por que a pessoa certa, com a senha certa, passou os
     * próximos minutos sem conseguir entrar.
     */
    public function bloqueado(Lockout $evento): void
    {
        Auditoria::registrar(
            recurso: 'acesso',
            acao: 'bloqueado',
            descricao: 'tentativas demais em sequência',
            ator: (string) $evento->request->input('email', 'desconhecido'),
        );
    }
}
