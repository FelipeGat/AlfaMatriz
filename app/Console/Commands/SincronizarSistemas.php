<?php

namespace App\Console\Commands;

use App\Models\Sistema;
use App\Services\SincronizadorSistemaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Sinaliza o fim do ensaio para desfazer a transação. Não é erro. */
final class EnsaioConcluido extends \RuntimeException {}

/**
 * Puxa o retrato de cada sistema integrado para a Matriz.
 *
 * Sem `--sistema`, percorre todos os que declaram a capacidade `sincroniza` e
 * estão configurados. Um sistema fora do ar não pode interromper os outros: a
 * falha é por sistema, o relatório sai por sistema, e o comando só sai com
 * erro no final — assim o agendamento horário não perde o AlfaGym porque o
 * AlfaControl estava em manutenção.
 */
class SincronizarSistemas extends Command
{
    protected $signature = 'alfa:sincronizar-sistemas
                            {--sistema= : slug de um sistema específico (padrão: todos os integráveis)}
                            {--simular : mostra o que faria e desfaz tudo ao final}';

    protected $description = 'Puxa revendas, clientes e licenças dos sistemas integrados para a matriz';

    /** O nome antigo continua valendo: cron manual e runbook não quebram. */
    protected $aliases = ['app:sincronizar-alfagym'];

    public function handle(): int
    {
        $sistemas = $this->sistemasAlvo();

        if ($sistemas->isEmpty()) {
            $this->error($this->option('sistema')
                ? "Sistema '{$this->option('sistema')}' não encontrado."
                : 'Nenhum sistema integrável: verifique capacidade, endereço e chave.');

            return self::FAILURE;
        }

        if (! $this->option('simular')) {
            return $this->percorrer($sistemas);
        }

        // Ensaio: roda igual, relata igual e desfaz tudo. É o portão da virada
        // de um sistema novo — ler este relatório é o que separa "ancorou o que
        // já existia" de "duplicou a base inteira".
        $this->warn('ENSAIO: nada será gravado.');

        $codigo = self::SUCCESS;

        try {
            DB::transaction(function () use ($sistemas, &$codigo) {
                $codigo = $this->percorrer($sistemas);

                // A única saída que garante o rollback: sair pelo `return`
                // confirmaria a transação e gravaria o ensaio.
                throw new EnsaioConcluido;
            });
        } catch (EnsaioConcluido) {
            $this->warn('ENSAIO concluído: nada foi gravado.');
        }

        return $codigo;
    }

    /** @param  \Illuminate\Support\Collection<int, Sistema>  $sistemas */
    private function percorrer(\Illuminate\Support\Collection $sistemas): int
    {
        $falhou = false;

        foreach ($sistemas as $sistema) {
            $this->line("<options=bold>{$sistema->nome}</>");

            $resultado = (new SincronizadorSistemaService($sistema))->sincronizar();

            if (! $resultado['ok']) {
                // Nomeia quem falhou: com vários sistemas, "respondeu 500" sem
                // dono manda o suporte investigar o servidor errado.
                $this->error("  {$resultado['motivo']}");
                $falhou = true;

                continue;
            }

            $this->relatar($resultado['resumo']);
        }

        return $falhou ? self::FAILURE : self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, Sistema> */
    private function sistemasAlvo(): \Illuminate\Support\Collection
    {
        if ($slug = $this->option('sistema')) {
            return Sistema::query()->where('slug', $slug)->get();
        }

        return Sistema::comCapacidade('sincroniza')->get()
            ->filter(fn (Sistema $sistema) => $sistema->integravel())
            ->values();
    }

    /** @param  array<string, mixed>  $resumo */
    private function relatar(array $resumo): void
    {
        $this->info(sprintf(
            '  Revendas: %d criadas, %d atualizadas.',
            $resumo['revendas']['criadas'],
            $resumo['revendas']['atualizadas']
        ));
        $this->info(sprintf(
            '  Clientes: %d criados, %d atualizados.',
            $resumo['clientes']['criados'],
            $resumo['clientes']['atualizados']
        ));
        $this->info(sprintf('  Licenças: %d aplicadas.', $resumo['licencas']['atualizadas']));
        $this->info(sprintf('  Uso: %d clientes medidos.', $resumo['uso']['medidos']));
    }
}
