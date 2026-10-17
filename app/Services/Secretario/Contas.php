<?php

namespace App\Services\Secretario;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Quem tem o Secretário: pela PERMISSÃO, e não pelo perfil "Secretário" —
 * desde 17/10/2026 ele vale para todos os perfis de pessoas, e quem o recebe
 * pelo perfil Administrador ou Membro também tem briefing e acompanhamento.
 * As mesmas portas da tela: conta ativa, fora do escopo de revenda.
 */
class Contas
{
    /** @return Collection<int, User> */
    public static function comSecretario(): Collection
    {
        return User::where('ativo', true)->get()
            ->filter(fn (User $u) => ! $u->temEscopoDeRevenda() && $u->canPermissao('secretario', 'ler'))->values();
    }
}
