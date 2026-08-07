<?php

namespace Database\Factories;

use App\Models\Fornecedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Fornecedor>
 */
class FornecedorFactory extends Factory
{
    protected $model = Fornecedor::class;

    public function definition(): array
    {
        return [
            'razao_social' => fake()->company().' LTDA',
            'nome_fantasia' => fake()->company(),
            'cpf_cnpj' => fake()->unique()->numerify('##.###.###/0001-##'),
            'email' => fake()->unique()->safeEmail(),
            'telefone' => fake()->numerify('(14) 9####-####'),
            'ativo' => true,
        ];
    }
}
