<?php

namespace App\Contracts;

interface IdentityAuthority
{
    /** Return current AlfaMatriz authorization, never locally inferred roles. */
    public function inspect(string $subject): array;
}
