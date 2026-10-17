<?php

namespace App\Console\Commands;

use App\Services\Secretario\Despachante;
use Illuminate\Console\Command;

/**
 * Entrega os lembretes do Secretário que venceram (sino + Telegram). Agendado
 * a cada minuto: o atraso máximo de um aviso é a volta do cron (spec FR-012).
 * Não depende de fila, de conversa aberta nem da máquina do dono.
 */
class SecretarioDespachar extends Command
{
    protected $signature = 'secretario:despachar';

    protected $description = 'Entrega os lembretes do Secretário que venceram';

    public function handle(Despachante $despachante): int
    {
        $n = $despachante->despachar();
        if ($n > 0) {
            // Só a contagem: o texto dos lembretes não vai para o log (FR-005).
            $this->info("{$n} aviso(s) entregue(s).");
        }

        return self::SUCCESS;
    }
}
