<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class CentralOwnerPolicy
{
    public function allows(?User $user): bool
    {
        if (! config('central_ia.exclusive_enabled')) return true;
        if (! $user || ! $user->ativo || $user->primeiro_acesso) return false;
        $policy = $this->policy();
        if (! $policy || (string) $user->getKey() !== (string) $policy['user_id']) return false;
        $subject = DB::table('central_ia_subjects')->where('user_id', $user->getKey())->value('subject');
        return is_string($subject) && hash_equals($policy['subject'], $subject);
    }

    private function policy(): ?array
    {
        $file = config('central_ia.owner_policy_file');
        if (! is_string($file) || ! is_file($file) || ! is_readable($file)) return null;
        clearstatcache(true, $file);
        if ((fileperms($file) & 0077) !== 0) return null;
        try { $p = json_decode(file_get_contents($file), true, 8, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return null; }
        if (! is_array($p) || ($p['version'] ?? null) !== 1 || ($p['issuer'] ?? null) !== 'alfamatriz'
            || ($p['confirmed'] ?? false) !== true || ! is_int($p['user_id'] ?? null) || $p['user_id'] < 1
            || ! is_string($p['subject'] ?? null) || ! \Illuminate\Support\Str::isUuid($p['subject'])) return null;
        return $p;
    }
}
