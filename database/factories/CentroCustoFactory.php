<?php

namespace Database\Factories;

use App\Models\CentroCusto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CentroCusto>
 */
class CentroCustoFactory extends Factory
{
    protected $model = CentroCusto::class;

    public function definition(): array
    {
        return [
            'nome' => 'Centro '.fake()->unique()->word(),
            'ativo' => true,
        ];
    }
}
