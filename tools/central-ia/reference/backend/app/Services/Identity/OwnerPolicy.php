<?php

namespace App\Services\Identity;

class OwnerPolicy
{
    public function allows(string $issuer, string $subject): bool
    {
        if (! config('central.exclusive_enabled')) return true;
        $p = $this->policy();
        return $p && $issuer === 'alfamatriz' && hash_equals($p['subject'], $subject);
    }

    public function telegram(string $sender): bool
    {
        if (! config('central.exclusive_enabled')) return true;
        $p = $this->policy();
        return $p && is_string($p['telegram_user_id'] ?? null)
            && preg_match('/^[1-9][0-9]*$/D', $p['telegram_user_id']) && hash_equals($p['telegram_user_id'], $sender);
    }

    private function policy(): ?array
    {
        $f = config('central.owner_policy_file');
        if (! is_string($f) || ! is_file($f) || ! is_readable($f)) return null;
        clearstatcache(true, $f);
        if ((fileperms($f) & 0077) !== 0) return null;
        try { $p = json_decode(file_get_contents($f), true, 8, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return null; }
        return is_array($p) && ($p['version'] ?? null) === 1 && ($p['confirmed'] ?? false) === true
            && ($p['issuer'] ?? null) === 'alfamatriz' && is_int($p['user_id'] ?? null) && $p['user_id'] > 0
            && is_string($p['subject'] ?? null) && \Illuminate\Support\Str::isUuid($p['subject']) ? $p : null;
    }
}
