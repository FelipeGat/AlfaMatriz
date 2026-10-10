<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelBinding extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (self $binding) {
            if (Conversation::findOrFail($binding->conversation_id)->user_id !== $binding->user_id) {
                throw new \RuntimeException('ownership_conflict');
            }
        });
    }
}
