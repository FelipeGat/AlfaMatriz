<?php

namespace App\Services\Integracao;

/**
 * CPF e CNPJ normalizados.
 *
 * Um lugar só, de propósito: o casamento entre o cliente do sistema e o cliente
 * da matriz depende de os dois lados chegarem à mesma forma. Se cada ponto do
 * código decidir sua própria normalização, "12.345.678/0001-99" e
 * "12345678000199" viram clientes diferentes e ninguém entende por quê.
 */
class Documento
{
    /** Só os dígitos. Devolve null para o que não tem dígito nenhum. */
    public static function normalizar(?string $documento): ?string
    {
        if ($documento === null) {
            return null;
        }

        $digitos = preg_replace('/\D+/', '', $documento) ?? '';

        return $digitos === '' ? null : $digitos;
    }

    /**
     * Os dois documentos são o mesmo?
     *
     * Documento ausente NUNCA casa com documento ausente: dois clientes sem
     * CNPJ não são o mesmo cliente, e tratá-los como iguais juntaria cadastros
     * distintos em silêncio.
     */
    public static function iguais(?string $um, ?string $outro): bool
    {
        $um = self::normalizar($um);
        $outro = self::normalizar($outro);

        return $um !== null && $outro !== null && $um === $outro;
    }

    public static function ehCnpj(?string $documento): bool
    {
        return strlen((string) self::normalizar($documento)) === 14;
    }

    public static function ehCpf(?string $documento): bool
    {
        return strlen((string) self::normalizar($documento)) === 11;
    }

    /** Só para exibir: a forma guardada é sempre a normalizada. */
    public static function formatar(?string $documento): ?string
    {
        $digitos = self::normalizar($documento);

        if ($digitos === null) {
            return null;
        }

        if (strlen($digitos) === 14) {
            return vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($digitos));
        }

        if (strlen($digitos) === 11) {
            return vsprintf('%s%s%s.%s%s%s.%s%s%s-%s%s', str_split($digitos));
        }

        return $digitos;
    }
}
