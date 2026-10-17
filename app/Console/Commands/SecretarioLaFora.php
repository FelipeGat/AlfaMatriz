<?php

namespace App\Console\Commands;

use App\Services\Secretario\LaFora;
use Illuminate\Console\Command;

/**
 * Dólar, clima, Flamengo e notícias do Meu Dia (docs/specs/secretario-
 * central-do-dia): fontes públicas, a cada 30 min, para o cache. Só GET;
 * nada de nenhuma conta sai daqui.
 */
class SecretarioLaFora extends Command
{
    protected $signature = 'secretario:la-fora';

    protected $description = 'Atualiza dólar, clima, Flamengo e notícias do Meu Dia';

    public function handle(LaFora $laFora): int
    {
        $r = $laFora->buscar();
        $this->info(collect($r)->map(fn ($b, $k) => $k.':'.($b ? 'ok' : 'sem dado'))->implode(' '));

        return self::SUCCESS;
    }
}
