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

    /** `sistemas.slug` é único: sem sequência, o segundo sistema de um teste estoura. */
    private static int $sequencia = 0;

    public function definition(): array
    {
        $nome = fake()->unique()->company();

        return [
            'nome' => $nome,
            'slug' => Str::slug($nome).'-'.(++self::$sequencia),
            'natureza' => 'produto',
            'categoria' => 'saas',
            'unidade_cobranca' => 'unidade ativa',
            'base_url' => null,
            'token' => null,
            'ativo' => true,
            // Sem poder nenhum por padrão: quem precisa de uma capacidade a
            // declara. O contrário faria um teste passar por engano, dando ao
            // sistema um poder que a produção não lhe deu.
            'capacidades' => [],
        ];
    }

    /** O AlfaGym como está em produção: leitura, provisionamento e licença. */
    public function alfagym(): static
    {
        return $this->state([
            'nome' => 'AlfaGym',
            'slug' => 'alfagym',
            'unidade_cobranca' => 'academia ativa',
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'capacidades' => [
                'sincroniza',
                'sincroniza_licencas',
                'provisiona_revenda',
                'provisiona_cliente',
                'exige_admin_no_cliente',
                'gerencia_licenca',
            ],
        ]);
    }

    /**
     * O AlfaControl na Fase 1: só leitura. Quem opera revenda, cliente,
     * licença e módulo continua sendo o painel dele.
     */
    public function alfacontrol(): static
    {
        return $this->state([
            'nome' => 'AlfaControl',
            'slug' => 'alfacontrol',
            'unidade_cobranca' => 'condomínio ativo',
            'base_url' => 'https://control.alfasolucoes.cloud',
            'capacidades' => ['sincroniza', 'sincroniza_modulos'],
        ]);
    }

    /**
     * Um sistema de dentro de casa: existe para a tarefa apontar, e não se
     * vende. Sem categoria e sem unidade de cobrança porque não tem nem uma
     * nem outra — um interno com esses campos preenchidos passaria despercebido
     * num teste que deveria provar que ele fica fora do comercial.
     */
    public function interno(): static
    {
        return $this->state([
            'natureza' => 'interno',
            'categoria' => null,
            'unidade_cobranca' => null,
        ]);
    }

    /** Endereço e chave preenchidos — o sistema passa a ser `integravel()`. */
    public function configurado(string $token = 'chave-de-teste'): static
    {
        return $this->state(fn (array $atributos) => [
            'base_url' => $atributos['base_url'] ?? 'https://sistema.alfasolucoes.cloud',
            'token' => $token,
        ]);
    }
}
