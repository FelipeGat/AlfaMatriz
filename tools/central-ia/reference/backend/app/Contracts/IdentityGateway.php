<?php

namespace App\Contracts;

interface IdentityGateway extends IdentityAuthority
{
    public function redeem(string $ticket, string $flow): array;
}
