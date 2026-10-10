<?php

namespace App\Http\Controllers;

use App\Contracts\IdentityGateway;
use App\Models\CentralIdentity;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\Identity\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AlfaBridgeController extends Controller
{
    public function session(Request $request, IdentityGateway $gateway)
    {
        $this->fields($request, ['ticket', 'flow']);
        $data = $request->validate(['ticket' => 'required|regex:/^[a-f0-9]{64}$/D', 'flow' => 'required|regex:/^[a-f0-9]{64}$/D']);
        try {
            $snapshot = $gateway->redeem($data['ticket'], $data['flow']);
        } catch (\Throwable) {
            abort(503, 'identity_authority_unavailable');
        }
        abort_unless(($snapshot['version'] ?? null) === 1 && ($snapshot['issuer'] ?? null) === 'alfamatriz'
            && ($snapshot['audience'] ?? null) === 'central-agentes' && is_string($snapshot['subject'] ?? null)
            && ($snapshot['active'] ?? false) === true && ($snapshot['ready'] ?? false) === true
            && is_int($snapshot['session_epoch'] ?? null) && $snapshot['session_epoch'] > 0
            && is_array($snapshot['operations'] ?? null) && in_array('read', $snapshot['operations'], true), 403);
        abort_unless(app(\App\Services\Identity\OwnerPolicy::class)->allows($snapshot['issuer'], $snapshot['subject']), 403);
        $token = bin2hex(random_bytes(32));
        DB::transaction(function () use ($snapshot, $token) {
            $identity = CentralIdentity::where('issuer', 'alfamatriz')->where('subject', $snapshot['subject'])->lockForUpdate()->first();
            abort_unless($identity && $identity->status === 'active', 403, 'identity_not_provisioned');
            $identity->update(['session_epoch' => $snapshot['session_epoch']]);
            DB::table('central_bridge_sessions')->insert(['owner_id' => $identity->user_id, 'identity_id' => $identity->id,
                'token_hash' => hash('sha256', $token), 'session_epoch' => $snapshot['session_epoch'],
                'expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(['session_token' => $token, 'expires_in' => 900])->header('Cache-Control', 'no-store, private');
    }

    public function history(Request $request, ConversationController $controller, ConversationService $service)
    {
        $this->fields($request, ['before', 'after']);
        $this->user($request, 'read');

        return $controller->messages($request, $service)->header('Cache-Control', 'no-store, private');
    }

    public function send(Request $request, ConversationService $service)
    {
        $this->fields($request, ['content', 'client_id']);
        $user = $this->user($request, 'send');
        $data = $request->validate(['content' => 'required|string|max:3000', 'client_id' => 'required|uuid']);
        abort_if(trim($data['content']) === '', 422);
        $message = $service->post($user, $data['content'], $data['client_id'], 'alfamatriz');

        return response()->json(['id' => $message->id], 201)->header('Cache-Control', 'no-store, private');
    }

    private function fields(Request $request, array $allowed): void
    {
        abort_if(array_diff(array_keys($request->all()), $allowed) !== [], 422, 'unexpected_fields');
    }

    private function user(Request $request, string $operation): User
    {
        $token = $request->header('X-Central-Session');
        abort_unless(is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token), 401);
        $session = DB::table('central_bridge_sessions')->where('token_hash', hash('sha256', $token))->first();
        abort_unless($session && ! $session->revoked_at && now()->lt($session->expires_at), 401);
        $user = User::findOrFail($session->owner_id);
        $identity = app(Access::class)->authorize($user, $operation, (int) $session->session_epoch);
        abort_unless($identity->id === $session->identity_id, 403);
        $request->setUserResolver(fn () => $user);

        return $user;
    }
}
