<?php

namespace Tests\Feature\Integracao;

use App\Models\Sistema;
use App\Models\SistemaCliente;
use App\Models\SistemaContador;
use App\Models\SistemaLicenca;
use App\Services\Integracao\ConectorHttp;
use App\Services\Integracao\ErroIntegracao;
use App\Services\Integracao\FabricaDeConector;
use App\Services\Integracao\SincronizacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\Amostras;
use Tests\Support\FabricaFalsa;
use Tests\TestCase;

/**
 * Prova mecânica do piloto AlfaGym (T-080/T-081).
 *
 * As amostras em tests/Fixtures/Integracao/alfagym/ são o formato EXATO que o
 * AlfaGym produz (os DTOs Java do pacote com.alfagym.matriz): envelope de
 * erro com o catálogo fechado, cliente com a academia como unidade de cobrança
 * (1 por cliente ativo, filiais não contam), licença com início/fim/bloqueia e
 * contadores com a unidade de cobrança "academia ativa".
 *
 * AC-096 mora nos dois lados: a recusa do filtro do AlfaGym (envelope
 * nao_autenticado idêntico para chave ausente ou errada) e o que a matriz faz
 * com ela — o teste abaixo prova a metade que é nossa, e o filtro Java prova a
 * dele.
 */
class ContratoAlfaGymTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = 'chave-secreta-da-matriz-para-o-alfagym';

    protected function setUp(): void
    {
        parent::setUp();

        config(['integracao.espera_entre_tentativas' => 0, 'integracao.tentativas' => 2]);
    }

    /**
     * @spec:AC-096 O envelope de recusa do AlfaGym (formato real do filtro Java)
     * vira um erro nomeado e a chave não aparece em lugar nenhum — e como a
     * recusa é recusa, um único pedido basta.
     */
    public function test_a_recusa_do_alfagym_vira_nao_autenticado_sem_revelar_a_chave(): void
    {
        // Envelope EXATO que o MatrizApiKeyFilter do AlfaGym escreve: idêntico
        // para chave ausente ou chave errada, como o contrato manda.
        Http::fake(['*' => Http::response(
            '{"contrato":"1.0","erro":{"codigo":"nao_autenticado","mensagem":"Credenciais inválidas","detalhes":{}}}',
            401,
            ['Content-Type' => 'application/json']
        )]);

        $conector = new ConectorHttp(Sistema::factory()->create([
            'nome' => 'AlfaGym',
            'base_url' => 'https://gym.alfasolucoes.cloud/',
            'token' => self::CHAVE,
        ]));

        try {
            $conector->clientes();
            $this->fail('A recusa precisava virar erro.');
        } catch (ErroIntegracao $erro) {
            $this->assertSame('nao_autenticado', $erro->codigo);
            $this->assertSame('Credenciais inválidas', $erro->getMessage());
            $this->assertStringNotContainsString(self::CHAVE, $erro->getMessage());
            $this->assertStringNotContainsString(self::CHAVE, (string) $erro);
        }

        Http::assertSentCount(1, 'Recusa do sistema não é repetida.');
    }

    /**
     * @spec:AC-096 A matriz só apresenta a chave para o endereço do contrato:
     * é ela que se identifica ao AlfaGym, com a chave própria, não a do
     * monitoramento.
     */
    public function test_a_matriz_se_apresenta_com_a_chave_propria_no_prefixo_do_contrato(): void
    {
        Http::fake(['*' => Http::response([
            'contrato' => '1.0',
            'sistema' => 'alfagym',
            'pagina' => ['numero' => 1, 'tamanho' => 200, 'total_itens' => 0, 'total_paginas' => 1],
            'dados' => [],
        ])]);

        $conector = new ConectorHttp(Sistema::factory()->create([
            'nome' => 'AlfaGym',
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => self::CHAVE,
        ]));

        $conector->clientes();

        Http::assertSent(function ($pedido) {
            $this->assertSame(
                'https://gym.alfasolucoes.cloud/api/matriz/v1/clientes',
                explode('?', $pedido->url())[0]
            );
            $this->assertSame(self::CHAVE, $pedido->header('X-Matriz-Key')[0]);

            return true;
        });
    }

    /**
     * @spec:AC-096 Com a integração desligada no AlfaGym (hash vazio), todo
     * pedido é recusado — a matriz registra isso como falha de autenticação do
     * sistema, não como sucesso com lista vazia.
     */
    public function test_integracao_desligada_no_alfagym_recusa_todo_pedido(): void
    {
        Http::fake(['*' => Http::response(
            '{"contrato":"1.0","erro":{"codigo":"nao_autenticado","mensagem":"Credenciais inválidas","detalhes":{}}}',
            401
        )]);

        $sistema = Sistema::factory()->create([
            'nome' => 'AlfaGym',
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => self::CHAVE,
        ]);

        $execucao = app(SincronizacaoService::class)->sincronizar($sistema);

        $this->assertSame('parcial', $execucao->status);
        $this->assertSame('nao_autenticado', $execucao->erro_codigo);
        $this->assertSame(0, SistemaCliente::count(), 'Nada entra no retrato com a integração desligada.');
    }

    /**
     * @spec:AC-097 As respostas reais do AlfaGym, no formato do contrato,
     * produzem o retrato local correto: academia vira cliente com a situação
     * certa, a licença traz início/fim e se barra o acesso, e a contagem da
     * unidade de cobrança é o número de academias ativas.
     */
    public function test_as_amostras_reais_do_alfagym_viram_retrato_local_correto(): void
    {
        $sistema = Sistema::factory()->integrado()->create(['nome' => 'AlfaGym']);
        $conector = Amostras::conector('alfagym', sistema: 'alfagym');
        $this->app->instance(FabricaDeConector::class, (new FabricaFalsa)->registrar($sistema, $conector));

        $execucao = app(SincronizacaoService::class)->sincronizar($sistema);

        $this->assertTrue($execucao->deuCerto(), 'A sincronização completa do AlfaGym precisa dar certo.');

        // Academia vira cliente com a situação certa — TRIAL/ACTIVE = ativo,
        // pendente de licença = pendente, suspensa/cancelada = bloqueado.
        $ativa = SistemaCliente::doSistema($sistema)->where('id_externo', '128')->first();
        $this->assertSame('ativo', $ativa->status);
        $this->assertTrue($ativa->ativo);
        $this->assertSame(1, $ativa->unidades_ativas, 'Academia ativa = 1 unidade de cobrança.');

        $pendente = SistemaCliente::doSistema($sistema)->where('id_externo', '130')->first();
        $this->assertSame('pendente', $pendente->status);
        $this->assertSame(0, $pendente->unidades_ativas, 'Sem licença vigente, não conta como academia ativa.');

        $bloqueada = SistemaCliente::doSistema($sistema)->where('id_externo', '131')->first();
        $this->assertSame('bloqueado', $bloqueada->status);
        $this->assertFalse($bloqueada->ativo);

        // A licença traz início, fim e se barra o acesso.
        $licenca = SistemaLicenca::doSistema($sistema)->where('id_externo', '91')->first();
        $this->assertSame('2026-03-01', $licenca->inicio_em->toDateString());
        $this->assertSame('2027-03-01', $licenca->fim_em->toDateString());
        $this->assertTrue($licenca->bloqueia_acesso, 'Vencida, a licença do AlfaGym barra o acesso.');

        $cancelada = SistemaLicenca::doSistema($sistema)->where('id_externo', '94')->first();
        $this->assertSame('cancelada', $cancelada->status);

        // A contagem da unidade de cobrança é o número de academias ativas.
        $contador = SistemaContador::where('sistema_id', $sistema->id)
            ->where('competencia', now()->format('Y-m'))
            ->first();
        $this->assertSame('academia ativa', $contador->unidade_cobranca);
        $this->assertSame(2, $contador->unidades_ativas, 'Duas academias ativas entre as quatro.');
        $this->assertSame(2, $contador->clientes_ativos);
        $this->assertSame(2, $contador->unidadesDaRevenda('3'));
    }

    /**
     * @spec:AC-097 O retrato guarda o que o AlfaGym declarou em /ping: a
     * unidade de cobrança é a academia ativa, e é ela que a tela de
     * divergências confronta com o que a Alfa faturou.
     */
    public function test_o_ping_do_alfagym_declara_a_academia_ativa_como_unidade(): void
    {
        $sistema = Sistema::factory()->integrado()->create(['nome' => 'AlfaGym']);

        Http::fake(['*/api/matriz/v1/ping' => Http::response([
            'contrato' => '1.0',
            'sistema' => 'alfagym',
            'dados' => [
                'sistema' => 'alfagym',
                'versao' => '2026.08.1',
                'contrato' => '1.0',
                'unidade_cobranca' => 'academia ativa',
                'relogio' => now()->toIso8601String(),
                'cadastro_local_aberto' => true,
            ],
        ])]);

        $conector = new ConectorHttp($sistema);

        $ping = $conector->ping();

        $this->assertSame('alfagym', $ping['sistema']);
        $this->assertSame('academia ativa', $ping['unidade_cobranca']);
        $this->assertTrue($ping['cadastro_local_aberto']);
    }
}
