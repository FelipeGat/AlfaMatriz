<?php

namespace App\Services\Integracao\Dto;

use App\Services\Integracao\Documento;
use App\Services\Integracao\ErroIntegracao;
use Carbon\CarbonImmutable;

/**
 * Um cliente como o sistema o descreve — a academia, o condomínio, a família,
 * a clínica.
 */
class ClienteExterno
{
    /** As situações que o contrato prevê. Qualquer outra vira "pendente". */
    private const STATUS = ['ativo', 'pendente', 'bloqueado', 'cancelado'];

    public function __construct(
        public readonly string $idExterno,
        public readonly string $nome,
        public readonly ?string $razaoSocial,
        public readonly ?string $cpfCnpj,
        public readonly ?string $email,
        public readonly ?string $telefone,
        public readonly ?string $cidade,
        public readonly ?string $uf,
        public readonly bool $ativo,
        public readonly string $status,
        public readonly ?string $revendaIdExterno,
        public readonly int $unidadesAtivas,
        public readonly ?CarbonImmutable $criadoEm,
        public readonly ?CarbonImmutable $atualizadoEm,
        public readonly array $cru,
    ) {}

    public static function deArray(array $item): self
    {
        $id = trim((string) ($item['id_externo'] ?? ''));

        if ($id === '') {
            throw ErroIntegracao::respostaInvalida('um cliente veio sem identificador.');
        }

        $status = (string) ($item['status'] ?? 'ativo');

        return new self(
            idExterno: $id,
            nome: trim((string) ($item['nome'] ?? '')) ?: "Cliente {$id}",
            razaoSocial: $item['razao_social'] ?? null,
            cpfCnpj: Documento::normalizar($item['cpf_cnpj'] ?? null),
            email: $item['email'] ?? null,
            telefone: $item['telefone'] ?? null,
            cidade: $item['cidade'] ?? null,
            uf: self::uf($item['uf'] ?? null),
            ativo: (bool) ($item['ativo'] ?? true),
            // Situação desconhecida vira "pendente", nunca "ativo": tratar o
            // que não se entende como ativo faria a matriz cobrar por engano.
            status: in_array($status, self::STATUS, true) ? $status : 'pendente',
            revendaIdExterno: self::texto($item['revenda_id_externo'] ?? null),
            unidadesAtivas: max(0, (int) ($item['unidades_ativas'] ?? 0)),
            criadoEm: self::momento($item['criado_em'] ?? null),
            atualizadoEm: self::momento($item['atualizado_em'] ?? null),
            cru: $item,
        );
    }

    public function paraEspelho(): array
    {
        return [
            'nome' => $this->nome,
            'razao_social' => $this->razaoSocial,
            'cpf_cnpj' => $this->cpfCnpj,
            'email' => $this->email,
            'telefone' => $this->telefone,
            'cidade' => $this->cidade,
            'uf' => $this->uf,
            'ativo' => $this->ativo,
            'status' => $this->status,
            'revenda_id_externo' => $this->revendaIdExterno,
            'unidades_ativas' => $this->unidadesAtivas,
            'criado_em_origem' => $this->criadoEm,
            'atualizado_em_origem' => $this->atualizadoEm,
            'payload' => $this->cru,
        ];
    }

    private static function uf(mixed $valor): ?string
    {
        $uf = strtoupper(trim((string) $valor));

        return strlen($uf) === 2 ? $uf : null;
    }

    private static function texto(mixed $valor): ?string
    {
        $texto = trim((string) ($valor ?? ''));

        return $texto === '' ? null : $texto;
    }

    private static function momento(mixed $valor): ?CarbonImmutable
    {
        if (! is_string($valor) || $valor === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($valor);
        } catch (\Throwable) {
            return null;
        }
    }
}
