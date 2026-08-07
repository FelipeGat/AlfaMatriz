<?php

namespace Database\Factories;

use App\Models\Sistema;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sistema>
 */
class SistemaFactory extends Factory
{
    protected $model = Sistema::class;

    public function definition(): array
    {
        return [
            'nome' => fake()->unique()->company(),
            'slug' => 'alfagym',
            'categoria' => 'saas',
            'unidade_cobranca' => 'academia ativa',
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => null,
            'ativo' => true,
        ];
    }
}
