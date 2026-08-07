<?php

namespace App\Services\Integracao\Dto;

use App\Services\Integracao\ErroIntegracao;

/**
 * Um plano oferecido pelo sistema.
 *
 * Existe para a matriz escolher um plano ao liberar licença em vez de mandar
 * texto livre e torcer para o outro lado reconhecer.
 */
class PlanoExterno
{
    public function __construct(
        public readonly string $idExterno,
        public readonly string $nome,
        public readonly bool $ativo,
        public readonly ?float $precoMensal,
        public readonly string $moeda,
        public readonly array $limites,
        public readonly array $cru,
    ) {}

    public static function deArray(array $item): self
    {
        $id = trim((string) ($item['id_externo'] ?? ''));

        if ($id === '') {
            throw ErroIntegracao::respostaInvalida('um plano veio sem identificador.');
        }

        return new self(
            idExterno: $id,
            nome: trim((string) ($item['nome'] ?? '')) ?: "Plano {$id}",
            ativo: (bool) ($item['ativo'] ?? true),
            precoMensal: isset($item['preco_mensal']) ? (float) $item['preco_mensal'] : null,
            moeda: strtoupper((string) ($item['moeda'] ?? 'BRL')),
            // Livre de propósito: a matriz guarda e mostra o que o sistema
            // declara como limite, sem tentar interpretar.
            limites: is_array($item['limites'] ?? null) ? $item['limites'] : [],
            cru: $item,
        );
    }

    public function paraEspelho(): array
    {
        return [
            'nome' => $this->nome,
            'ativo' => $this->ativo,
            'preco_mensal' => $this->precoMensal,
            'moeda' => $this->moeda,
            'limites' => $this->limites,
            'payload' => $this->cru,
        ];
    }
}
