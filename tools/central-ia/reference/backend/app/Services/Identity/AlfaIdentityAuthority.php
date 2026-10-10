<?php

namespace App\Services\Identity;

use App\Contracts\IdentityGateway;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AlfaIdentityAuthority implements IdentityGateway
{
    public function inspect(string $subject): array
    {
        return $this->request(config('central.identity_authority_url'), ['subject' => $subject]);
    }

    public function redeem(string $ticket, string $flow): array
    {
        $inspect = parse_url(config('central.identity_authority_url'));
        $redeem = parse_url(config('central.identity_redeem_url'));
        if (! is_array($inspect) || ! is_array($redeem) || ($inspect['host'] ?? null) !== ($redeem['host'] ?? null)
            || ($inspect['port'] ?? 443) !== ($redeem['port'] ?? 443)) {
            throw new \RuntimeException('identity_authority_unavailable');
        }

        return $this->request(config('central.identity_redeem_url'), ['ticket' => $ticket, 'flow' => $flow]);
    }

    private function request(mixed $url, array $payload): array
    {
        $file = config('central.identity_authority_key_file');
        if (! is_string($url) || ! str_starts_with($url, 'https://') || ! is_string($file) || ! is_readable($file)) {
            throw new \RuntimeException('identity_authority_unavailable');
        }
        try {
            $key = trim(file_get_contents($file));
            if (strlen($key) < 32) {
                throw new \RuntimeException;
            }
            // No redirects, retries or exception bodies: secrets/URLs must not enter logs.
            $ca = config('central.identity_ca_file');
            $data = Http::acceptJson()->withToken($key)->withoutRedirecting()->connectTimeout(2)->timeout(5)
                ->withOptions(['verify' => $ca ?: true])->post($url, $payload)->throw()->json();
            if (! is_array($data) || ($data['version'] ?? null) !== 1 || ($data['issuer'] ?? null) !== 'alfamatriz'
                || ($data['audience'] ?? null) !== 'central-agentes') {
                throw new \RuntimeException;
            }

            return $data;
        } catch (\Throwable $failure) {
            $transportCode = null;
            $previous = $failure->getPrevious();
            if ($previous instanceof ConnectException) {
                $transportCode = $previous->getHandlerContext()['errno'] ?? null;
            }
            Log::warning('identity_authority_unavailable', [
                'category' => $failure instanceof ConnectionException ? 'transport'
                    : ($failure instanceof RequestException ? 'http' : 'contract'),
                'status' => $failure instanceof RequestException ? $failure->response->status() : null,
                'transport_code' => is_int($transportCode) ? $transportCode : null,
            ]);
            throw new \RuntimeException('identity_authority_unavailable');
        }
    }
}
