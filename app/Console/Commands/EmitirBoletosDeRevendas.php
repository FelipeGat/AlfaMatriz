<?php

namespace App\Console\Commands;

use App\Services\MercadoPago\BoletoDaRevenda;
use App\Services\MercadoPago\ErroDoMercadoPago;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Emite o boleto das cobranças de licenciamento pendentes das revendas "por
 * cliente" (docs/specs/cobranca-revendas-boleto). Roda todo dia às 08:00: na
 * prática, no dia 01, logo depois do fechamento; se falhar, tenta no dia seguinte.
 */
class EmitirBoletosDeRevendas extends Command
{
    protected $signature = 'cobrancas:emitir-boletos-revendas';

    protected $description = 'Emite pelo Mercado Pago o boleto das cobranças das revendas por cliente';

    public function handle(BoletoDaRevenda $boletos): int
    {
        foreach ($boletos->paraEmitir() as $c) {
            if ($motivo = $boletos->impedimento($c)) {
                $c->forceFill(['mp_erro' => mb_substr($motivo, 0, 255)])->save();
                $this->line("#{$c->id}: {$motivo}");

                continue;
            }
            try {
                $boletos->emitir($c);
                $this->info("#{$c->id}: boleto emitido");
            } catch (ErroDoMercadoPago $e) {
                $c->forceFill(['mp_erro' => mb_substr($e->getMessage(), 0, 255)])->save();
                Log::warning('Boleto não emitido', ['cobranca' => $c->id, 'erro' => $e->getMessage()]);
                $this->warn("#{$c->id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
