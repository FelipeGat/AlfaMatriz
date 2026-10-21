<?php

namespace App\Services\MercadoPago;

use RuntimeException;

/** Falha do Mercado Pago com mensagem pronta para a tela — nunca com o token nem o corpo cru. */
class ErroDoMercadoPago extends RuntimeException {}
