<?php

namespace App\Services\Integracao\Dto;

use App\Services\Integracao\Documento;
use App\Services\Integracao\ErroIntegracao;

/** Uma revenda como o sistema a descreve, conforme o contrato. */
class RevendaExterna
{
    public function __construct(
        public readonly string $idExterno,
        public readonly string $nome,
        public readonly ?string $cnpj,
        public readonly ?string $email,
        public readonly ?string $telefone,
        public readonly bool $ativo,
        public readonly int $clientesAtivos,
        public readonly array $cru,
    ) {}

    public static function deArray(array $item): self
    {
        $id = trim((string) ($item['id_externo'] ?? ''));

        if ($id === '') {
            throw ErroIntegracao::respostaInvalida('uma revenda veio sem identificador.');
        }

        return new self(
            idExterno: $id,
            nome: trim((string) ($item['nome'] ?? '')) ?: "Revenda {$id}",
            cnpj: Documento::normalizar($item['cnpj'] ?? null),
            email: $item['email'] ?? null,
            telefone: $item['telefone'] ?? null,
            ativo: (bool) ($item['ativo'] ?? true),
            clientesAtivos: (int) ($item['clientes_ativos'] ?? 0),
            cru: $item,
        );
    }

    /** Os campos do retrato local que vêm do sistema. */
    public function paraEspelho(): array
    {
        return [
            'nome' => $this->nome,
            'cnpj' => $this->cnpj,
            'email' => $this->email,
            'telefone' => $this->telefone,
            'ativo' => $this->ativo,
            'clientes_ativos' => $this->clientesAtivos,
            'payload' => $this->cru,
        ];
    }
}
