<?php

namespace App\Services\Identity;

use App\Contracts\IdentityAuthority;
use App\Models\CentralIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class IdentityLinker
{
    /** Explicit mapping preserves local user ID and every historical relationship. */
    public function link(User $user, string $subject): CentralIdentity
    {
        if (! app(OwnerPolicy::class)->allows('alfamatriz', $subject)) throw new \RuntimeException('identity_link_refused');
        $current = app(IdentityAuthority::class)->inspect($subject);
        if (($current['issuer'] ?? null) !== 'alfamatriz' || ($current['subject'] ?? null) !== $subject
            || ($current['active'] ?? false) !== true || ($current['ready'] ?? false) !== true
            || ! is_int($current['session_epoch'] ?? null) || $current['session_epoch'] < 1) {
            throw new \RuntimeException('identity_link_refused');
        }

        return DB::transaction(function () use ($user, $subject, $current) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($existing = CentralIdentity::where('user_id', $user->id)->first()) {
                if ($existing->issuer !== 'alfamatriz' || $existing->subject !== $subject) {
                    throw new \RuntimeException('identity_link_conflict');
                }

                return $existing;
            }
            $identity = CentralIdentity::create(['user_id' => $user->id, 'issuer' => 'alfamatriz', 'subject' => $subject,
                'status' => 'active', 'session_epoch' => $current['session_epoch']]);
            app(PrivateAudit::class)->record($user, 'identity_linked', ['identity_id' => $identity->id]);

            return $identity;
        });
    }
}
