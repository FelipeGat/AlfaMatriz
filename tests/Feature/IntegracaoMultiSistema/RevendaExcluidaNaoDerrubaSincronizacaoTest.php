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
 * Revenda excluída na Matriz não pode derrubar a sincronização.
 *
 * O cenário é o de produção em 12/08/2026: a revenda veio do sistema externo,
 * foi ancorada, e alguém a excluiu na Matriz. O escopo de soft delete a
 * escondia da âncora E da reconciliação por documento, o ciclo seguinte
 * tentava recriá-la — e o índice único de CNPJ, que ainda enxerga a linha
 * excluída, derrubava o ciclo INTEIRO com 1062. De hora em hora, levando
 * junto clientes, licenças e módulos, até alguém reparar que nada sincronizava.
 *
 * O comportamento certo tem dois lados: o ciclo não cai, e a exclusão não é
 * desfeita — excluir foi decisão de gente, e o sincronizador não desfaz
 * decisão tomada por gente.
 */
class RevendaExcluidaNaoDerrubaSincronizacaoTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $gym;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gym = Sistema::factory()->alfagym()->create([
            'slug' => 'alfagym',
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => 'chave-de-teste',
            'ativo' => true,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/revendas*' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym', 'dados' => [
                    ['id_externo' => '77', 'nome' => 'Empresa', 'cnpj' => '11111111111111', 'ativo' => false],
                ],
            ]),
            '*gym.alfasolucoes.cloud/api/matriz/v1/clientes*' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym', 'dados' => [
                    ['id_externo' => '128', 'nome' => 'Academia Corpo em Movimento',
                        'cpf_cnpj' => '98765432000155', 'ativo' => true, 'status' => 'ativo'],
                ],
            ]),
            '*gym.alfasolucoes.cloud/api/matriz/v1/licencas*' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym', 'dados' => [],
            ]),
        ]);
    }

    public function test_o_ciclo_pula_a_revenda_excluida_e_segue_ate_o_fim(): void
    {
        $excluida = Revenda::create(['nome' => 'Empresa', 'cnpj' => '11111111111111', 'ativo' => false]);
        $excluida->ancorarEm($this->gym, '77');
        $excluida->delete();

        $resultado = (new SincronizadorSistemaService($this->gym))->sincronizar();

        $this->assertTrue($resultado['ok'], 'O ciclo caiu na revenda excluída.');
        $this->assertSame(1, $resultado['resumo']['revendas']['ignoradas']);

        // Nem gêmea nem ressuscitada: continua UMA linha, e continua excluída.
        $this->assertSame(1, Revenda::withTrashed()->count());
        $this->assertTrue($excluida->fresh()->trashed(), 'A exclusão foi desfeita pelo sincronizador.');

        // O que vem DEPOIS das revendas no ciclo era o que a queda levava junto.
        $this->assertSame(1, Cliente::count(), 'Os clientes não foram sincronizados.');
    }

    public function test_revenda_excluida_sem_ancora_tambem_e_pulada(): void
    {
        // A variante do cadastro manual: excluída antes de qualquer sistema
        // externo conhecê-la. A reconciliação por documento é o único caminho
        // que a encontra — e precisa encontrá-la para o create() não colidir.
        $excluida = Revenda::create(['nome' => 'Empresa', 'cnpj' => '11111111111111', 'ativo' => false]);
        $excluida->delete();

        $resultado = (new SincronizadorSistemaService($this->gym))->sincronizar();

        $this->assertTrue($resultado['ok'], 'O ciclo caiu na revenda excluída.');
        $this->assertSame(1, $resultado['resumo']['revendas']['ignoradas']);
        $this->assertSame(1, Revenda::withTrashed()->count());
    }
}
