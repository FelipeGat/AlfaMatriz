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

        // Falha da rodada sai com 0 de propósito (T-482): o serviço já a
        // gravou no log com o motivo — WARNING se isolada, ERROR se repetida —
        // e o exit 1 só acrescentaria o ERROR genérico do agendador.
        if ($r['erro']) {
            $this->error($r['erro']);
            $this->line($r['persistente']
                ? "Falhou {$r['falhas_seguidas']} rodadas seguidas: registrado como erro."
                : "Falha {$r['falhas_seguidas']} de ".ReconciliacaoDoMonitor::FALHAS_PARA_ERRO.' antes de virar erro; a próxima rodada tenta de novo.');
        }

        return self::SUCCESS;
    }
}
