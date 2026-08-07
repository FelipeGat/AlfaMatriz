<?php

namespace Database\Factories;

use App\Models\Revenda;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Revenda>
 */
class RevendaFactory extends Factory
{
    protected $model = Revenda::class;

    public function definition(): array
    {
        return [
            'nome' => fake()->company(),
            // O CNPJ é único na tabela: gerar por sequência evita colisão em
            // cenários com muitas revendas.
            'cnpj' => fake()->unique()->numerify('##.###.###/0001-##'),
            'contato_nome' => fake()->name(),
            'contato_email' => fake()->unique()->safeEmail(),
            'contato_telefone' => fake()->numerify('(14) 9####-####'),
            'ativo' => true,
        ];
    }

    public function inativa(): static
    {
        return $this->state(fn () => ['ativo' => false]);
    }
}
