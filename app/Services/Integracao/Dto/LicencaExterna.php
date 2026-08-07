<?php

namespace App\Services\Integracao\Dto;

use App\Services\Integracao\ErroIntegracao;
use Carbon\CarbonImmutable;

/** A licença de um cliente dentro do sistema. */
class LicencaExterna
{
    private const STATUS = ['ativa', 'pendente', 'vencida', 'bloqueada', 'cancelada'];

    public function __construct(
        public readonly string $idExterno,
        public readonly string $clienteIdExterno,
        public readonly ?string $revendaIdExterno,
        public readonly string $status,
        public readonly ?string $plano,
        public readonly ?string $planoIdExterno,
        public readonly ?string $tipo,
        public readonly ?CarbonImmutable $inicioEm,
        public readonly ?CarbonImmutable $fimEm,
        public readonly bool $bloqueiaAcesso,
        public readonly ?string $liberadaPor,
        public readonly ?CarbonImmutable $liberadaEm,
        public readonly array $cru,
    ) {}

    public static function deArray(array $item): self
    {
        $cliente = trim((string) ($item['cliente_id_externo'] ?? ''));

        if ($cliente === '') {
            throw ErroIntegracao::respostaInvalida('uma licença veio sem o cliente a que pertence.');
        }

        // Sistema sem entidade de licença própria manda a linha derivada do
        // cliente. Se ele esquecer o identificador, derivamos aqui: a chave
        // única do retrato local depende dele nunca ser nulo.
        $id = trim((string) ($item['id_externo'] ?? '')) ?: "cliente:{$cliente}";

        $status = (string) ($item['status'] ?? 'pendente');

        return new self(
            idExterno: $id,
            clienteIdExterno: $cliente,
            revendaIdExterno: self::texto($item['revenda_id_externo'] ?? null),
            // Situação desconhecida vira "pendente", nunca "ativa".
            status: in_array($status, self::STATUS, true) ? $status : 'pendente',
            plano: self::texto($item['plano'] ?? null),
            planoIdExterno: self::texto($item['plano_id_externo'] ?? null),
            tipo: self::texto($item['tipo'] ?? null),
            inicioEm: self::momento($item['inicio_em'] ?? null),
            fimEm: self::momento($item['fim_em'] ?? null),
            // O padrão é FALSO: se o sistema não declarar, a matriz não pode
            // prometer que bloquear vai barrar o acesso de alguém.
            bloqueiaAcesso: (bool) ($item['bloqueia_acesso'] ?? false),
            liberadaPor: self::texto($item['liberada_por'] ?? null),
            liberadaEm: self::momento($item['liberada_em'] ?? null),
            cru: $item,
        );
    }

    public function paraEspelho(): array
    {
        return [
            'status' => $this->status,
            'plano' => $this->plano,
            'plano_id_externo' => $this->planoIdExterno,
            'tipo' => $this->tipo,
            'inicio_em' => $this->inicioEm,
            'fim_em' => $this->fimEm,
            'bloqueia_acesso' => $this->bloqueiaAcesso,
            'liberada_por' => $this->liberadaPor,
            'liberada_em' => $this->liberadaEm,
            'payload' => $this->cru,
        ];
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
