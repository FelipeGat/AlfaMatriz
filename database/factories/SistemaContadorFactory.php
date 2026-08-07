<?php

namespace Database\Factories;

use App\Models\Sistema;
use App\Models\SistemaContador;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SistemaContador>
 */
class SistemaContadorFactory extends Factory
{
    protected $model = SistemaContador::class;

    public function definition(): array
    {
        return [
            'sistema_id' => Sistema::factory(),
            'competencia' => now()->format('Y-m'),
            'unidade_cobranca' => 'cliente ativo',
            'clientes_total' => 0,
            'clientes_ativos' => 0,
            'unidades_ativas' => 0,
            'licencas_ativas' => 0,
            'por_revenda' => [],
            'coletado_em' => now(),
        ];
    }

    /** A quebra por revenda, que é o que a tela de divergências compara. */
    public function comRevenda(string $idExterno, int $unidades, string $nome = 'Revenda'): static
    {
        return $this->state(function (array $atributos) use ($idExterno, $unidades, $nome) {
            $linhas = $atributos['por_revenda'] ?? [];
            $linhas[] = [
                'revenda_id_externo' => $idExterno,
                'nome' => $nome,
                'clientes_ativos' => $unidades,
                'unidades_ativas' => $unidades,
            ];

            return ['por_revenda' => $linhas];
        });
    }
}
