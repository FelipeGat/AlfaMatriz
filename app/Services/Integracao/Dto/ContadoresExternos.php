<?php

namespace App\Services\Integracao\Dto;

use App\Services\Integracao\ErroIntegracao;

/**
 * O retrato numérico de um sistema numa competência.
 *
 * A quebra por revenda vem pronta do sistema de propósito: é ela que a tela de
 * divergências usa para comparar o que o sistema conta com o que a Alfa
 * faturou, sem somar milhares de linhas do lado da matriz.
 */
class ContadoresExternos
{
    public function __construct(
        public readonly string $competencia,
        public readonly ?string $unidadeCobranca,
        public readonly int $clientesTotal,
        public readonly int $clientesAtivos,
        public readonly int $clientesPendentes,
        public readonly int $clientesBloqueados,
        public readonly int $unidadesAtivas,
        public readonly int $licencasAtivas,
        public readonly int $licencasVencendo,
        public readonly int $licencasVencidas,
        public readonly float $faturadoNoSistema,
        public readonly array $porRevenda,
        public readonly array $cru,
    ) {}

    public static function deArray(array $item): self
    {
        $competencia = trim((string) ($item['competencia'] ?? ''));

        if (! preg_match('/^\d{4}-\d{2}$/', $competencia)) {
            throw ErroIntegracao::respostaInvalida('os contadores vieram com competência inválida.');
        }

        return new self(
            competencia: $competencia,
            unidadeCobranca: $item['unidade_cobranca'] ?? null,
            clientesTotal: (int) ($item['clientes_total'] ?? 0),
            clientesAtivos: (int) ($item['clientes_ativos'] ?? 0),
            clientesPendentes: (int) ($item['clientes_pendentes'] ?? 0),
            clientesBloqueados: (int) ($item['clientes_bloqueados'] ?? 0),
            unidadesAtivas: (int) ($item['unidades_ativas'] ?? 0),
            licencasAtivas: (int) ($item['licencas_ativas'] ?? 0),
            licencasVencendo: (int) ($item['licencas_vencendo'] ?? 0),
            licencasVencidas: (int) ($item['licencas_vencidas'] ?? 0),
            faturadoNoSistema: (float) ($item['faturado_no_sistema'] ?? 0),
            porRevenda: self::normalizarPorRevenda($item['por_revenda'] ?? []),
            cru: $item,
        );
    }

    public function paraEspelho(): array
    {
        return [
            'competencia' => $this->competencia,
            'unidade_cobranca' => $this->unidadeCobranca,
            'clientes_total' => $this->clientesTotal,
            'clientes_ativos' => $this->clientesAtivos,
            'clientes_pendentes' => $this->clientesPendentes,
            'clientes_bloqueados' => $this->clientesBloqueados,
            'unidades_ativas' => $this->unidadesAtivas,
            'licencas_ativas' => $this->licencasAtivas,
            'licencas_vencendo' => $this->licencasVencendo,
            'licencas_vencidas' => $this->licencasVencidas,
            'faturado_no_sistema' => $this->faturadoNoSistema,
            'por_revenda' => $this->porRevenda,
            'coletado_em' => now(),
        ];
    }

    /** Quantas unidades o sistema atribui a uma revenda nesta competência. */
    public function unidadesDaRevenda(string $revendaIdExterno): ?int
    {
        foreach ($this->porRevenda as $linha) {
            if ($linha['revenda_id_externo'] === $revendaIdExterno) {
                return $linha['unidades_ativas'];
            }
        }

        return null;
    }

    /**
     * Sempre a mesma forma, com os tipos certos.
     *
     * Guardar o que o sistema mandou sem normalizar faria a comparação da tela
     * de divergências depender de o outro lado mandar número como número —
     * e "18" diferente de 18 acusaria divergência onde não há.
     */
    private static function normalizarPorRevenda(mixed $bruto): array
    {
        if (! is_array($bruto)) {
            return [];
        }

        $linhas = [];

        foreach ($bruto as $linha) {
            if (! is_array($linha) || ! isset($linha['revenda_id_externo'])) {
                continue;
            }

            $linhas[] = [
                'revenda_id_externo' => (string) $linha['revenda_id_externo'],
                'nome' => (string) ($linha['nome'] ?? ''),
                'clientes_ativos' => (int) ($linha['clientes_ativos'] ?? 0),
                'unidades_ativas' => (int) ($linha['unidades_ativas'] ?? 0),
                'valor' => (float) ($linha['valor'] ?? 0),
            ];
        }

        return $linhas;
    }
}
