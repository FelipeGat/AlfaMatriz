<?php

namespace Database\Factories;

use App\Models\Sistema;
use App\Models\SistemaRevenda;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SistemaRevenda>
 */
class SistemaRevendaFactory extends Factory
{
    protected $model = SistemaRevenda::class;

    public function definition(): array
    {
        return [
            'sistema_id' => Sistema::factory(),
            'id_externo' => (string) fake()->unique()->numberBetween(1, 999),
            'nome' => fake()->company(),
            'cnpj' => fake()->unique()->numerify('##############'),
            'ativo' => true,
            'clientes_ativos' => 0,
            'sincronizado_em' => now(),
        ];
    }

    public function semVinculo(): static
    {
        return $this->state(fn () => ['revenda_id' => null, 'vinculo_origem' => null]);
    }
}
