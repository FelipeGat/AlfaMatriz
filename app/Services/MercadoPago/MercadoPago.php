<?php

namespace App\Services\MercadoPago;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A API do Mercado Pago, só o que o boleto da revenda usa (docs/specs/
 * cobranca-revendas-boleto): emitir (`POST /v1/payments`, bolbradesco) e
 * consultar (`GET /v1/payments/{id}`). Mesmo caminho do Gestor.
 */
class MercadoPago
{
    public function configurado(): bool
    {
        return filled(config('services.mercadopago.access_token'));
    }

    /** @return array<string, mixed> o pagamento criado */
    public function criarPagamento(array $corpo, string $chaveDeIdempotencia): array
    {
        return $this->resposta(fn () => $this->pedido()->withHeaders(['X-Idempotency-Key' => $chaveDeIdempotencia])->post('/v1/payments', $corpo));
    }

    /** @return array<string, mixed> */
    public function pagamento(string $id): array
    {
        return $this->resposta(fn () => $this->pedido()->get('/v1/payments/'.rawurlencode($id)));
    }

    private function pedido(): PendingRequest
    {
        if (! $this->configurado()) {
            throw new ErroDoMercadoPago('O Mercado Pago não está configurado no servidor (MERCADOPAGO_ACCESS_TOKEN).');
        }

        return Http::baseUrl('https://api.mercadopago.com')->withToken(config('services.mercadopago.access_token'))
            ->acceptJson()->asJson()->timeout(30)->connectTimeout(10);
    }

    private function resposta(callable $fazer): array
    {
        try {
            $r = $fazer();
        } catch (ConnectionException) {
            throw new ErroDoMercadoPago('O Mercado Pago não respondeu.');
        }
        if ($r->successful() && is_array($r->json())) {
            return $r->json();
        }
        $motivo = collect($r->json('cause') ?? [])->pluck('description')->filter()->first() ?? $r->json('message') ?? 'erro '.$r->status();
        throw new ErroDoMercadoPago(match (true) {
            $r->status() === 401 => 'O Mercado Pago recusou a credencial do servidor.',
            default => 'O Mercado Pago recusou: '.Str::limit(strip_tags((string) $motivo), 160),
        });
    }
}
