<?php

namespace Tests\Feature\IntegracaoMultiSistema;

use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Sistema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O uso real medido na origem — a coleção `/uso` do contrato.
 *
 * O risco que estes testes cobrem é o da cobrança metrada: o AlfaJornada
 * fatura por funcionário ativo, e esse número só existe dentro dele. Um
 * retrato errado aqui não é bug de tela — é boleto errado.
 */
class UsoRealDoSistemaTest extends TestCase
{
    use RefreshDatabase;

    /** As três coleções que o AlfaJornada serve, na resposta mínima que o ciclo aceita. */
    private function fakeJornada(array $uso, array $clientes = [['id_externo' => '7', 'nome' => 'Padaria Estrela', 'ativo' => true]]): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*jornada.alfasolucoes.cloud/api/matriz/v1/revendas*' => $this->envelope('alfajornada', []),
            '*jornada.alfasolucoes.cloud/api/matriz/v1/clientes*' => $this->envelope('alfajornada', $clientes),
            '*jornada.alfasolucoes.cloud/api/matriz/v1/uso*' => $this->envelope('alfajornada', $uso),
        ]);
    }

    private function envelope(string $sistema, array $dados): mixed
    {
        return Http::response(['contrato' => '1.0', 'sistema' => $sistema, 'dados' => $dados]);
    }

    private function pivotDe(Cliente $cliente, Sistema $sistema): object
    {
        return $cliente->fresh()->sistemas()->where('sistemas.id', $sistema->id)->first()->pivot;
    }

    /**
     * @spec:AC-321 O retrato de uso do cliente aparece no vínculo: unidades na
     * unidade de cobrança do sistema, métricas informadas pela origem e a hora
     * da medição.
     */
    public function test_o_uso_do_cliente_e_gravado_no_vinculo(): void
    {
        $jornada = Sistema::factory()->alfajornada()->create(['token' => 'chave-jornada']);

        $this->fakeJornada([[
            'cliente_id_externo' => '7',
            'unidades_ativas' => 42,
            'metricas' => ['funcionarios_ativos' => 42, 'dispositivos_ativos' => 3, 'cnpjs_ativos' => 2],
        ]]);

        $this->artisan('alfa:sincronizar-sistemas', ['--sistema' => 'alfajornada'])->assertSuccessful();

        $cliente = Cliente::porOrigemExterna($jornada, '7');
        $vinculo = $this->pivotDe($cliente, $jornada);

        $this->assertSame(42, (int) $vinculo->uso_unidades);
        $this->assertSame(
            ['funcionarios_ativos' => 42, 'dispositivos_ativos' => 3, 'cnpjs_ativos' => 2],
            $vinculo->metricasDeUso()
        );
        $this->assertNotNull($vinculo->uso_medido_em, 'A hora da medição é o que diz se o retrato é fresco.');
    }

    /**
     * @spec:AC-322 Sistema sem a capacidade não é perguntado sobre uso: o
     * AlfaGym não serve `/uso`, e consultar um endereço que a origem não serve
     * daria 404 a cada ciclo.
     */
    public function test_sistema_sem_a_capacidade_nao_e_perguntado_sobre_uso(): void
    {
        Sistema::factory()->alfagym()->create(['token' => 'chave-gym']);

        Http::preventStrayRequests();
        Http::fake(['*gym.alfasolucoes.cloud/*' => $this->envelope('alfagym', [])]);

        $this->artisan('alfa:sincronizar-sistemas')->assertSuccessful();

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/api/matriz/v1/uso'));
        // O restante do ciclo seguiu normal.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/matriz/v1/clientes'));
    }

    /**
     * @spec:AC-323 Desligar a capacidade apaga o retrato que sobrou — um
     * número congelado que a Matriz não consegue mais confirmar não fica na
     * tela, mesmo padrão do retrato de licença.
     */
    public function test_desligar_a_capacidade_limpa_o_retrato_de_uso(): void
    {
        $jornada = Sistema::factory()->alfajornada()->create([
            'token' => 'chave-jornada',
            'capacidades' => ['sincroniza'], // perdeu o `sincroniza_uso`
        ]);

        $cliente = Cliente::create(['nome' => 'Padaria Estrela', 'ativo' => true]);
        $cliente->ancorarEm($jornada, '7');
        $cliente->sistemas()->attach($jornada->id, [
            'ativo' => true,
            'uso_unidades' => 42,
            'uso_metricas' => json_encode(['funcionarios_ativos' => 42]),
            'uso_medido_em' => now()->subHour(),
        ]);

        $this->fakeJornada(uso: [], clientes: [['id_externo' => '7', 'nome' => 'Padaria Estrela', 'ativo' => true]]);

        $this->artisan('alfa:sincronizar-sistemas', ['--sistema' => 'alfajornada'])->assertSuccessful();

        $vinculo = $this->pivotDe($cliente, $jornada);

        $this->assertNull($vinculo->uso_unidades);
        $this->assertSame([], $vinculo->metricasDeUso());
        $this->assertNull($vinculo->uso_medido_em);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/api/matriz/v1/uso'));
    }

    /**
     * @spec:AC-324 Uso de cliente que a Matriz não conhece não derruba o
     * ciclo: o item é pulado e os demais gravam.
     */
    public function test_uso_de_cliente_desconhecido_e_pulado_sem_derrubar_o_ciclo(): void
    {
        $jornada = Sistema::factory()->alfajornada()->create(['token' => 'chave-jornada']);

        $this->fakeJornada([
            ['cliente_id_externo' => '999', 'unidades_ativas' => 10, 'metricas' => []],
            ['cliente_id_externo' => '7', 'unidades_ativas' => 5, 'metricas' => ['funcionarios_ativos' => 5]],
        ]);

        $this->artisan('alfa:sincronizar-sistemas', ['--sistema' => 'alfajornada'])->assertSuccessful();

        $cliente = Cliente::porOrigemExterna($jornada, '7');
        $this->assertSame(5, (int) $this->pivotDe($cliente, $jornada)->uso_unidades);
    }

    /**
     * @spec:AC-325 O relatório do comando conta o uso aplicado, e o ciclo com
     * movimento deixa o número na auditoria.
     */
    public function test_o_relatorio_e_a_auditoria_contam_o_uso_medido(): void
    {
        Sistema::factory()->alfajornada()->create(['token' => 'chave-jornada']);

        $this->fakeJornada([
            ['cliente_id_externo' => '7', 'unidades_ativas' => 42, 'metricas' => []],
        ]);

        $this->artisan('alfa:sincronizar-sistemas', ['--sistema' => 'alfajornada'])
            ->expectsOutputToContain('Uso: 1 clientes medidos.')
            ->assertSuccessful();

        $rastro = Auditoria::where('recurso', 'sistemas')->where('acao', 'sincronizou')->latest('id')->first();

        $this->assertNotNull($rastro);
        $this->assertArrayHasKey('uso · medidos', $rastro->alteracoes);
    }

    /**
     * @spec:AC-326 Configurar o AlfaJornada basta para ele sincronizar: entra
     * no ciclo sem `--sistema`, com a chave dele no header, e os clientes e o
     * uso aparecem — sem código novo por sistema.
     */
    public function test_alfajornada_configurado_entra_no_ciclo_como_os_demais(): void
    {
        $jornada = Sistema::factory()->alfajornada()->create(['token' => 'chave-jornada']);

        $this->fakeJornada([
            ['cliente_id_externo' => '7', 'unidades_ativas' => 42, 'metricas' => ['cnpjs_ativos' => 2]],
        ]);

        $this->artisan('alfa:sincronizar-sistemas')->assertSuccessful();

        $cliente = Cliente::porOrigemExterna($jornada, '7');

        $this->assertNotNull($cliente, 'O cliente do AlfaJornada tinha de ser espelhado.');
        $this->assertSame(42, (int) $this->pivotDe($cliente, $jornada)->uso_unidades);

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://jornada.alfasolucoes.cloud/api/matriz/v1/uso')
            && $r->hasHeader('X-Matriz-Key', 'chave-jornada'));
    }
}
