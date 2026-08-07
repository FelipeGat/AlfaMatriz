<?php

namespace App\Services\Integracao;

use App\Models\Sistema;

/**
 * Entrega o conector de um sistema.
 *
 * Nenhum serviço instancia conector direto: todos pedem aqui. É essa indireção
 * que permite a suíte inteira rodar sem rede — o teste substitui esta fábrica
 * no contêiner e passa a devolver o dublê, sem que nenhum serviço saiba.
 */
class FabricaDeConector
{
    public function para(Sistema $sistema): ConectorSistema
    {
        return new ConectorHttp($sistema);
    }
}
