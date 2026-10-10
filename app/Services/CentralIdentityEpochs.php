<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CentralIdentityEpochs
{
    public function user(int $userId): void
    {
        if (config('central_ia.enabled')) {
            DB::table('central_ia_subjects')->where('user_id', $userId)->increment('session_epoch');
        }
    }

    public function profile(int $profileId): void
    {
        if (config('central_ia.enabled')) {
            DB::table('central_ia_subjects')->whereIn('user_id', DB::table('perfil_user')->where('perfil_id', $profileId)->select('user_id'))
                ->increment('session_epoch');
        }
    }
}
