<?php

namespace App\Console\Commands;

use App\Services\Suporte\BaseDoSuporte;
use Illuminate\Console\Command;

/**
 * A conversa que o Pedro atendeu sozinho e o cliente largou vira registro
 * "encerrado sem confirmação" para a equipe conferir (T-476; base-do-suporte.md).
 * De hora em hora basta: o prazo é a janela de 24 h do WhatsApp.
 */
class SuporteRegistros extends Command
{
    protected $signature = 'suporte:registros';

    protected $description = 'Propõe o registro das conversas que o Pedro atendeu sozinho e o cliente deixou sem resposta';

    public function handle(BaseDoSuporte $base): int
    {
        if ($n = $base->encerrarSemResposta()) {
            $this->info("{$n} registro(s) proposto(s) como encerrado sem confirmação.");
        }

        return self::SUCCESS;
    }
}
