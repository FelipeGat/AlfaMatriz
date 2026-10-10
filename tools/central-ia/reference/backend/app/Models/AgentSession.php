<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentSession extends Model
{
    protected static function booted(): void
    {
        static::saving(function (self $session) {
            if (AgentIntegration::findOrFail($session->integration_id)->owner_id !== $session->owner_id
                || Conversation::findOrFail($session->conversation_id)->user_id !== $session->owner_id) {
                throw new \RuntimeException('ownership_conflict');
            }
        });
    }

    protected $fillable = ['owner_id', 'integration_id', 'conversation_id', 'status'];
}
