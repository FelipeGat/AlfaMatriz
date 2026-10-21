<?php

namespace App\Console\Commands;

use App\Models\SecretarioEmailConta;
use App\Services\Secretario\Email\LeitorDeEmails;
use Illuminate\Console\Command;

/**
 * Lê as caixas IMAP do Secretário (só leitura) e apaga o que passou de 30
 * dias. Uma caixa com erro não para as outras. Só contagens no log.
 */
class SecretarioEmails extends Command
{
    protected $signature = 'secretario:emails';

    protected $description = 'Lê as caixas de e-mail do Secretário (somente leitura) e limpa as mensagens antigas';

    public function handle(LeitorDeEmails $leitor): int
    {
        foreach (SecretarioEmailConta::orderBy('id')->get() as $conta) {
            $u = \App\Models\User::find($conta->user_id);
            if (! $u || ! $u->ativo || ! $u->canPermissao('secretario', 'ler')) {
                continue;
            }
            $r = $leitor->sincronizar($conta);
            $this->line("caixa #{$conta->id}: ".($r['ok'] ? $r['novas'].' nova(s)' : 'falhou ('.$r['erro'].')'));
        }
        if ($n = $leitor->limpar()) {
            $this->line("{$n} mensagem(ns) com mais de 30 dias removida(s).");
        }

        return self::SUCCESS;
    }
}
