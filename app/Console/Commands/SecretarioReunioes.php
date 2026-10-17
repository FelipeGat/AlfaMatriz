<?php

namespace App\Console\Commands;

use App\Services\Secretario\Reunioes;
use Illuminate\Console\Command;

/** Libera as transcrições presas e falha as que estouraram as tentativas (apagando o áudio). */
class SecretarioReunioes extends Command
{
    protected $signature = 'secretario:reunioes';

    protected $description = 'Libera transcrições e análises de reunião presas do Secretário';

    public function handle(Reunioes $reunioes): int
    {
        if ($n = $reunioes->liberarPresas()) {
            $this->info("{$n} reunião(ões) liberada(s) ou encerrada(s).");
        }
        if ($n = $reunioes->expirarParados()) {
            $this->info("{$n} áudio(s) sem transcrição apagado(s) depois de ".Reunioes::EXPIRA_DIAS.' dias.');
        }

        return self::SUCCESS;
    }
}
