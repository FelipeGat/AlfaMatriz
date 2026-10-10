<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageDelivery extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (self $delivery) {
            $message = Message::findOrFail($delivery->message_id);
            $binding = ChannelBinding::findOrFail($delivery->channel_binding_id);
            $owner = $message->owner_id;
            if ($owner === null || $binding->user_id !== $owner || $binding->conversation_id !== $message->conversation_id
                || ($delivery->owner_id !== null && $delivery->owner_id !== $owner)) {
                throw new \RuntimeException('ownership_conflict');
            }
            $delivery->owner_id = $owner;
        });
    }
}
