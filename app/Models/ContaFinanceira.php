<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContaFinanceira extends Model
{
    use Auditavel;

    protected string $recursoAuditoria = 'financeiro';

    protected $table = 'contas_financeiras';

    protected $fillable = [
        'nome', 'tipo', 'banco_codigo', 'agencia', 'numero_conta',
        'saldo', 'limite_cartao', 'dia_fechamento_cartao', 'ativo',
    ];

    protected function casts(): array
    {
        return [
            'saldo' => 'decimal:2',
            'limite_cartao' => 'decimal:2',
            'ativo' => 'boolean',
        ];
    }

    public function movimentacoes(): HasMany
    {
        return $this->hasMany(MovimentacaoFinanceira::class);
    }

    public function reprocessarSaldo(): void
    {
        $saldo = 0;

        $this->movimentacoes()->orderBy('data')->orderBy('id')->chunkById(200, function ($lote) use (&$saldo) {
            foreach ($lote as $movimentacao) {
                $saldo += match ($movimentacao->tipo) {
                    'saida' => -$movimentacao->valor,
                    default => $movimentacao->valor,
                };
                $movimentacao->updateQuietly(['saldo_resultante' => $saldo]);
            }
        });

        $this->updateQuietly(['saldo' => $saldo]);
    }
}
