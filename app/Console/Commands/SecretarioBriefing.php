<?php

namespace App\Console\Commands;

use App\Services\Secretario\Briefings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * O briefing do dia de cada conta, no horário dela (docs/specs/secretario-
 * briefing), e a entrega no Telegram do que ficou pendente. A cada minuto;
 * a chave única por dia impede o segundo. Só contagens no log.
 */
class SecretarioBriefing extends Command
{
    protected $signature = 'secretario:briefing';

    protected $description = 'Gera e entrega o briefing diário do Secretário no horário de cada conta';

    public function handle(Briefings $briefings): int
    {
        $agora = CarbonImmutable::now();
        $gerados = 0;
        foreach ($briefings->devidos($agora) as $user) {
            // Erro de uma conta não deixa as outras (nem a entrega) sem briefing.
            try {
                $gerados += $briefings->gerar($user, $agora) ? 1 : 0;
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $entregues = $briefings->entregarPendentes();
        if ($gerados || $entregues) {
            $this->info("{$gerados} briefing(s) gerado(s), {$entregues} entregue(s).");
        }

        return self::SUCCESS;
    }
}
