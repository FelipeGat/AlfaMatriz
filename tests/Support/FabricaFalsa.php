<?php

namespace Tests\Support;

use App\Models\Sistema;
use App\Services\Integracao\ConectorFalso;
use App\Services\Integracao\ConectorSistema;
use App\Services\Integracao\FabricaDeConector;

/**
 * A fábrica que os testes põem no lugar da de verdade.
 *
 * Guarda um dublê por sistema, para um teste com três sistemas poder programar
 * um respondendo, um fora do ar e um mal configurado — que é justamente o
 * cenário que a tela de integração precisa saber mostrar.
 */
class FabricaFalsa extends FabricaDeConector
{
    /** @var array<int, ConectorSistema> */
    private array $conectores = [];

    public function registrar(Sistema|int $sistema, ConectorSistema $conector): self
    {
        $this->conectores[$sistema instanceof Sistema ? $sistema->id : $sistema] = $conector;

        return $this;
    }

    public function para(Sistema $sistema): ConectorSistema
    {
        // Sistema sem dublê registrado ganha um vazio, em vez de cair no
        // conector real: um teste que esqueceu de programar um sistema deve
        // ver "nenhum dado", nunca uma tentativa de rede.
        return $this->conectores[$sistema->id] ??= new ConectorFalso;
    }

    public function conectorDe(Sistema|int $sistema): ?ConectorSistema
    {
        return $this->conectores[$sistema instanceof Sistema ? $sistema->id : $sistema] ?? null;
    }
}
