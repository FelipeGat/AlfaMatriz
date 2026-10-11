<?php

namespace App\Services\CentralIa;

/** Só falhas reconhecidas do provedor podem iniciar um handoff automático. */
class FalhaDeProvedor
{
    public static function tipo(string $erro): ?string
    {
        if (preg_match('/(?:hit|reached|exceeded|exhausted).{0,60}(?:limit|quota)|(?:usage|weekly|rate|premium).{0,30}limit|insufficient_quota|quota.{0,30}(?:exceeded|exhausted)|too many requests|\b429\b/iu', $erro)) {
            return 'limite';
        }

        if (preg_match('/overloaded|model.{0,20}(?:capacity|unavailable)|service unavailable|temporarily unavailable|\b(?:502|503|504)\b/iu', $erro)) {
            return 'indisponivel';
        }

        // Timeout, credencial inválida, processo interrompido, erro de projeto
        // ou ferramenta não são licença para repetir uma implementação.
        return null;
    }
}
