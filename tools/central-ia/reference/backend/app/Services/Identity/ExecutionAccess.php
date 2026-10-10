<?php

namespace App\Services\Identity;

use App\Models\AgentIntegration;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

class ExecutionAccess
{
    public function authorize(AgentRun $run): User
    {
        abort_unless($run->mode === 'mock' && config('central.agent_mode') === 'mock', 403, 'real_inference_disabled');
        $user = User::findOrFail($run->owner_id);
        app(Access::class)->authorize($user, 'execute', (int) $run->session_epoch);
        $conversation = Conversation::findOrFail($run->conversation_id);
        app(Access::class)->owns($user, $conversation->user_id);
        $message = Message::findOrFail($run->message_id);
        abort_unless($message->owner_id === $user->id && $message->conversation_id === $conversation->id && $message->actor_type === 'user', 403, 'execution_denied');
        $integration = AgentIntegration::where('owner_id', $user->id)->findOrFail($run->integration_id);
        abort_if($integration->status === 'revoked' || (int) $integration->generation !== (int) $run->integration_generation, 403, 'integration_revoked');

        return $user;
    }
}
