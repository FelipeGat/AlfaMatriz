<?php

namespace App\Services\Identity;

use App\Models\AgentIntegration;
use App\Models\AgentSession;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Sessions
{
    public function create(User $owner, int $integrationId, int $conversationId): AgentSession
    {
        app(Access::class)->authorize($owner, 'sessions');
        $integration = AgentIntegration::where('owner_id', $owner->id)->findOrFail($integrationId);
        abort_if($integration->status !== 'connected', 403, 'integration_not_connected');
        $conversation = Conversation::where('user_id', $owner->id)->findOrFail($conversationId);

        return AgentSession::create(['owner_id' => $owner->id, 'integration_id' => $integration->id, 'conversation_id' => $conversation->id]);
    }

    public function revoke(User $owner, int $sessionId): void
    {
        app(Access::class)->authorize($owner, 'sessions');
        DB::transaction(function () use ($owner, $sessionId) {
            AgentSession::where('owner_id', $owner->id)->lockForUpdate()->findOrFail($sessionId)->update(['status' => 'revoked']);
            app(PrivateAudit::class)->record($owner, 'session_revoked', ['session_id' => $sessionId]);
        });
    }
}
