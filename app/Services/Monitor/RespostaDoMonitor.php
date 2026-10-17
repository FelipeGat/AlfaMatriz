<?php

namespace App\Services\Monitor;

use Illuminate\Support\Carbon;

/**
 * O que uma tela do Monitor recebe da API de leitura do AlfaMonitor (#394).
 *
 * Carrega, além do dado, a HONESTIDADE sobre ele: de quando é o retrato
 * (`geradoEm`, a hora do monitor, e não a da consulta) e se é o último dado
 * bom guardado porque a consulta de agora falhou (`velho`). A tela mostra
 * os dois — número de servidor sem hora é número que alguém vai ler como
 * "agora" quando o monitor está fora há uma hora.
 */
final class RespostaDoMonitor
{
    /**
     * @param  array<string, mixed>|null  $dados
     */
    public function __construct(
        public readonly ?array $dados,
        public readonly ?Carbon $geradoEm = null,
        public readonly bool $velho = false,
        public readonly ?string $erro = null,
        public readonly bool $naoEncontrado = false,
    ) {}

    public static function falha(string $erro): self
    {
        return new self(null, erro: $erro);
    }

    public static function inexistente(): self
    {
        return new self(null, naoEncontrado: true);
    }

    public function temDados(): bool
    {
        return $this->dados !== null;
    }

    /** Atalho para ler um campo do dado com ponto (`topo.acessos_hoje`). */
    public function get(string $chave, mixed $padrao = null): mixed
    {
        return data_get($this->dados, $chave, $padrao);
    }
}
