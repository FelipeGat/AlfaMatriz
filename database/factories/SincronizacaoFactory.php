<?php

namespace Database\Factories;

use App\Models\Sincronizacao;
use App\Models\Sistema;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sincronizacao>
 */
class SincronizacaoFactory extends Factory
{
    protected $model = Sincronizacao::class;

    public function definition(): array
    {
        return [
            'sistema_id' => Sistema::factory(),
            'escopo' => 'completa',
            'origem' => 'agendada',
            'status' => 'sucesso',
            'iniciada_em' => now()->subMinutes(2),
            'finalizada_em' => now()->subMinutes(2)->addSeconds(3),
            'duracao_ms' => 3000,
            'itens_lidos' => 40,
            'itens_criados' => 0,
            'itens_atualizados' => 40,
        ];
    }

    public function falha(string $codigo = 'conexao_falhou', string $mensagem = 'Não foi possível falar com o sistema.'): static
    {
        return $this->state(fn () => [
            'status' => 'falha',
            'erro_codigo' => $codigo,
            'erro_mensagem' => $mensagem,
            'itens_lidos' => 0,
            'itens_atualizados' => 0,
        ]);
    }

    public function parcial(): static
    {
        return $this->state(fn () => [
            'status' => 'parcial',
            'erro_codigo' => 'erro_interno',
            'erro_mensagem' => 'A leitura das licenças falhou no meio.',
        ]);
    }
}
