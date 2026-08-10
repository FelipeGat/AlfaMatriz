<?php

namespace Tests\Feature\IntegracaoMultiSistema;

use App\Models\Cliente;
use App\Models\ClienteModulo;
use App\Models\Modulo;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\SincronizadorSistemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Módulos são adicionais cobrados à parte da licença.
 *
 * Na Fase 1 a Matriz só os lê: contratar e cancelar continua no painel do
 * AlfaControl. Mas o valor precisa chegar aqui desde já, porque é ele que
 * compõe a receita real da revenda.
 */
class ModulosContratadosTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $control;

    private Cliente $cliente;

    /** @var array<int, array<string, mixed>> */
    private array $catalogo = [];

    /** @var array<int, array<string, mixed>> */
    private array $contratados = [];

    private bool $fakeRegistrado = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->control = Sistema::factory()->alfacontrol()->create(['token' => 'chave-control']);

        $revenda = Revenda::create(['nome' => 'Invest', 'cnpj' => '12345678000199', 'ativo' => true]);
        $revenda->ancorarEm($this->control, '900');

        $this->cliente = Cliente::create([
            'nome' => 'Condomínio Central', 'revenda_id' => $revenda->id, 'ativo' => true,
        ]);
        $this->cliente->ancorarEm($this->control, '501');
    }

    /**
     * Um fake só, que lê o estado atual a cada requisição.
     *
     * `Http::fake()` chamado duas vezes ACUMULA stubs e o primeiro registrado
     * vence — registrar de novo para "trocar" a resposta não troca nada, e o
     * teste passaria a exercitar o cenário anterior sem avisar.
     *
     * @param  array<int, array<string, mixed>>  $catalogo
     * @param  array<int, array<string, mixed>>  $contratados
     */
    private function fake(array $catalogo = [], array $contratados = []): void
    {
        $this->catalogo = $catalogo;
        $this->contratados = $contratados;

        if ($this->fakeRegistrado) {
            return;
        }

        $this->fakeRegistrado = true;

        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $dados = match (true) {
                str_contains($request->url(), '/modulos-contratados') => $this->contratados,
                str_contains($request->url(), '/modulos') => $this->catalogo,
                default => [],
            };

            return Http::response(['contrato' => '1.0', 'sistema' => 'alfacontrol', 'dados' => $dados]);
        });
    }

    private function sincronizar(): array
    {
        return (new SincronizadorSistemaService($this->control))->sincronizar();
    }

    /** @return array<string, mixed> */
    private function itemDoCatalogo(string $codigo = 'FINANCEIRO'): array
    {
        return ['id_externo' => '3', 'codigo' => $codigo, 'nome' => 'Financeiro',
            'descricao' => 'Contas e mensalidades', 'ativo' => true];
    }

    /** @return array<string, mixed> */
    private function itemContratado(array $extra = []): array
    {
        return array_merge([
            'id_externo' => '9', 'cliente_id_externo' => '501', 'modulo_id_externo' => '3',
            'modulo_codigo' => 'FINANCEIRO', 'status' => 'ativo',
            'inicio_em' => '2026-01-01', 'fim_em' => null,
            'valor_mensal' => 49.90, 'observacao' => null,
        ], $extra);
    }

    /**
     * @spec:AC-146 O catálogo é lido por sistema e rodar de novo não duplica.
     */
    public function test_catalogo_e_lido_e_nao_duplica(): void
    {
        $this->fake(catalogo: [$this->itemDoCatalogo()]);

        $this->assertTrue($this->sincronizar()['ok']);
        $this->sincronizar();

        $this->assertSame(1, Modulo::count());

        $modulo = Modulo::firstOrFail();
        $this->assertSame('FINANCEIRO', $modulo->codigo);
        $this->assertSame($this->control->id, $modulo->sistema_id);
        $this->assertTrue($modulo->ativo);
    }

    /**
     * @spec:AC-147 A contratação grava status, vigência e valor — o valor é o
     * que alimenta o faturamento.
     */
    public function test_contratacao_grava_vigencia_e_valor(): void
    {
        $this->fake(catalogo: [$this->itemDoCatalogo()], contratados: [$this->itemContratado()]);

        $this->assertTrue($this->sincronizar()['ok']);

        $contratacao = ClienteModulo::firstOrFail();

        $this->assertSame($this->cliente->id, $contratacao->cliente_id);
        $this->assertSame('ativo', $contratacao->status);
        $this->assertSame('2026-01-01', $contratacao->data_inicio->toDateString());
        $this->assertNull($contratacao->data_fim);
        $this->assertSame('49.90', (string) $contratacao->valor_mensal);
        // Ancorada, para a Fase 2 poder operar sobre ela.
        $this->assertSame('9', $contratacao->idExternoNoSistema($this->control));
    }

    /**
     * @spec:AC-147 Mudança de status na origem é refletida, sem duplicar.
     */
    public function test_mudanca_de_status_na_origem_e_refletida(): void
    {
        $this->fake(catalogo: [$this->itemDoCatalogo()], contratados: [$this->itemContratado()]);
        $this->sincronizar();

        $this->fake(
            catalogo: [$this->itemDoCatalogo()],
            contratados: [$this->itemContratado(['status' => 'suspenso'])]
        );
        $this->sincronizar();

        $this->assertSame(1, ClienteModulo::count());
        $this->assertSame('suspenso', ClienteModulo::firstOrFail()->status);
    }

    /**
     * @spec:AC-148 Contratação que some da origem é encerrada, não apagada — o
     * faturamento precisa da memória do período.
     */
    public function test_contratacao_que_some_e_encerrada_e_nao_apagada(): void
    {
        $this->fake(catalogo: [$this->itemDoCatalogo()], contratados: [$this->itemContratado()]);
        $this->sincronizar();

        $this->fake(catalogo: [$this->itemDoCatalogo()], contratados: []);
        $this->sincronizar();

        $contratacao = ClienteModulo::firstOrFail();
        $this->assertSame('inativo', $contratacao->status);
        $this->assertNotNull($contratacao->data_fim);
    }

    /**
     * @spec:AC-148 Módulo que sai do catálogo vira inativo, nunca apagado:
     * remover levaria junto, em cascata, o histórico de contratações.
     */
    public function test_modulo_que_sai_do_catalogo_vira_inativo(): void
    {
        $this->fake(catalogo: [$this->itemDoCatalogo()], contratados: [$this->itemContratado()]);
        $this->sincronizar();

        $this->fake(catalogo: [$this->itemDoCatalogo('REFEITORIO')], contratados: []);
        $this->sincronizar();

        $financeiro = Modulo::where('codigo', 'FINANCEIRO')->firstOrFail();
        $this->assertFalse($financeiro->ativo);
        $this->assertSame(1, ClienteModulo::count(), 'O histórico de contratação não pode sumir.');
    }

    /**
     * @spec:AC-149 Sistema sem a capacidade não é consultado sobre módulos. O
     * AlfaGym não tem módulos: pedir a ele daria 404 a cada ciclo.
     */
    public function test_sistema_sem_a_capacidade_nao_consulta_modulos(): void
    {
        $gym = Sistema::factory()->alfagym()->create(['token' => 'chave-gym']);

        Http::preventStrayRequests();
        Http::fake(['*gym.alfasolucoes.cloud/*' => Http::response([
            'contrato' => '1.0', 'sistema' => 'alfagym', 'dados' => [],
        ])]);

        (new SincronizadorSistemaService($gym))->sincronizar();

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/modulos'));
    }

    /**
     * @spec:AC-147 Contratação de cliente que a Matriz ainda não conhece é
     * ignorada, em vez de criar registro órfão.
     */
    public function test_contratacao_de_cliente_desconhecido_e_ignorada(): void
    {
        $this->fake(
            catalogo: [$this->itemDoCatalogo()],
            contratados: [$this->itemContratado(['cliente_id_externo' => '999'])]
        );

        $this->assertTrue($this->sincronizar()['ok']);
        $this->assertSame(0, ClienteModulo::count());
    }

    /**
     * @spec:AC-146 O casamento é pelo código, não pelo id numérico: o código é
     * único e estável entre ambientes.
     */
    public function test_o_casamento_e_pelo_codigo(): void
    {
        $this->fake(catalogo: [$this->itemDoCatalogo()], contratados: [$this->itemContratado()]);
        $this->sincronizar();

        // Mesma contratação, id do módulo diferente na origem (outro ambiente).
        $this->fake(
            catalogo: [array_merge($this->itemDoCatalogo(), ['id_externo' => '77'])],
            contratados: [$this->itemContratado(['modulo_id_externo' => '77'])]
        );
        $this->sincronizar();

        $this->assertSame(1, Modulo::count());
        $this->assertSame(1, ClienteModulo::count());
    }
}
