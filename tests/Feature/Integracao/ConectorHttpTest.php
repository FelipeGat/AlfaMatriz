<?php

namespace Tests\Feature\Integracao;

use App\Models\Sistema;
use App\Services\Integracao\ConectorHttp;
use App\Services\Integracao\ErroIntegracao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConectorHttpTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = 'chave-secreta-da-matriz-para-o-alfagym';

    protected function setUp(): void
    {
        parent::setUp();

        // Sem espera entre tentativas: a regra que interessa é QUANDO repete,
        // não quanto tempo dorme entre uma e outra.
        config(['integracao.espera_entre_tentativas' => 0, 'integracao.tentativas' => 2]);
    }

    private function conector(): ConectorHttp
    {
        return new ConectorHttp(Sistema::factory()->create([
            'nome' => 'AlfaGym',
            'base_url' => 'https://gym.alfasolucoes.cloud/',
            'token' => self::CHAVE,
        ]));
    }

    private function envelope(array $dados, array $pagina = []): array
    {
        return [
            'contrato' => '1.0',
            'sistema' => 'alfagym',
            'gerado_em' => '2026-08-07T13:04:11-03:00',
            'pagina' => $pagina ?: ['numero' => 1, 'tamanho' => 200, 'total_itens' => count($dados), 'total_paginas' => 1],
            'dados' => $dados,
        ];
    }

    /**
     * @spec:AC-084 O conector fala com o endereço do contrato, apresentando a
     * chave da matriz. Um dublê de interface esconderia para sempre um erro de
     * endereço ou de cabeçalho: só a resposta simulada prova isso.
     */
    public function test_o_conector_chama_o_endereco_do_contrato_com_a_chave(): void
    {
        Http::fake(['*' => Http::response($this->envelope([['id_externo' => '128', 'nome' => 'Academia']]))]);

        $this->conector()->clientes();

        Http::assertSent(function (Request $pedido) {
            $this->assertSame(
                'https://gym.alfasolucoes.cloud/api/matriz/v1/clientes',
                explode('?', $pedido->url())[0],
                'O prefixo do contrato precisa ser respeitado, e a barra final do endereço não pode duplicar.'
            );
            $this->assertSame(self::CHAVE, $pedido->header('X-Matriz-Key')[0]);
            $this->assertStringContainsString('pagina=1', $pedido->url());

            return true;
        });
    }


    /**
     * @spec:AC-081 A chave não aparece na mensagem de erro. Ela vaza por onde
     * ninguém olha: uma exceção que carrega o pedido inteiro acaba no relatório
     * de erro, e de lá em qualquer lugar.
     */
    public function test_a_chave_nunca_aparece_na_mensagem_de_erro(): void
    {
        Http::fake(['*' => Http::response(['erro' => ['codigo' => 'nao_autenticado', 'mensagem' => 'Recusado.']], 401)]);

        try {
            $this->conector()->clientes();
            $this->fail('A recusa precisava virar erro.');
        } catch (ErroIntegracao $erro) {
            $this->assertStringNotContainsString(self::CHAVE, $erro->getMessage());
            $this->assertStringNotContainsString(self::CHAVE, json_encode($erro->detalhes));
            $this->assertStringNotContainsString(self::CHAVE, (string) $erro);
        }
    }

    /**
     * @spec:AC-081 Falha de conexão também não pode carregar a chave — e a
     * mensagem original do cliente HTTP traz o pedido junto, por isso ela é
     * descartada em vez de repassada.
     */
    public function test_falha_de_conexao_nao_carrega_a_chave(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException(
            'cURL error 7: Failed to connect to gym.alfasolucoes.cloud with X-Matriz-Key: '.self::CHAVE
        ));

        try {
            $this->conector()->clientes();
            $this->fail('A falha de conexão precisava virar erro.');
        } catch (ErroIntegracao $erro) {
            $this->assertSame('conexao_falhou', $erro->codigo);
            $this->assertStringNotContainsString(self::CHAVE, $erro->getMessage());
        }
    }

    /**
     * @spec:AC-084 Recusa do sistema NÃO é repetida: um "não" não muda por
     * insistência, e repetir transformaria cada 404 em três chamadas inúteis.
     */
    public function test_recusa_do_sistema_nao_e_repetida(): void
    {
        Http::fake(['*' => Http::response(['erro' => ['codigo' => 'cliente_nao_encontrado', 'mensagem' => 'Não achei.']], 404)]);

        try {
            $this->conector()->clientes();
            $this->fail('A recusa precisava virar erro.');
        } catch (ErroIntegracao $erro) {
            $this->assertSame('cliente_nao_encontrado', $erro->codigo);
            $this->assertSame('Não achei.', $erro->getMessage(), 'A mensagem do sistema aparece literalmente.');
        }

        Http::assertSentCount(1);
    }

    /**
     * @spec:AC-084 Erro do servidor É repetido: costuma passar sozinho, e
     * desistir na primeira faria uma sincronização inteira falhar por um soluço.
     */
    public function test_erro_do_servidor_e_repetido(): void
    {
        Http::fake(['*' => Http::response(['erro' => ['codigo' => 'erro_interno', 'mensagem' => 'Deu ruim.']], 500)]);

        try {
            $this->conector()->clientes();
            $this->fail('Precisava terminar em erro.');
        } catch (ErroIntegracao $erro) {
            $this->assertSame('erro_interno', $erro->codigo);
        }

        Http::assertSentCount(2);
    }

    /**
     * @spec:AC-084 Excesso de pedidos também é repetido, embora seja 4xx: é a
     * única recusa que muda por esperar um pouco.
     */
    public function test_excesso_de_pedidos_e_repetido_apesar_de_ser_recusa(): void
    {
        Http::fake(['*' => Http::response(['erro' => ['codigo' => 'limite_de_taxa', 'mensagem' => 'Devagar.']], 429)]);

        try {
            $this->conector()->clientes();
            $this->fail('Precisava terminar em erro.');
        } catch (ErroIntegracao $erro) {
            $this->assertSame('limite_de_taxa', $erro->codigo);
            $this->assertFalse($erro->ehRecusa());
        }

        Http::assertSentCount(2);
    }

    /**
     * @spec:AC-084 Uma falha passageira que se resolve na segunda tentativa
     * não vira erro nenhum para quem chamou.
     */
    public function test_falha_passageira_e_superada_na_segunda_tentativa(): void
    {
        Http::fakeSequence()
            ->push(['erro' => ['codigo' => 'indisponivel', 'mensagem' => 'Em manutenção.']], 503)
            ->push($this->envelope([['id_externo' => '128', 'nome' => 'Academia']]), 200);

        $resposta = $this->conector()->clientes();

        $this->assertCount(1, $resposta->itens());
        Http::assertSentCount(2);
    }

    /**
     * @spec:AC-079 Sistema sem endereço não chega a fazer pedido nenhum: o
     * conector recusa antes, dizendo o que falta.
     */
    public function test_sistema_sem_endereco_recusa_antes_de_chamar(): void
    {
        Http::fake();

        $sistema = Sistema::factory()->create(['base_url' => null, 'token' => 'chave']);

        try {
            (new ConectorHttp($sistema))->clientes();
            $this->fail('Precisava recusar.');
        } catch (ErroIntegracao $erro) {
            $this->assertSame('sem_endereco', $erro->codigo);
            $this->assertTrue($erro->ehConfiguracao());
        }

        Http::assertNothingSent();
    }

    /** @spec:AC-079 Sistema sem chave também recusa antes de chamar. */
    public function test_sistema_sem_chave_recusa_antes_de_chamar(): void
    {
        Http::fake();

        $sistema = Sistema::factory()->create([
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => null,
        ]);

        try {
            (new ConectorHttp($sistema))->clientes();
            $this->fail('Precisava recusar.');
        } catch (ErroIntegracao $erro) {
            $this->assertSame('sem_chave', $erro->codigo);
        }

        Http::assertNothingSent();
    }

    /**
     * @spec:AC-079 Chave que não pode ser lida vira um erro nomeado, não uma
     * exceção crua de decifragem. Acontece quando a chave da aplicação é
     * trocada no servidor: todas as chaves de integração ficam ilegíveis de
     * uma vez, e a mensagem precisa dizer o que houve.
     */
    public function test_chave_ilegivel_vira_erro_nomeado(): void
    {
        Http::fake();

        $sistema = Sistema::factory()->create([
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => 'chave',
        ]);

        // Simula a chave da aplicação tendo mudado: o valor guardado deixa de
        // ser decifrável.
        \DB::table('sistemas')->where('id', $sistema->id)->update(['token' => 'lixo-que-nao-decifra']);

        try {
            (new ConectorHttp($sistema->fresh()))->clientes();
            $this->fail('Precisava recusar.');
        } catch (ErroIntegracao $erro) {
            $this->assertSame('chave_ilegivel', $erro->codigo);
            $this->assertStringContainsString('chave da aplicação', $erro->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * @spec:AC-078 Resposta numa versão de contrato que o painel não entende é
     * recusada, em vez de virar retrato torto.
     */
    public function test_resposta_de_contrato_incompativel_e_recusada(): void
    {
        Http::fake(['*' => Http::response([
            'contrato' => '2.0',
            'sistema' => 'alfagym',
            'dados' => [['id_externo' => '128']],
        ])]);

        try {
            $this->conector()->clientes();
            $this->fail('Precisava recusar.');
        } catch (ErroIntegracao $erro) {
            $this->assertSame('contrato_incompativel', $erro->codigo);
        }
    }

    /** @spec:AC-089 Os contadores chegam como objeto, não como lista. */
    public function test_os_contadores_chegam_como_objeto(): void
    {
        Http::fake(['*' => Http::response([
            'contrato' => '1.0',
            'sistema' => 'alfagym',
            'dados' => [
                'competencia' => '2026-08',
                'unidade_cobranca' => 'academia ativa',
                'unidades_ativas' => 33,
                'por_revenda' => [['revenda_id_externo' => '3', 'unidades_ativas' => 18]],
            ],
        ])]);

        $contadores = $this->conector()->contadores('2026-08');

        $this->assertSame('2026-08', $contadores->competencia);
        $this->assertSame('academia ativa', $contadores->unidadeCobranca);
        $this->assertSame(18, $contadores->unidadesDaRevenda('3'));
    }
}
