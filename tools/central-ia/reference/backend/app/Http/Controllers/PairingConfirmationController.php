<?php

namespace App\Http\Controllers;

use App\Models\ChannelBinding;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Identity\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PairingConfirmationController extends Controller
{
    public function pending(Request $request)
    {
        app(Access::class)->authorize($request->user(), 'pair');
        $pending = DB::table('pending_channel_links')->where('user_id', $request->user()->id)->where('expires_at', '>', now())->first(['chat_id', 'expires_at']);

        return response()->json(['pending' => $pending]);
    }

    public function confirm(Request $request)
    {
        $data = $request->validate(['chat_id' => 'required|string|regex:/^[0-9]{1,20}$/']);
        $owner = $request->user();
        $identity = app(Access::class)->authorize($owner, 'pair');
        DB::transaction(function () use ($owner, $identity, $data) {
            User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $pending = DB::table('pending_channel_links')->where('user_id', $owner->id)->lockForUpdate()->first();
            abort_unless($pending && $pending->chat_id === $data['chat_id'] && now()->lt($pending->expires_at) && (int) $pending->session_epoch === (int) $identity->session_epoch, 409, 'pairing_confirmation_unavailable');
            $conversation = Conversation::firstOrCreate(['user_id' => $owner->id]);
            $binding = ChannelBinding::where('user_id', $owner->id)->lockForUpdate()->first();
            abort_if($binding && $binding->revoked_at === null, 409, 'already_paired');
            $fields = ['user_id' => $owner->id, 'conversation_id' => $conversation->id, 'telegram_user_id' => $pending->telegram_user_id,
                'chat_id' => $pending->chat_id, 'revoked_at' => null];
            $binding ? $binding->update($fields) : ChannelBinding::create($fields);
            DB::table('pending_channel_links')->where('id', $pending->id)->delete();
            DB::table('audit_events')->insert(['user_id' => $owner->id, 'event' => 'telegram_paired', 'created_at' => now()]);
        });

        return response()->noContent();
    }
}
