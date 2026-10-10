<?php

namespace App\Services\CentralIa;

use Illuminate\Support\Facades\Http;

/**
 * A única porta para a API do bot da Central.
 *
 * O token viaja na URL (`/bot<token>/método`), e a mensagem de exceção do
 * cliente HTTP repete a URL inteira: nada daqui é logado nem repassado, e quem
 * chama recebe só um código.
 *
 * Os códigos separam o que importa para não dobrar mensagem:
 *  - `recusada`: o Telegram respondeu que não — não chegou, pode corrigir e tentar;
 *  - `incerta`: rede caiu ou 5xx — PODE ter chegado, não reenviar sozinho;
 *  - `conflito`: 409, outro consumidor (ou webhook) está lendo o mesmo bot;
 *  - `limite`: 429, não chegou; tentar depois.
 */
class TelegramApi
{
    public function configurado(): bool
    {
        return filled(config('central_ia.telegram.token'));
    }

    /**
     * @return array{ok: bool, resultado?: mixed, erro?: string, esperar?: int}
     */
    public function chamar(string $metodo, array $dados = [], int $timeout = 15): array
    {
        if (! $this->configurado()) {
            return ['ok' => false, 'erro' => 'nao_configurado'];
        }

        try {
            $resposta = Http::connectTimeout(5)->timeout($timeout)
                ->post('https://api.telegram.org/bot'.config('central_ia.telegram.token').'/'.$metodo, $dados);
        } catch (\Throwable) {
            return ['ok' => false, 'erro' => 'incerta'];
        }

        $corpo = $resposta->json();

        if ($resposta->successful() && is_array($corpo) && ($corpo['ok'] ?? false) === true) {
            return ['ok' => true, 'resultado' => $corpo['result'] ?? null];
        }

        return match (true) {
            $resposta->status() === 409 => ['ok' => false, 'erro' => 'conflito'],
            $resposta->status() === 429 => ['ok' => false, 'erro' => 'limite', 'esperar' => (int) ($corpo['parameters']['retry_after'] ?? 5)],
            $resposta->clientError() => ['ok' => false, 'erro' => 'recusada'],
            default => ['ok' => false, 'erro' => 'incerta'],
        };
    }
}
