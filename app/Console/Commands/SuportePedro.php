<?php

namespace App\Console\Commands;

use App\Services\Suporte\Cobranca;
use App\Services\Suporte\Pedro;
use Illuminate\Console\Command;

/** O Pedro a cada minuto (docs/specs/suporte-pedro-whatsapp): a fila da abertura e a resposta de reserva. */
class SuportePedro extends Command
{
    protected $signature = 'suporte:pedro';

    protected $description = 'Põe na fila quem escreveu fora do horário, responde sem IA o pedido que venceu e cobra os atendimentos críticos';

    public function handle(Pedro $pedro, Cobranca $cobranca): int
    {
        $vencidos = $pedro->vencidos();
        $enfileirados = $pedro->aguardando();
        $cobrados = $cobranca->rodar();
        if ($vencidos || $enfileirados || $cobrados) {
            $this->info("Pedro: {$enfileirados} na fila, {$vencidos} respondido(s) sem IA, {$cobrados} atendimento(s) cobrado(s).");
        }

        return self::SUCCESS;
    }
}
