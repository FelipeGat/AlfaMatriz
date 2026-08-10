<?php

namespace Database\Factories;

use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tarefa>
 */
class TarefaFactory extends Factory
{
    protected $model = Tarefa::class;

    public function definition(): array
    {
        return [
            'titulo' => fake()->sentence(4),
            'resumo' => fake()->sentence(8),
            'detalhes' => fake()->paragraph(),
            'sistema_id' => null,
            'responsavel_id' => null,
            'criado_por_id' => User::factory(),
            'prioridade' => 'media',
        ];
    }
}
