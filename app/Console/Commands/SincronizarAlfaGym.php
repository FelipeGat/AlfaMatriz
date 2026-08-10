<?php

namespace App\Console\Commands;

use App\Models\Sistema;
use App\Services\SincronizadorSistemaService;
use Illuminate\Console\Command;

class SincronizarAlfaGym extends Command
{
    protected $signature = 'app:sincronizar-alfagym {--sistema=alfagym : slug do sistema}';

    protected $description = 'Puxa revendas, clientes e licenças do AlfaGym para a matriz';

    public function handle(): int
    {
        $sistema = Sistema::query()->where('slug', $this->option('sistema'))->first();

        if (! $sistema) {
            $this->error("Sistema '{$this->option('sistema')}' não encontrado.");

            return self::FAILURE;
        }

        $resultado = (new SincronizadorSistemaService($sistema))->sincronizar();

        if (! $resultado['ok']) {
            $this->error($resultado['motivo']);

            return self::FAILURE;
        }

        $resumo = $resultado['resumo'];

        $this->info(sprintf(
            'Revendas: %d criadas, %d atualizadas.',
            $resumo['revendas']['criadas'],
            $resumo['revendas']['atualizadas']
        ));
        $this->info(sprintf(
            'Clientes: %d criados, %d atualizados.',
            $resumo['clientes']['criados'],
            $resumo['clientes']['atualizados']
        ));
        $this->info(sprintf('Licenças: %d aplicadas.', $resumo['licencas']['atualizadas']));

        return self::SUCCESS;
    }
}
