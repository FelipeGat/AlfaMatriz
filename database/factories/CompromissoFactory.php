<?php

namespace Database\Factories;

use App\Models\Compromisso;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Compromisso>
 */
class CompromissoFactory extends Factory
{
    protected $model = Compromisso::class;

    public function definition(): array
    {
        // Nasce no modo Duração, que é o padrão do formulário, e com as quatro
        // colunas do intervalo COERENTES entre si: um factory que deixasse
        // `hora_fim` solta produziria compromisso impossível, e o teste de
        // sobreposição passaria a medir o factory em vez do código.
        $data = Carbon::today()->toDateString();

        return [
            'titulo' => fake()->sentence(3),
            'descricao' => null,
            'data' => $data,
            'hora' => '09:00',
            'data_fim' => $data,
            'hora_fim' => '10:00',
            'duracao_modo' => true,
            'duracao_horas' => 1,
            'criado_por_id' => User::factory(),
            'tarefa_id' => null,
        ];
    }

    /** Um compromisso num dia e horário dados, com o término coerente. */
    public function em(string $data, string $hora, float $horas = 1): static
    {
        return $this->state(function () use ($data, $hora, $horas) {
            $fim = Compromisso::terminoPorDuracao($data, $hora, $horas);

            return [
                'data' => $data,
                'hora' => $hora,
                'data_fim' => $fim->toDateString(),
                'hora_fim' => $fim->format('H:i'),
                'duracao_horas' => $horas,
            ];
        });
    }
}
