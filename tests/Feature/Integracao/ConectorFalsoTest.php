<?php

namespace Tests\Feature\Integracao;

use App\Models\Sistema;
use App\Services\Integracao\ConectorFalso;
use App\Services\Integracao\Dto\ClienteExterno;
use App\Services\Integracao\ErroIntegracao;
use App\Services\Integracao\FabricaDeConector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Amostras;
use Tests\Support\FabricaFalsa;
use Tests\TestCase;

class ConectorFalsoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-078 O dublê responde no mesmo formato do contrato, com as
     * amostras carregadas. É o que permite provar sincronização, importação e
     * telas sem rede — e sem depender de o AlfaGym estar no ar.
     */
    public function test_o_duble_responde_no_formato_do_contrato(): void
    {
        $conector = Amostras::conector();

        $clientes = $conector->clientes();

        $this->assertSame('1.0', $clientes->contrato);
        $this->assertSame('alfagym', $clientes->sistema);
        $this->assertCount(4, $clientes->itens());

        // E o que ele devolve é legível pelos mesmos objetos que leem o
        // sistema de verdade — se não fosse, o dublê provaria outra coisa.
        $cliente = ClienteExterno::deArray($clientes->itens()[0]);
        $this->assertSame('128', $cliente->idExterno);
        $this->assertSame('98765432000155', $cliente->cpfCnpj, 'O documento das amostras vem formatado de propósito.');
    }

    /**
     * @spec:AC-078 O dublê ordena por identificador, como o contrato exige.
     * Um dublê que devolvesse em ordem aleatória esconderia o defeito de
     * paginação que a ordenação estável existe para evitar.
     */
    public function test_o_duble_ordena_como_o_contrato_exige(): void
    {
        $conector = new ConectorFalso(['clientes' => [
            ['id_externo' => '3', 'nome' => 'C'],
            ['id_externo' => '1', 'nome' => 'A'],
            ['id_externo' => '2', 'nome' => 'B'],
        ]]);

        $ids = array_column($conector->clientes()->itens(), 'id_externo');

        $this->assertSame(['1', '2', '3'], $ids);
    }

    /** @spec:AC-078 O dublê pagina, e a resposta sabe se ainda há mais. */
    public function test_o_duble_pagina_e_avisa_quando_ha_mais(): void
    {
        $itens = [];
        for ($i = 1; $i <= 5; $i++) {
            $itens[] = ['id_externo' => (string) $i, 'nome' => "Cliente {$i}"];
        }

        $conector = new ConectorFalso(['clientes' => $itens], tamanhoPagina: 2);

        $primeira = $conector->clientes(1);
        $this->assertCount(2, $primeira->itens());
        $this->assertSame(5, $primeira->totalItens);
        $this->assertSame(3, $primeira->totalPaginas);
        $this->assertTrue($primeira->temProximaPagina());

        $ultima = $conector->clientes(3);
        $this->assertCount(1, $ultima->itens());
        $this->assertFalse($ultima->temProximaPagina());
    }


    /** @spec:AC-089 Os contadores chegam com a competência pedida. */
    public function test_os_contadores_chegam_com_a_competencia_pedida(): void
    {
        $contadores = Amostras::conector()->contadores('2026-08');

        $this->assertSame('2026-08', $contadores->competencia);
        $this->assertSame('academia ativa', $contadores->unidadeCobranca);
        $this->assertSame(2, $contadores->unidadesDaRevenda('3'));
        $this->assertSame(0, $contadores->unidadesDaRevenda('7'));
    }

    /**
     * @spec:AC-082 O dublê sabe ficar fora do ar — sem isso só dá para provar
     * o caminho feliz, e o caminho que a tela precisa saber mostrar fica sem
     * teste.
     */
    public function test_o_duble_sabe_ficar_fora_do_ar(): void
    {
        $conector = Amostras::conector()->falharCom('indisponivel', 503);

        try {
            $conector->clientes();
            $this->fail('Precisava falhar.');
        } catch (ErroIntegracao $erro) {
            $this->assertSame('indisponivel', $erro->codigo);
            $this->assertTrue($erro->ehIndisponibilidade());
        }

        $conector->pararDeFalhar();
        $this->assertCount(4, $conector->clientes()->itens());
    }

    /**
     * @spec:AC-087 O dublê sabe falhar no MEIO de uma varredura, num escopo
     * só. É o único jeito de provar que uma interrupção não marca como ausente
     * quem nem chegou a ser lido.
     */
    public function test_o_duble_sabe_falhar_no_meio_de_uma_varredura(): void
    {
        $conector = Amostras::conector()->falharNoEscopo('licencas', 'erro_interno');

        // Os escopos anteriores continuam respondendo...
        $this->assertCount(2, $conector->revendas()->itens());
        $this->assertCount(4, $conector->clientes()->itens());

        // ...e só o programado falha.
        try {
            $conector->licencas();
            $this->fail('Precisava falhar.');
        } catch (ErroIntegracao $erro) {
            $this->assertSame('erro_interno', $erro->codigo);
        }

        // Na vez seguinte, já passou.
        $this->assertCount(4, $conector->licencas()->itens());
    }

    /**
     * @spec:AC-078 O dublê guarda o que foi pedido, para o teste conferir que
     * a sincronização chamou o que devia — inclusive a competência.
     */
    public function test_o_duble_guarda_o_que_foi_pedido(): void
    {
        $conector = Amostras::conector();

        $conector->clientes(1);
        $conector->clientes(2);
        $conector->contadores('2026-08');

        $this->assertSame(2, $conector->vezesQueChamou('clientes'));
        $this->assertTrue($conector->chamou('contadores'));
        $this->assertFalse($conector->chamou('planos'));

        $this->assertSame('2026-08', $conector->chamadas()[2]['argumentos']['competencia']);
    }

    /**
     * @spec:AC-078 A fábrica é o único caminho para um conector, e o teste
     * consegue trocá-la — é essa indireção que mantém a suíte sem rede.
     */
    public function test_a_fabrica_pode_ser_trocada_pelo_teste(): void
    {
        $sistema = Sistema::factory()->integrado()->create();
        $duble = Amostras::conector();

        $this->app->instance(
            FabricaDeConector::class,
            (new FabricaFalsa)->registrar($sistema, $duble)
        );

        $conector = app(FabricaDeConector::class)->para($sistema);

        $this->assertSame($duble, $conector);
        $this->assertCount(4, $conector->clientes()->itens());
    }

    /**
     * @spec:AC-078 Sistema sem dublê registrado devolve vazio, nunca cai no
     * conector real: um teste que esqueceu de programar um sistema precisa ver
     * "nenhum dado", jamais uma tentativa de rede.
     */
    public function test_sistema_sem_duble_registrado_devolve_vazio(): void
    {
        $sistema = Sistema::factory()->integrado()->create();

        $conector = (new FabricaFalsa)->para($sistema);

        $this->assertInstanceOf(ConectorFalso::class, $conector);
        $this->assertCount(0, $conector->clientes()->itens());
    }

    /** @spec:AC-078 Fora dos testes, a fábrica entrega o conector de verdade. */
    public function test_fora_dos_testes_a_fabrica_entrega_o_conector_de_verdade(): void
    {
        $sistema = Sistema::factory()->integrado()->create();

        $conector = (new FabricaDeConector)->para($sistema);

        $this->assertInstanceOf(\App\Services\Integracao\ConectorHttp::class, $conector);
    }
}
