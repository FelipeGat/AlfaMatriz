<?php

namespace App\Services\Identity;

use App\Models\AgentIntegration;
use App\Models\AgentRun;
use App\Models\AgentSession;
use App\Models\CentralIdentity;
use App\Models\ChannelBinding;
use App\Models\PairingCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Revocation
{
    public function integration(User $owner, int $id): void
    {
        app(Access::class)->authorize($owner, 'integrations');
        DB::transaction(function () use ($owner, $id) {
            $integration = AgentIntegration::where('owner_id', $owner->id)->lockForUpdate()->findOrFail($id);
            app(PrivateAudit::class)->record($owner, 'integration_revoked', ['integration_id' => $id]);
            $integration->update(['status' => 'revoked', 'generation' => $integration->generation + 1]);
            AgentSession::where('owner_id', $owner->id)->where('integration_id', $id)->update(['status' => 'revoked']);
            AgentRun::where('owner_id', $owner->id)->where('integration_id', $id)->where('status', 'queued')
                ->update(['status' => 'cancelled', 'error_code' => 'integration_revoked', 'finished_at' => now()]);
        });
    }

    public function telegram(User $owner): void
    {
        app(Access::class)->authorize($owner, 'pair');
        DB::transaction(function () use ($owner) {
            app(PrivateAudit::class)->record($owner, 'telegram_revoked');
            ChannelBinding::where('user_id', $owner->id)->update(['revoked_at' => now()]);
            PairingCode::where('user_id', $owner->id)->delete();
            DB::table('pending_channel_links')->where('user_id', $owner->id)->delete();
        });
    }

    /** Only an authenticated AlfaMatriz lifecycle handler may call this; no admin endpoint. */
    public function identity(CentralIdentity $identity): void
    {
        DB::transaction(function () use ($identity) {
            $current = CentralIdentity::lockForUpdate()->findOrFail($identity->id);
            $current->update(['status' => 'revoked', 'session_epoch' => $current->session_epoch + 1]);
            $owner = $current->user_id;
            app(PrivateAudit::class)->record(User::findOrFail($owner), 'identity_revoked', ['identity_id' => $current->id]);
            ChannelBinding::where('user_id', $owner)->update(['revoked_at' => now()]);
            PairingCode::where('user_id', $owner)->delete();
            DB::table('pending_channel_links')->where('user_id', $owner)->delete();
            AgentSession::where('owner_id', $owner)->update(['status' => 'revoked']);
            AgentRun::where('owner_id', $owner)->where('status', 'queued')
                ->update(['status' => 'cancelled', 'error_code' => 'identity_revoked', 'finished_at' => now()]);
        });
    }
}
