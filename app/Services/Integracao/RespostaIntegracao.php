<?php

namespace App\Services\Integracao;

use Carbon\CarbonImmutable;

/**
 * Uma resposta do sistema, já conferida contra o contrato.
 *
 * Nada além desta classe lê o envelope cru. É aqui que a versão do contrato é
 * verificada — e recusar cedo é o que impede um sistema numa versão futura de
 * gravar retrato torto, que é pior que retrato ausente porque parece confiável.
 */
class RespostaIntegracao
{
    public function __construct(
        public readonly string $contrato,
        public readonly string $sistema,
        public readonly ?CarbonImmutable $geradoEm,
        public readonly array $dados,
        public readonly int $paginaNumero = 1,
        public readonly int $paginaTamanho = 0,
        public readonly int $totalItens = 0,
        public readonly int $totalPaginas = 1,
    ) {}

    /**
     * @throws ErroIntegracao quando o envelope não é do formato esperado ou a
     *                        versão principal do contrato é outra.
     */
    public static function deArray(mixed $corpo, int $majorEsperado): self
    {
        if (! is_array($corpo)) {
            throw ErroIntegracao::respostaInvalida('o corpo não é um objeto.');
        }

        $contrato = (string) ($corpo['contrato'] ?? '');

        if ($contrato === '') {
            throw ErroIntegracao::respostaInvalida('falta a versão do contrato.');
        }

        // Só a versão PRINCIPAL importa: campo novo continua compatível, e
        // recusar por diferença de versão menor travaria a integração a cada
        // acréscimo inofensivo do outro lado.
        $major = (int) explode('.', $contrato)[0];

        if ($major !== $majorEsperado) {
            throw ErroIntegracao::contratoIncompativel($contrato, $majorEsperado);
        }

        if (! array_key_exists('dados', $corpo)) {
            throw ErroIntegracao::respostaInvalida('falta o campo de dados.');
        }

        $dados = $corpo['dados'];

        if (! is_array($dados)) {
            throw ErroIntegracao::respostaInvalida('o campo de dados não é uma lista nem um objeto.');
        }

        $pagina = is_array($corpo['pagina'] ?? null) ? $corpo['pagina'] : [];

        return new self(
            contrato: $contrato,
            sistema: (string) ($corpo['sistema'] ?? ''),
            geradoEm: self::momento($corpo['gerado_em'] ?? null),
            dados: $dados,
            paginaNumero: (int) ($pagina['numero'] ?? 1),
            paginaTamanho: (int) ($pagina['tamanho'] ?? count($dados)),
            totalItens: (int) ($pagina['total_itens'] ?? count($dados)),
            totalPaginas: (int) ($pagina['total_paginas'] ?? 1),
        );
    }

    public function temProximaPagina(): bool
    {
        return $this->paginaNumero < $this->totalPaginas;
    }

    /** Os itens da coleção. Vazio quando a resposta é de um recurso único. */
    public function itens(): array
    {
        return array_is_list($this->dados) ? $this->dados : [];
    }

    /** O objeto, quando a resposta é de um recurso único (ping, contadores). */
    public function objeto(): array
    {
        return array_is_list($this->dados) ? [] : $this->dados;
    }

    private static function momento(mixed $valor): ?CarbonImmutable
    {
        if (! is_string($valor) || $valor === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($valor);
        } catch (\Throwable) {
            // Data ilegível não derruba a resposta inteira: é informativa, e
            // perder o carimbo de hora é menos grave que perder os dados.
            return null;
        }
    }
}
