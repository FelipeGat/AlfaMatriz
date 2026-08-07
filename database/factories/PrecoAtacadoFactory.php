<?php

namespace Database\Factories;

use App\Models\PrecoAtacado;
use App\Models\Sistema;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrecoAtacado>
 */
class PrecoAtacadoFactory extends Factory
{
    protected $model = PrecoAtacado::class;

    public function definition(): array
    {
        return [
            'sistema_id' => Sistema::factory(),
            // Tier padrão (de todas as revendas) por omissão: é o caso comum, e
            // o tier próprio de uma revenda é sempre uma escolha explícita.
            'revenda_id' => null,
            'nome' => 'Start',
            'preco_base' => 99.00,
            'unidades_inclusas' => 1,
            'valor_excedente_unidade' => null,
            'limite_unidades' => 5,
            'ordem' => 1,
            // Começa no passado para estar vigente em qualquer teste que rode
            // "hoje" — a vigência é avaliada na data da execução (ASM-009).
            'vigencia_inicio' => now()->subYear()->toDateString(),
            'vigencia_fim' => null,
        ];
    }

    /** Tier metrado: sem unidades inclusas, cobra por unidade ativa. */
    public function metrado(float $porUnidade = 10.00): static
    {
        return $this->state(fn () => [
            'nome' => 'Metrado',
            'unidades_inclusas' => 0,
            'valor_excedente_unidade' => $porUnidade,
            'limite_unidades' => null,
        ]);
    }

    /** Tier fechado: preço único até o limite, sem excedente. */
    public function fechado(): static
    {
        return $this->state(fn () => ['valor_excedente_unidade' => null]);
    }

    public function vencido(): static
    {
        return $this->state(fn () => [
            'vigencia_inicio' => now()->subYears(2)->toDateString(),
            'vigencia_fim' => now()->subDay()->toDateString(),
        ]);
    }
}
