<?php

namespace Tests\Support;

use App\Contracts\IdentityAuthority;
use App\Models\CentralIdentity;

class FakeIdentityAuthority implements IdentityAuthority
{
    public array $overrides = [];

    public bool $available = true;

    public function inspect(string $subject): array
    {
        if (! $this->available) {
            throw new \RuntimeException('unavailable');
        }
        if (isset($this->overrides[$subject])) {
            return $this->overrides[$subject];
        }
        $identity = CentralIdentity::where('issuer', 'alfamatriz')->where('subject', $subject)->first();

        return ['issuer' => 'alfamatriz', 'subject' => $subject, 'active' => true, 'ready' => true,
            'session_epoch' => (int) ($identity?->session_epoch ?? 1),
            'operations' => ['read', 'send', 'pair', 'execute', 'integrations', 'sessions']];
    }
}
