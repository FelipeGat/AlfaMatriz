<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentRun extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (self $run) {
            $conversation = Conversation::findOrFail($run->conversation_id);
            $message = Message::findOrFail($run->message_id);
            if ($run->owner_id !== $conversation->user_id || $message->owner_id !== $run->owner_id || $message->conversation_id !== $run->conversation_id) {
                throw new \RuntimeException('ownership_conflict');
            }
            if ($run->integration_id !== null && AgentIntegration::findOrFail($run->integration_id)->owner_id !== $run->owner_id) {
                throw new \RuntimeException('ownership_conflict');
            }
        });
    }

    protected function casts(): array
    {
        return ['usage' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
