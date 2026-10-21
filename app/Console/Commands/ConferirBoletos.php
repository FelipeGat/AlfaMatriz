<?php

namespace App\Console\Commands;

use App\Services\MercadoPago\BoletoDaRevenda;
use App\Services\MercadoPago\ErroDoMercadoPago;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Confere no Mercado Pago os boletos em aberto e dá baixa no que foi pago
 * (docs/specs/cobranca-revendas-boleto). De hora em hora: a baixa vem da
 * consulta, e não de aviso de fora.
 */
class ConferirBoletos extends Command
{
    protected $signature = 'cobrancas:conferir-boletos';

    protected $description = 'Confere no Mercado Pago os boletos em aberto e dá baixa no que foi pago';

    public function handle(BoletoDaRevenda $boletos): int
    {
        foreach ($boletos->paraConferir() as $c) {
            try {
                $this->line("#{$c->id}: ".$boletos->conferir($c));
            } catch (ErroDoMercadoPago $e) {
                Log::warning('Boleto não conferido', ['cobranca' => $c->id, 'erro' => $e->getMessage()]);
                $this->warn("#{$c->id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
