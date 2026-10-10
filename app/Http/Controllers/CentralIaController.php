<?php

namespace App\Http\Controllers;

use App\Services\CentralBackend;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CentralIaController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(config('central_ia.ui_enabled') && config('central_ia.enabled'), 404);

        // Render the shell locally even if Central or every executor is offline.
        return response()->view('central-ia.index', ['canSend' => $request->user()->canPermissao('central_ia', 'incluir')])
            ->header('Cache-Control', 'no-store, private');
    }

    public function messages(Request $request, CentralBackend $backend)
    {
        return $this->respond(function () use ($request, $backend) {
            $this->only($request, ['before', 'after']);
            $data = $request->validate(['before' => 'nullable|integer|min:1', 'after' => 'nullable|integer|min:0']);
            abort_if(isset($data['before'], $data['after']), 422);
            $result = $backend->call($request, 'history', $data);
            // Only public message fields: never forward account/session/technical metadata.
            abort_unless(is_array($result['messages'] ?? null) && is_array($result['deliveries'] ?? null)
                && is_bool($result['has_more'] ?? null) && is_bool($result['telegram_paired'] ?? null), 503);
            $fields = array_flip(['id', 'client_id', 'actor_type', 'actor_name', 'kind', 'origin', 'content', 'created_at', 'delivery_status']);

            return [
                'messages' => array_map(fn ($message) => array_intersect_key($message, $fields), $result['messages']),
                'deliveries' => array_map(fn ($delivery) => array_intersect_key($delivery, array_flip(['id', 'status'])), $result['deliveries']),
                'has_more' => $result['has_more'], 'telegram_paired' => $result['telegram_paired'],
            ];
        });
    }

    public function send(Request $request, CentralBackend $backend)
    {
        return $this->respond(function () use ($request, $backend) {
            $this->only($request, ['content', 'client_id']);
            $data = $request->validate(['content' => 'required|string|max:3000', 'client_id' => 'required|uuid']);
            abort_if(trim($data['content']) === '', 422);
            $result = $backend->call($request, 'send', $data);

            return ['id' => $result['id']];
        }, 201);
    }

    private function only(Request $request, array $fields): void
    {
        abort_unless(config('central_ia.ui_enabled') && config('central_ia.enabled'), 404);
        abort_if(array_diff(array_keys($request->except('_token')), $fields) !== [], 422);
    }

    private function respond(callable $operation, int $status = 200)
    {
        try {
            return response()->json($operation(), $status)->header('Cache-Control', 'no-store, private');
        } catch (HttpException $e) {
            $messages = [401 => 'Sua sessão precisa ser renovada.', 403 => 'Seu acesso à Central não está autorizado.',
                404 => 'A Central não está habilitada para esta conta.', 422 => 'Confira os dados da mensagem.',
                429 => 'Limite temporário atingido. Aguarde antes de tentar novamente.'];

            return response()->json(['message' => $messages[$e->getStatusCode()] ?? 'A Central está temporariamente indisponível. Sua mensagem pode ser reenviada com segurança.'], $e->getStatusCode())
                ->header('Cache-Control', 'no-store, private');
        }
    }
}
