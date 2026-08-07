<?php

namespace Database\Factories;

use App\Models\SistemaCliente;
use App\Models\SistemaLicenca;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SistemaLicenca>
 */
class SistemaLicencaFactory extends Factory
{
    protected $model = SistemaLicenca::class;

    public function definition(): array
    {
        return [
            'sistema_cliente_id' => SistemaCliente::factory(),
            'id_externo' => (string) fake()->unique()->numberBetween(1000, 99999),
            'status' => 'ativa',
            'plano' => 'Growth',
            'tipo' => 'mensal',
            'inicio_em' => now()->subMonth()->toDateString(),
            'fim_em' => now()->addMonths(2)->toDateString(),
            'bloqueia_acesso' => true,
            'sincronizado_em' => now(),
        ];
    }

    public function configure(): static
    {
        // A licença sempre pertence ao mesmo sistema do cliente dela: deixar
        // isso a cargo de quem escreve o teste é convite para retrato
        // inconsistente que só aparece na tela.
        return $this->afterMaking(function (SistemaLicenca $licenca) {
            $licenca->sistema_id ??= SistemaCliente::find($licenca->sistema_cliente_id)?->sistema_id;
        });
    }

    public function vencendoEm(int $dias): static
    {
        return $this->state(fn () => [
            'status' => 'ativa',
            'fim_em' => now()->addDays($dias)->toDateString(),
        ]);
    }

    public function vencida(): static
    {
        return $this->state(fn () => [
            'status' => 'vencida',
            'fim_em' => now()->subDays(10)->toDateString(),
        ]);
    }

    public function pendente(): static
    {
        return $this->state(fn () => ['status' => 'pendente', 'inicio_em' => null, 'fim_em' => null]);
    }

    /** Sistema em que vencer a licença NÃO barra o acesso de ninguém. */
    public function semBloqueioDeAcesso(): static
    {
        return $this->state(fn () => ['bloqueia_acesso' => false]);
    }
}
