<?php

namespace App\Services\Identity;

use App\Contracts\IdentityAuthority;
use App\Models\CentralIdentity;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Access
{
    public function authorize(User $user, string $operation, ?int $sessionEpoch = null): CentralIdentity
    {
        $identity = CentralIdentity::where('user_id', $user->id)->first();
        if (! $identity || ! app(OwnerPolicy::class)->allows($identity->issuer, $identity->subject) || $identity->status !== 'active' || ($sessionEpoch !== null && (int) $identity->session_epoch !== $sessionEpoch)) {
            throw new HttpException(403, 'access_denied');
        }
        try {
            $request = app('request');
            $scope = $request->attributes->get('central.identity_request_scope') === true;
            $key = $identity->issuer.':'.$identity->subject;
            $snapshots = $scope ? $request->attributes->get('central.identity_request_snapshots', []) : [];
            $current = $snapshots[$key] ?? app(IdentityAuthority::class)->inspect($identity->subject);
            if ($scope) {
                $snapshots[$key] = $current;
                $request->attributes->set('central.identity_request_snapshots', $snapshots);
            }
        } catch (\Throwable) {
            throw new HttpException(503, 'identity_authority_unavailable');
        }
        if (($current['issuer'] ?? null) !== $identity->issuer || ($current['subject'] ?? null) !== $identity->subject
            || ($current['active'] ?? false) !== true || ($current['ready'] ?? false) !== true
            || ($current['session_epoch'] ?? null) !== (int) $identity->session_epoch
            || ! is_array($current['operations'] ?? null) || ! in_array($operation, $current['operations'], true)) {
            throw new HttpException(403, 'access_denied');
        }

        return $identity;
    }

    public function owns(User $user, int $owner): void
    {
        if ($user->id !== $owner) {
            throw new HttpException(404, 'resource_unavailable');
        }
    }
}
