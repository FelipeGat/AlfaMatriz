<?php

namespace Tests\Feature\IntegracaoMultiSistema;

use App\Models\Cliente;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\SincronizadorSistemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Revenda que chega com o CNPJ de OUTRA revenda da Matriz não derruba o ciclo.
 *
 * O cenário é o do staging em 18/10/2026 (T-412): a mesma empresa existia
 * como duas revendas na Matriz, uma ancorada no AlfaGym e outra no
 * AlfaControl, e o AlfaGym passou a mandar o CNPJ que já era da segunda. O
 * UPDATE colidia no índice único, a exceção escapava do sincronizador e o
 * comando morria ali — o AlfaControl, que vinha depois, nunca sincronizava, e
 * nenhum "parou de sincronizar" chegava a ninguém.
 *
 * O certo é não fundir (fusão errada não tem desfazer) e não cair: o CNPJ da
 * Matriz fica, o resto atualiza, e o conflito aparece no resumo.
 */
class CnpjDeOutraRevendaNaoDerrubaSincronizacaoTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $gym;

    private Sistema $control;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gym = Sistema::factory()->alfagym()->create(['token' => 'chave-gym']);
        $this->control = Sistema::factory()->alfacontrol()->create(['token' => 'chave-control']);
    }

    /** @param array<int, array<string, mixed>> $revendasDoGym */
    private function fake(array $revendasDoGym): void
    {
        $vazio = fn (string $s) => Http::response(['contrato' => '1.0', 'sistema' => $s, 'dados' => []]);

        Http::preventStrayRequests();
        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/revendas*' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym', 'dados' => $revendasDoGym,
            ]),
            '*gym.alfasolucoes.cloud/api/matriz/v1/clientes*' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym', 'dados' => [
                    ['id_externo' => '128', 'nome' => 'Academia Corpo em Movimento',
                        'cpf_cnpj' => '98765432000155', 'ativo' => true, 'status' => 'ativo'],
                ],
            ]),
            '*gym.alfasolucoes.cloud/*' => $vazio('alfagym'),
            '*control.alfasolucoes.cloud/*' => $vazio('alfacontrol'),
        ]);
    }

    public function test_cnpj_que_ja_e_de_outra_revenda_nao_derruba_o_ciclo(): void
    {
        $doGym = Revenda::create(['nome' => 'Carla Araujo', 'cnpj' => '96804599746677']);
        $doGym->ancorarEm($this->gym, '1');
        $doControl = Revenda::create(['nome' => 'Nuno Carvalho', 'cnpj' => '53840880734565']);
        $doControl->ancorarEm($this->control, '2');

        $this->fake([
            ['id_externo' => '1', 'nome' => 'Elisa Melo', 'cnpj' => '53840880734565', 'ativo' => true],
        ]);

        $resultado = (new SincronizadorSistemaService($this->gym))->sincronizar();

        $this->assertTrue($resultado['ok'], 'O ciclo caiu no CNPJ de outra revenda.');
        $this->assertSame(1, $resultado['resumo']['revendas']['cnpj_em_conflito']);

        // O resto atualiza; o CNPJ fica o da Matriz, e a outra não é tocada.
        $this->assertSame('Elisa Melo', $doGym->fresh()->nome);
        $this->assertSame('96804599746677', $doGym->fresh()->cnpj);
        $this->assertSame('Nuno Carvalho', $doControl->fresh()->nome);
        $this->assertSame(2, Revenda::count(), 'Fundiu ou duplicou revenda.');

        // O que vem depois das revendas no ciclo era o que a queda levava junto.
        $this->assertSame(1, Cliente::count(), 'Os clientes não foram sincronizados.');
    }

    public function test_o_comando_segue_para_os_outros_sistemas_e_avisa_o_conflito(): void
    {
        Revenda::create(['nome' => 'Carla Araujo', 'cnpj' => '96804599746677'])->ancorarEm($this->gym, '1');
        Revenda::create(['nome' => 'Nuno Carvalho', 'cnpj' => '53840880734565'])->ancorarEm($this->control, '2');

        $this->fake([
            ['id_externo' => '1', 'nome' => 'Elisa Melo', 'cnpj' => '53840880734565', 'ativo' => true],
        ]);

        $this->artisan('alfa:sincronizar-sistemas')
            ->expectsOutputToContain('1 com CNPJ que já é de outra revenda')
            ->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'control.alfasolucoes.cloud/api/matriz/v1/revendas'));
    }

    /**
     * A variante da criação: a dona do CNPJ já está ancorada em OUTRO id deste
     * mesmo sistema, então a reconciliação por documento a recusa (de
     * propósito) e o fluxo vai para o create() — que colidia do mesmo jeito.
     */
    public function test_revenda_nova_com_cnpj_ocupado_nasce_sem_cnpj(): void
    {
        $dona = Revenda::create(['nome' => 'Empresa', 'cnpj' => '11222333000181']);
        $dona->ancorarEm($this->gym, '1');

        $this->fake([
            ['id_externo' => '1', 'nome' => 'Empresa', 'cnpj' => '11222333000181', 'ativo' => true],
            ['id_externo' => '9', 'nome' => 'Empresa (cópia)', 'cnpj' => '11222333000181', 'ativo' => true],
        ]);

        $resultado = (new SincronizadorSistemaService($this->gym))->sincronizar();

        $this->assertTrue($resultado['ok'], 'O ciclo caiu ao criar revenda com CNPJ ocupado.');
        $this->assertSame(1, $resultado['resumo']['revendas']['criadas']);
        $this->assertSame(1, $resultado['resumo']['revendas']['cnpj_em_conflito']);
        $this->assertNull(Revenda::porOrigemExterna($this->gym, '9')->cnpj);
        $this->assertSame('11222333000181', $dona->fresh()->cnpj);
    }
}
