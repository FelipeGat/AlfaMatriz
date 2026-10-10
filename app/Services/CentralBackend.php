<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CentralBackend
{
    public function call(Request $request, string $operation, array $payload = []): array
    {
        // Session data must remain on the server, never in a cookie-backed session.
        abort_unless(in_array(config('session.driver'), ['file', 'database', 'redis', 'array'], true), 503, 'central_configuration_unavailable');
        $snapshot = app(CentralIdentitySnapshot::class)->forUser($request->user());
        $required = $operation === 'send' ? 'send' : 'read';
        abort_unless(in_array($required, $snapshot['operations'], true), 403, 'access_denied');
        $grant = $request->session()->get('central_ia.backend');
        if (! is_array($grant) || ($grant['subject'] ?? null) !== $snapshot['subject']
            || ($grant['epoch'] ?? null) !== $snapshot['session_epoch'] || ($grant['expires'] ?? 0) <= now()->timestamp) {
            $flow = bin2hex(random_bytes(32));
            $ticket = app(CentralIdentityTickets::class)->issue($request->user(), $flow);
            $result = $this->transport('session', ['ticket' => $ticket['ticket'], 'flow' => $flow]);
            if (! is_string($result['session_token'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $result['session_token'])
                || ! is_int($result['expires_in'] ?? null) || $result['expires_in'] < 1 || $result['expires_in'] > 900) {
                throw new HttpException(503, 'central_unavailable');
            }
            $grant = ['ciphertext' => Crypt::encryptString($result['session_token']), 'subject' => $snapshot['subject'], 'epoch' => $snapshot['session_epoch'],
                'expires' => now()->addSeconds($result['expires_in'] - 10)->timestamp];
            $request->session()->put('central_ia.backend', $grant);
        }
        try {
            $token = Crypt::decryptString($grant['ciphertext']);
        } catch (\Throwable) {
            $request->session()->forget('central_ia.backend');
            throw new HttpException(503, 'central_unavailable');
        }
        try {
            return $this->transport($operation, $payload, $token);
        } catch (HttpException $e) {
            if (in_array($e->getStatusCode(), [401, 403], true)) {
                $request->session()->forget('central_ia.backend');
            }
            // Never retry an ambiguous send with another id. The browser retains its id.
            throw $e;
        }
    }

    private function transport(string $operation, array $payload, ?string $session = null): array
    {
        $url = config('central_ia.backend_url');
        $keyFile = config('central_ia.backend_key_file');
        $parts = is_string($url) ? parse_url($url) : false;
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['query'])
            || isset($parts['fragment']) || ! is_string($keyFile) || ! is_readable($keyFile)) {
            throw new HttpException(503, 'central_configuration_unavailable');
        }
        $key = trim(file_get_contents($keyFile));
        abort_unless(strlen($key) >= 32, 503, 'central_configuration_unavailable');
        try {
            $client = Http::acceptJson()->withToken($key)->withoutRedirecting()->connectTimeout(2)->timeout(8)
                ->withOptions(['verify' => config('central_ia.backend_ca_file') ?: true]);
            if ($session !== null) {
                $client = $client->withHeaders(['X-Central-Session' => $session]);
            }
            $response = $client->post(rtrim($url, '/').'/internal/alfa/'.$operation, $payload);
            if (! $response->successful()) {
                Log::warning('central_transport_http_failure', ['operation' => $operation, 'status' => $response->status()]);
                $status = in_array($response->status(), [401, 403, 404, 422, 429], true) ? $response->status() : 503;
                // Do not deserialize exception bodies or expose the upstream response.
                throw new HttpException($status, $status === 403 ? 'access_denied' : 'central_unavailable');
            }
            $data = $response->json();
            if (! is_array($data)) {
                throw new \RuntimeException;
            }

            return $data;
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            Log::warning('central_transport_unavailable', ['operation' => $operation]);
            throw new HttpException(503, 'central_unavailable');
        }
    }
}
