<?php

namespace App\Console\Commands;

use App\Models\SecretarioGoogleConta;
use App\Services\Secretario\SincronizadorGoogle;
use Illuminate\Console\Command;

/**
 * Lê as agendas Google conectadas ao Secretário (de ontem a +30 dias) para o
 * cache mínimo. Só contagens no log — nunca título de evento nem token.
 */
class SecretarioSincronizarGoogle extends Command
{
    protected $signature = 'secretario:sincronizar-google';

    protected $description = 'Sincroniza as agendas Google conectadas ao Secretário';

    public function handle(SincronizadorGoogle $sincronizador): int
    {
        foreach (SecretarioGoogleConta::where('situacao', SecretarioGoogleConta::CONECTADA)->get() as $conta) {
            $r = $sincronizador->sincronizar($conta);
            $this->line("conta #{$conta->id}: ".($r['ok'] ? ($r['eventos'] ?? 0).' evento(s)' : 'falhou ('.$r['erro'].')'));
        }

        return self::SUCCESS;
    }
}
