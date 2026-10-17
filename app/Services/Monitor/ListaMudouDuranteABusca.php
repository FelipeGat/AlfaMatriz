<?php

namespace App\Services\Monitor;

use RuntimeException;

/**
 * O total de uma lista paginada do AlfaMonitor mudou entre as páginas (#399):
 * as já juntadas podem repetir ou pular item, e a busca precisa recomeçar.
 */
final class ListaMudouDuranteABusca extends RuntimeException
{
    public function __construct(string $rota)
    {
        parent::__construct("A lista {$rota} do AlfaMonitor mudou durante a busca.");
    }
}
