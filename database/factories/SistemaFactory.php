<?php

namespace Database\Factories;

use App\Models\Sistema;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Sistema>
 */
class SistemaFactory extends Factory
{
    protected $model = Sistema::class;

    public function definition(): array
    {
        $nome = 'Alfa'.Str::ucfirst(fake()->unique()->word());

        return [
            'nome' => $nome,
            'slug' => Str::slug($nome),
            'categoria' => 'saas',
            'unidade_cobranca' => 'cliente ativo',
            'ativo' => true,
        ];
    }

    public function inativo(): static
    {
        return $this->state(fn () => ['ativo' => false]);
    }

    /** Um sistema com endereço e chave de integração já preenchidos. */
    public function integrado(string $baseUrl = 'https://exemplo.invalido'): static
    {
        return $this->state(fn () => [
            'base_url' => $baseUrl,
            'token' => 'chave-de-teste',
        ]);
    }
}
