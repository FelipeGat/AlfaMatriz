<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A raiz não é uma tela: encaminha para a inicial de quem abre. O teste
     * original vinha do esqueleto do Laravel e esperava 200, o que nunca foi
     * verdade aqui.
     *
     * Deslogado vai direto ao login. Antes passava pelo Centro de Controle
     * para de lá ser expulso — mesmo destino, um salto a mais.
     */
    public function test_a_raiz_manda_o_visitante_deslogado_ao_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
