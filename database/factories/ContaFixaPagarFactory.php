<?php

namespace Database\Factories;

use App\Models\CentroCusto;
use App\Models\ContaFixaPagar;
use App\Models\Fornecedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContaFixaPagar>
 */
class ContaFixaPagarFactory extends Factory
{
    protected $model = ContaFixaPagar::class;

    public function definition(): array
    {
        return [
            'centro_custo_id' => CentroCusto::factory(),
            'conta_id' => null,
            'fornecedor_id' => Fornecedor::factory(),
            'conta_financeira_id' => null,
            'descricao' => 'Despesa '.fake()->unique()->word(),
            'valor' => 500.00,
            'dia_vencimento' => 10,
            // Começa no passado e não termina: vigente em qualquer competência
            // que o teste escolher, a menos que ele diga o contrário.
            'data_inicio' => now()->subYear()->startOfYear()->toDateString(),
            'data_fim' => null,
            'forma_pagamento' => 'boleto',
            'ativo' => true,
        ];
    }

    public function desativada(): static
    {
        return $this->state(fn () => ['ativo' => false]);
    }

    /** Só passa a valer depois da competência informada (AAAA-MM). */
    public function comecandoDepoisDe(string $competencia): static
    {
        return $this->state(fn () => [
            'data_inicio' => \Carbon\Carbon::createFromFormat('Y-m', $competencia)
                ->startOfMonth()->addMonth()->toDateString(),
        ]);
    }

    /** Já encerrada antes da competência informada (AAAA-MM). */
    public function encerradaAntesDe(string $competencia): static
    {
        $mes = \Carbon\Carbon::createFromFormat('Y-m', $competencia)->startOfMonth();

        return $this->state(fn () => [
            'data_inicio' => $mes->copy()->subYear()->toDateString(),
            'data_fim' => $mes->copy()->subDay()->toDateString(),
        ]);
    }
}
