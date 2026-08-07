<?php

namespace Database\Factories;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cliente>
 */
class ClienteFactory extends Factory
{
    protected $model = Cliente::class;

    public function definition(): array
    {
        return [
            // Sem revenda por padrão: o cliente direto é o caso que o motor de
            // faturamento deixa de fora de propósito, e deixá-lo explícito no
            // teste é melhor do que herdá-lo sem querer.
            'revenda_id' => null,
            'nome' => fake()->company(),
            'cpf_cnpj' => fake()->unique()->numerify('##.###.###/0001-##'),
            'tipo_pessoa' => 'PJ',
            'cidade' => fake()->city(),
            'uf' => 'SP',
            'ativo' => true,
            'tipo_cliente' => 'CONTRATO',
        ];
    }

    public function inativo(): static
    {
        return $this->state(fn () => ['ativo' => false]);
    }
}
