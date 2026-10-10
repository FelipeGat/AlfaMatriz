<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TelegramClient
{
    public function call(string $method, array $payload = [], int $timeout = 20): array
    {
        $token = config('central.telegram_token');
        if (! config('central.telegram_enabled') || ! is_string($token) || $token === '') {
            return ['ok' => false, 'error' => 'not_configured'];
        }
        try {
            $response = Http::connectTimeout(5)->timeout($timeout)->post('https://api.telegram.org/bot'.$token.'/'.$method, $payload);
            $body = $response->json();
            if ($response->successful() && is_array($body) && ($body['ok'] ?? false) === true) {
                return ['ok' => true, 'result' => $body['result'] ?? []];
            }
            // 5xx or invalid response may follow a successful delivery: do not retry sendMessage.
            $uncertain = $response->serverError() || ! is_array($body) || ! array_key_exists('ok', $body) || (($body['ok'] ?? false) === true);

            return ['ok' => false, 'error' => $uncertain ? 'uncertain' : 'telegram_rejected'];
        } catch (\Throwable) {
            // Exception text contains the bot token in its URL. Never propagate or log it.
            return ['ok' => false, 'error' => 'uncertain'];
        }
    }
}
