<?php

namespace App\Services\Identity;

use Illuminate\Support\Facades\DB;

class OwnershipBackfill
{
    /** Explicit future maintenance operation; never invoked by migration or request. */
    public function run(): void
    {
        DB::transaction(function () {
            foreach (DB::table('conversations')->orderBy('id')->get(['id', 'user_id']) as $conversation) {
                foreach (['messages', 'agent_runs'] as $table) {
                    if (DB::table($table)->where('conversation_id', $conversation->id)->whereNotNull('owner_id')->where('owner_id', '!=', $conversation->user_id)->exists()) {
                        throw new \RuntimeException('ownership_conflict');
                    }
                    DB::table($table)->where('conversation_id', $conversation->id)->whereNull('owner_id')->update(['owner_id' => $conversation->user_id]);
                }
            }
            foreach (DB::table('message_deliveries')->orderBy('id')->get() as $delivery) {
                $message = DB::table('messages')->find($delivery->message_id);
                $binding = DB::table('channel_bindings')->find($delivery->channel_binding_id);
                if (! $message || ! $binding || $message->owner_id !== $binding->user_id || $message->conversation_id !== $binding->conversation_id
                    || ($delivery->owner_id !== null && $delivery->owner_id !== $message->owner_id)) {
                    throw new \RuntimeException('ownership_conflict');
                }
                DB::table('message_deliveries')->where('id', $delivery->id)->whereNull('owner_id')->update(['owner_id' => $message->owner_id]);
            }
        });
    }
}
