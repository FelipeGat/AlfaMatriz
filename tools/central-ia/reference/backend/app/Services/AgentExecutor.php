<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class AgentExecutor
{
    public function run(int $id, array $messages): array
    {
        try {
            $key = trim(file_get_contents(config('central.executor_key_file')));
            if ($key === '') {
                return ['ok' => false, 'error' => 'executor_not_configured'];
            }
            $response = Http::withToken($key)->connectTimeout(5)->timeout(config('central.agent_timeout'))
                ->post(config('central.executor_url').'/run', ['run_id' => $id, 'mode' => 'mock', 'messages' => $messages]);
            $data = $response->json();
            if (! $response->successful() || ! is_array($data) || ($data['mode'] ?? null) !== 'mock' || ! is_string($data['text'] ?? null) || mb_strlen($data['text']) > 3000 || trim($data['text']) === '') {
                return ['ok' => false, 'error' => 'executor_invalid_response'];
            }

            return ['ok' => true, 'text' => $data['text'], 'usage' => ['available' => false, 'reason' => 'simulation']];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'executor_uncertain'];
        }
    }
}
