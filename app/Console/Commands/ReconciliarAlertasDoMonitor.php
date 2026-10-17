<?php

namespace App\Console\Commands;

use App\Services\Monitor\ReconciliacaoDoMonitor;
use Illuminate\Console\Command;

/**
 * Recupera os alertas do AlfaMonitor que o webhook não entregou (#399). Roda
 * a cada hora (`routes/console.php`); a regra mora em `ReconciliacaoDoMonitor`.
 */
class ReconciliarAlertasDoMonitor extends Command
{
    protected $signature = 'alfa:monitor-reconciliar
                            {--do-inicio : Confere tudo o que o monitor ainda guarda (7 dias), e não só o que veio depois do cursor}';

    protected $description = 'Busca na API do AlfaMonitor os alertas que o webhook não entregou';

    public function handle(ReconciliacaoDoMonitor $reconciliacao): int
    {
        if ($this->option('do-inicio')) {
            $reconciliacao->zerarCursor();
        }

        $r = $reconciliacao->reconciliar();

        if ($r['sem_rota']) {
            $this->warn('O AlfaMonitor ainda não tem a rota de notificações (/api/v1/notificacoes). Nada a fazer.');

            return self::SUCCESS;
        }

        $this->info("Recuperados: {$r['gravados']} · já recebidos: {$r['repetidos']} · ignorados: {$r['ignorados']} · cursor: {$r['cursor']}");

        if ($r['erro']) {
            $this->error($r['erro']);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
