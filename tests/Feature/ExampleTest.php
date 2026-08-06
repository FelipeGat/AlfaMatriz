<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A raiz não é uma tela: encaminha para a inicial do sistema. O teste
     * original vinha do esqueleto do Laravel e esperava 200, o que nunca foi
     * verdade aqui.
     */
    public function test_a_raiz_encaminha_para_a_tela_inicial(): void
    {
        $this->get('/')->assertRedirect(route('centro-controle'));
    }
}
