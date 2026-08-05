<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A raiz não é uma tela: ela encaminha para o painel (routes/web.php).
     * O teste original vinha do esqueleto do Laravel e esperava 200, o que
     * nunca foi verdade neste sistema.
     */
    public function test_a_raiz_encaminha_para_o_painel(): void
    {
        $this->get('/')->assertRedirect(route('dashboard'));
    }
}
