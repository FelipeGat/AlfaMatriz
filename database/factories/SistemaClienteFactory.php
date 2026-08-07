<?php

namespace Database\Factories;

use App\Models\Sistema;
use App\Models\SistemaCliente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SistemaCliente>
 */
class SistemaClienteFactory extends Factory
{
    protected $model = SistemaCliente::class;

    public function definition(): array
    {
        return [
            'sistema_id' => Sistema::factory(),
            'id_externo' => (string) fake()->unique()->numberBetween(1000, 99999),
            'nome' => fake()->company(),
            'cpf_cnpj' => fake()->unique()->numerify('##############'),
            'cidade' => fake()->city(),
            'uf' => 'SP',
            'ativo' => true,
            'status' => 'ativo',
            'unidades_ativas' => 1,
            'sincronizado_em' => now(),
        ];
    }

    /** Ainda não ligado a nenhum cliente da matriz: vira pendência. */
    public function semVinculo(): static
    {
        return $this->state(fn () => ['cliente_id' => null, 'vinculo_origem' => null]);
    }

    public function semDocumento(): static
    {
        return $this->state(fn () => ['cpf_cnpj' => null, 'status' => 'pendente']);
    }

    public function bloqueado(): static
    {
        return $this->state(fn () => ['ativo' => false, 'status' => 'bloqueado', 'unidades_ativas' => 0]);
    }

    public function pendente(): static
    {
        return $this->state(fn () => ['status' => 'pendente']);
    }

    public function ausenteNaOrigem(): static
    {
        return $this->state(fn () => ['ausente_em_origem_em' => now()->subDays(3), 'ativo' => false]);
    }
}
