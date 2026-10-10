<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (self $message) {
            $owner = Conversation::findOrFail($message->conversation_id)->user_id;
            if ($message->owner_id !== null && $message->owner_id !== $owner) {
                throw new \RuntimeException('ownership_conflict');
            }
            $message->owner_id = $owner;
        });
    }

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function delivery()
    {
        return $this->hasOne(MessageDelivery::class);
    }
}
