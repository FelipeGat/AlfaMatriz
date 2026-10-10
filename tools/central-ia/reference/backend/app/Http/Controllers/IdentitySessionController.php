<?php

namespace App\Http\Controllers;

use App\Contracts\IdentityGateway;
use App\Models\CentralIdentity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IdentitySessionController extends Controller
{
    public function start(Request $request)
    {
        abort_unless(config('central.identity_bridge_enabled'), 404);
        abort_unless($request->isSecure(), 403, 'secure_transport_required');
        abort_if($request->except('_token') !== [], 422, 'unexpected_identity_fields');
        $flow = bin2hex(random_bytes(32));
        $request->session()->put('identity.flow', ['hash' => hash('sha256', $flow), 'expires_at' => now()->addSeconds(120)->timestamp]);

        return response()->json(['flow' => $flow, 'expires_in' => 120]);
    }

    public function complete(Request $request, IdentityGateway $gateway)
    {
        abort_unless(config('central.identity_bridge_enabled'), 404);
        abort_unless($request->isSecure(), 403, 'secure_transport_required');
        abort_if(array_diff(array_keys($request->except('_token')), ['ticket', 'flow']) !== [], 422, 'unexpected_identity_fields');
        $data = $request->validate(['ticket' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'flow' => ['required', 'regex:/^[a-f0-9]{64}$/D']]);
        $flow = $request->session()->pull('identity.flow');
        abort_unless(is_array($flow) && ($flow['expires_at'] ?? 0) > now()->timestamp
            && hash_equals($flow['hash'], hash('sha256', $data['flow'])), 403, 'identity_flow_refused');
        try {
            $snapshot = $gateway->redeem($data['ticket'], $data['flow']);
        } catch (\Throwable) {
            abort(503, 'identity_authority_unavailable');
        }
        abort_unless(($snapshot['version'] ?? null) === 1 && ($snapshot['issuer'] ?? null) === 'alfamatriz'
            && ($snapshot['audience'] ?? null) === 'central-agentes' && is_string($snapshot['subject'] ?? null)
            && ($snapshot['active'] ?? false) === true && ($snapshot['ready'] ?? false) === true
            && is_int($snapshot['session_epoch'] ?? null) && $snapshot['session_epoch'] > 0
            && is_array($snapshot['operations'] ?? null) && in_array('read', $snapshot['operations'], true), 403, 'access_denied');
        $identity = DB::transaction(function () use ($snapshot) {
            $mapping = CentralIdentity::where('issuer', 'alfamatriz')->where('subject', $snapshot['subject'])->first();
            abort_unless($mapping, 403, 'identity_not_provisioned');
            User::whereKey($mapping->user_id)->lockForUpdate()->firstOrFail();
            $mapping = CentralIdentity::whereKey($mapping->id)->lockForUpdate()->firstOrFail();
            abort_unless($mapping->status === 'active', 403, 'access_denied');
            $mapping->update(['session_epoch' => $snapshot['session_epoch']]);

            return $mapping;
        });
        Auth::login(User::findOrFail($identity->user_id), false);
        $request->session()->regenerate();
        $request->session()->put('central.identity_epoch', (int) $identity->session_epoch);

        return response()->noContent();
    }
}
