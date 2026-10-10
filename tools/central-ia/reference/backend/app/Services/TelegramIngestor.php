<?php

namespace App\Services;

use App\Models\CentralIdentity;
use App\Models\ChannelBinding;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PairingCode;
use App\Models\User;
use App\Services\Identity\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TelegramIngestor
{
    public function ingest(array $update): void
    {
        if (! isset($update['update_id']) || ! is_int($update['update_id']) || $update['update_id'] < 0) {
            return;
        }
        DB::transaction(function () use ($update) {
            // Claim update atomically. No raw Telegram payload is retained.
            if (! DB::table('telegram_updates')->insertOrIgnore(['update_id' => $update['update_id'], 'created_at' => now()])) {
                return;
            }
            $m = $update['message'] ?? null;
            if (! is_array($m) || ($m['chat']['type'] ?? '') !== 'private' || ! isset($m['from']['id'],$m['chat']['id']) || ($m['from']['is_bot'] ?? false) || (string) $m['from']['id'] !== (string) $m['chat']['id']) {
                return;
            }
            $text = $m['text'] ?? null;
            if (! is_string($text)) {
                return;
            }
            $sender = (string) $m['from']['id'];
            $chat = (string) $m['chat']['id'];
            if (! app(\App\Services\Identity\OwnerPolicy::class)->telegram($sender)) return;
            if (preg_match('/^\/start ([a-f0-9]{32})$/D', $text, $matches)) {
                $key = 'pair:'.$sender;
                if (RateLimiter::tooManyAttempts($key, 5)) {
                    return;
                }
                RateLimiter::hit($key, 300);
                $code = PairingCode::where('code_hash', hash('sha256', $matches[1]))->lockForUpdate()->first();
                if (! $code || $code->expires_at->isPast()) {
                    return;
                }
                $owner = User::whereKey($code->user_id)->lockForUpdate()->firstOrFail();
                $identity = $this->authorizeSender($owner, 'pair');
                if (! $identity) {
                    return;
                }
                if ((int) $code->session_epoch !== (int) $identity->session_epoch) {
                    return;
                }
                if (ChannelBinding::whereNull('revoked_at')->where(function ($q) use ($code, $sender) {
                    $q->where('user_id', $code->user_id)->orWhere('telegram_user_id', $sender);
                })->exists()) {
                    return;
                }
                DB::table('pending_channel_links')->updateOrInsert(['user_id' => $owner->id], [
                    'telegram_user_id' => $sender, 'chat_id' => $chat, 'expires_at' => now()->addMinutes(5),
                    'session_epoch' => $identity->session_epoch, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $code->delete();
                RateLimiter::clear($key);

                return;
            }
            if (str_starts_with($text, '/start')) {
                return;
            } // Never persist pairing codes as conversation text.
            $binding = ChannelBinding::where('telegram_user_id', $sender)->where('chat_id', $chat)->whereNull('revoked_at')->first();
            if (! $binding || trim($text) === '' || mb_strlen($text) > config('central.max_text')) {
                return;
            }
            $owner = User::whereKey($binding->user_id)->lockForUpdate()->firstOrFail();
            if (! $this->authorizeSender($owner, 'send')) {
                return;
            }
            $conversation = Conversation::findOrFail($binding->conversation_id);
            app(Access::class)->owns($owner, $conversation->user_id);
            $key = 'telegram-message:'.$sender;
            if (RateLimiter::tooManyAttempts($key, 30)) {
                return;
            }
            RateLimiter::hit($key, 60);
            Conversation::whereKey($binding->conversation_id)->lockForUpdate()->firstOrFail();
            $message = Message::firstOrCreate(['origin' => 'telegram', 'external_id' => (string) $update['update_id']],
                ['owner_id' => $owner->id, 'conversation_id' => $binding->conversation_id, 'actor_type' => 'user', 'actor_name' => $binding->user_id ? User::findOrFail($binding->user_id)->name : 'Usuário', 'content' => $text]);
            app(AgentService::class)->scheduleAutomatic($message);
        });
    }

    private function authorizeSender(User $owner, string $operation): ?CentralIdentity
    {
        try {
            return app(Access::class)->authorize($owner, $operation);
        } catch (HttpException $error) {
            if ($error->getStatusCode() === 403) {
                // Consume permanently forbidden updates so one revoked account cannot block the bot.
                return null;
            }
            // Temporary authority failures must remain retryable, without accepting the message.
            throw $error;
        }
    }
}
