<?php

namespace App\Services;

use App\Jobs\DeliverTelegramMessage;
use App\Models\ChannelBinding;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageDelivery;
use App\Models\User;
use App\Services\Identity\Access;
use Illuminate\Support\Facades\DB;

class ConversationService
{
    public function forUser(User $user): Conversation
    {
        app(Access::class)->authorize($user, 'read');

        return Conversation::firstOrCreate(['user_id' => $user->id]);
    }

    public function post(User $user, string $text, string $clientId, string $origin = 'web'): Message
    {
        app(Access::class)->authorize($user, 'send');
        $conversation = $this->forUser($user);
        $message = DB::transaction(function () use ($conversation, $user, $text, $clientId, $origin) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $existing = Message::where('conversation_id', $conversation->id)->where('client_id', $clientId)->first();
            if ($existing) {
                app(AgentService::class)->scheduleAutomatic($existing);

                return $existing;
            }
            $message = Message::create(['conversation_id' => $conversation->id, 'owner_id' => $user->id, 'actor_type' => 'user', 'actor_name' => $user->name, 'origin' => $origin, 'content' => $text, 'client_id' => $clientId]);
            $binding = ChannelBinding::where('user_id', $user->id)->whereNull('revoked_at')->first();
            if ($binding) {
                MessageDelivery::create(['owner_id' => $user->id, 'message_id' => $message->id, 'channel_binding_id' => $binding->id]);
            }
            app(AgentService::class)->scheduleAutomatic($message);

            return $message;
        });
        $delivery = MessageDelivery::where('message_id', $message->id)->where('status', 'pending')->first();
        if ($delivery && config('central.telegram_enabled')) {
            try {
                DeliverTelegramMessage::dispatch($delivery->id)->onQueue('telegram');
            } catch (\Throwable) { /* Persistent outbox will be dispatched by poller. No exception URL logged. */
            }
        }

        return $message;
    }
}
