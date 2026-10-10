<?php

namespace App\Http\Controllers;

use App\Models\ChannelBinding;
use App\Models\PairingCode;
use App\Services\Identity\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TelegramPairingController extends Controller
{
    public function store(Request $request)
    {
        $identity = app(Access::class)->authorize($request->user(), 'pair');
        if (ChannelBinding::where('user_id', $request->user()->id)->whereNull('revoked_at')->exists()) {
            return response()->json(['message' => 'Telegram já vinculado.'], 409);
        }
        DB::table('pending_channel_links')->where('user_id', $request->user()->id)->delete();
        $code = bin2hex(random_bytes(16));
        PairingCode::updateOrCreate(['user_id' => $request->user()->id], ['session_epoch' => $identity->session_epoch, 'code_hash' => hash('sha256', $code), 'expires_at' => now()->addMinutes(5)]);

        return response()->json(['command' => '/start '.$code, 'expires_in' => 300])->header('Cache-Control', 'no-store');
    }
}
