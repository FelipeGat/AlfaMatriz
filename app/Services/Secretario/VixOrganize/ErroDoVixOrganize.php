<?php

namespace App\Services\Secretario\VixOrganize;

use RuntimeException;

/** Falha do VixOrganize com mensagem pronta para a tela — nunca com a chave nem o corpo cru. */
class ErroDoVixOrganize extends RuntimeException {}
