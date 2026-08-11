<?php

namespace Tests\Unit;

use App\Models\Cliente;
use Tests\TestCase;

/**
 * A UF chega em maiúscula no atributo, venha de onde vier.
 *
 * O que existia antes era a classe `uppercase` do Tailwind no formulário, e ela
 * mente: text-transform muda o desenho na tela, não o valor enviado. O teste
 * não passa por HTTP de propósito — é no atributo que a garantia precisa valer,
 * já que o sincronizador também grava UF sem nunca ver o formulário.
 */
class UfDoClienteTest extends TestCase
{
    public function test_minuscula_vira_maiuscula(): void
    {
        $cliente = new Cliente(['uf' => 'es']);

        $this->assertSame('ES', $cliente->uf);
    }

    public function test_espaco_em_volta_nao_sobrevive(): void
    {
        $cliente = new Cliente(['uf' => ' sp ']);

        $this->assertSame('SP', $cliente->uf);
    }

    public function test_maiuscula_passa_intacta(): void
    {
        $cliente = new Cliente(['uf' => 'MG']);

        $this->assertSame('MG', $cliente->uf);
    }

    public function test_sem_uf_continua_sem_uf(): void
    {
        $cliente = new Cliente(['uf' => null]);

        $this->assertNull($cliente->uf);
    }
}
