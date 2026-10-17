<?php

namespace App\Console\Commands;

use App\Services\Secretario\Acompanhamento\Envio;
use App\Services\Secretario\Acompanhamento\Motor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Acompanhamento proativo (docs/specs/secretario-acompanhamento): roda as
 * regras de cada conta, decide o resumo pela política dela e entrega no
 * Telegram o que ficou pendente. Sem IA. Só contagens no log.
 */
class SecretarioAcompanhar extends Command
{
    protected $signature = 'secretario:acompanhar';

    protected $description = 'Confere as regras de acompanhamento do Secretário e envia os resumos devidos';

    public function handle(Motor $motor, Envio $envio): int
    {
        $agora = CarbonImmutable::now();
        $resumidos = 0;
        foreach ($motor->contas() as $user) {
            $motor->rodar($user, $agora);
            $resumidos += $envio->preparar($user, $agora) > 0 ? 1 : 0;
        }
        $entregues = $envio->entregarPendentes();
        if ($resumidos || $entregues) {
            $this->info("{$resumidos} resumo(s) preparado(s), {$entregues} entregue(s) no Telegram.");
        }

        return self::SUCCESS;
    }
}
