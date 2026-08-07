<?php

namespace App\Services\Integracao\Dto;

use App\Services\Integracao\ErroIntegracao;
use Carbon\CarbonImmutable;

/**
 * O que o sistema cobra de um cliente pela licença, numa competência.
 *
 * NÃO é o financeiro interno do produto (mensalidade de aluno, conta a receber
 * de condômino, lançamento de família) — esse fica fora do contrato.
 */
class FaturaExterna
{
    private const STATUS = ['pago', 'aberto', 'vencido', 'cancelado'];

    public function __construct(
        public readonly string $idExterno,
        public readonly string $clienteIdExterno,
        public readonly ?string $revendaIdExterno,
        public readonly string $competencia,
        public readonly float $valor,
        public readonly string $moeda,
        public readonly string $status,
        public readonly ?CarbonImmutable $vencimentoEm,
        public readonly ?CarbonImmutable $pagoEm,
        public readonly int $diasEmAtraso,
        public readonly int $unidadesCobradas,
        public readonly ?string $plano,
        public readonly ?string $licencaIdExterno,
        public readonly string $origem,
        public readonly array $cru,
    ) {}

    public static function deArray(array $item): self
    {
        $id = trim((string) ($item['id_externo'] ?? ''));
        $cliente = trim((string) ($item['cliente_id_externo'] ?? ''));
        $competencia = trim((string) ($item['competencia'] ?? ''));

        if ($id === '' || $cliente === '') {
            throw ErroIntegracao::respostaInvalida('uma fatura veio sem identificador ou sem o cliente a que pertence.');
        }

        if (! preg_match('/^\d{4}-\d{2}$/', $competencia)) {
            throw ErroIntegracao::respostaInvalida("a fatura {$id} veio com competência inválida.");
        }

        $status = (string) ($item['status'] ?? 'aberto');

        return new self(
            idExterno: $id,
            clienteIdExterno: $cliente,
            revendaIdExterno: self::texto($item['revenda_id_externo'] ?? null),
            competencia: $competencia,
            valor: (float) ($item['valor'] ?? 0),
            moeda: strtoupper((string) ($item['moeda'] ?? 'BRL')),
            // Situação desconhecida vira "aberto", nunca "pago": tratar como
            // pago o que não se entende esconderia inadimplência.
            status: in_array($status, self::STATUS, true) ? $status : 'aberto',
            vencimentoEm: self::momento($item['vencimento_em'] ?? null),
            pagoEm: self::momento($item['pago_em'] ?? null),
            diasEmAtraso: max(0, (int) ($item['dias_em_atraso'] ?? 0)),
            unidadesCobradas: max(0, (int) ($item['unidades_cobradas'] ?? 0)),
            plano: self::texto($item['plano'] ?? null),
            licencaIdExterno: self::texto($item['licenca_id_externo'] ?? null),
            // "derivado" só quando o sistema declara. O padrão é "titulo",
            // porque uma linha que o sistema considera oficial precisa entrar
            // na comparação de valores da tela de divergências.
            origem: ($item['origem'] ?? 'titulo') === 'derivado' ? 'derivado' : 'titulo',
            cru: $item,
        );
    }

    public function paraEspelho(): array
    {
        return [
            'competencia' => $this->competencia,
            'valor' => $this->valor,
            'moeda' => $this->moeda,
            'status' => $this->status,
            'vencimento_em' => $this->vencimentoEm,
            'pago_em' => $this->pagoEm,
            'dias_em_atraso' => $this->diasEmAtraso,
            'unidades_cobradas' => $this->unidadesCobradas,
            'plano' => $this->plano,
            'licenca_id_externo' => $this->licencaIdExterno,
            'origem' => $this->origem,
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
