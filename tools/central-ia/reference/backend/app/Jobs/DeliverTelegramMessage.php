<?php

namespace App\Jobs;

use App\Models\ChannelBinding;
use App\Models\Message;
use App\Models\MessageDelivery;
use App\Models\User;
use App\Services\Identity\Access;
use App\Services\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class DeliverTelegramMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $deliveryId) {}

    public function handle(TelegramClient $client): void
    {
        if (! config('central.telegram_enabled')) {
            return;
        }
        $delivery = DB::transaction(function () {
            $d = MessageDelivery::whereKey($this->deliveryId)->lockForUpdate()->first();
            if (! $d || $d->status !== 'pending') {
                return null;
            }
            $message = Message::findOrFail($d->message_id);
            $binding = ChannelBinding::findOrFail($d->channel_binding_id);
            $owner = User::findOrFail($d->owner_id);
            try {
                app(Access::class)->authorize($owner, 'read');
                if (! app(\App\Services\Identity\OwnerPolicy::class)->telegram((string) $binding->telegram_user_id) || $message->owner_id !== $owner->id || $binding->user_id !== $owner->id || $binding->conversation_id !== $message->conversation_id || $binding->revoked_at !== null) {
                    throw new \RuntimeException('delivery_denied');
                }
            } catch (\Throwable) {
                DB::table('message_deliveries')->where('id', $d->id)->update(['status' => 'failed', 'error_code' => 'delivery_denied', 'updated_at' => now()]);

                return null;
            }
            $d->update(['status' => 'sending', 'attempts' => $d->attempts + 1]);

            return $d;
        });
        if (! $delivery) {
            return;
        }
        $message = Message::findOrFail($delivery->message_id);
        $binding = ChannelBinding::find($delivery->channel_binding_id);
        if (! $binding) {
            $delivery->update(['status' => 'failed', 'error_code' => 'binding_missing']);

            return;
        }
        $author = $message->actor_type === 'agent' ? $message->actor_name : 'VS Code · '.$message->actor_name;
        $result = $client->call('sendMessage', ['chat_id' => $binding->chat_id, 'text' => $author."\n".$message->content]);
        if ($result['ok']) {
            $delivery->update(['status' => 'delivered', 'external_id' => (string) ($result['result']['message_id'] ?? ''), 'error_code' => null]);
        } else {
            $delivery->update(['status' => $result['error'] === 'uncertain' ? 'uncertain' : 'failed', 'error_code' => $result['error']]);
        }
    }
}
