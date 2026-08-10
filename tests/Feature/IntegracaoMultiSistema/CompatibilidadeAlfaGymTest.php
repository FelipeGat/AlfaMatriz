<?php

namespace Tests\Feature\IntegracaoMultiSistema;

use App\Models\Cliente;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\GerenciadorLicencaService;
use App\Services\ProvisionadorClienteService;
use App\Services\ProvisionadorRevendaService;
use App\Services\SincronizadorSistemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cadeado, não requisito.
 *
 * A integração com o AlfaGym está em produção. A generalização para vários
 * sistemas mexe nos quatro serviços que falam com ele, e o tipo de erro que
 * escaparia de um teste de comportamento é o formato do fio: um campo
 * renomeado, um header perdido, uma rota trocada. O outro lado só reclamaria
 * em produção.
 *
 * Aqui não se testa o que a Matriz grava — isso os testes de cada serviço já
 * fazem. Testa-se exatamente o que sai pela rede.
 */
class CompatibilidadeAlfaGymTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $gym;

    private Revenda $revenda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gym = Sistema::factory()->alfagym()->create(['token' => 'chave-de-teste']);
        $this->revenda = Revenda::create([
            'nome' => 'Invest Soluções',
            'cnpj' => '12345678000199',
            'contato_nome' => 'João',
            'contato_email' => 'contato@invest.com.br',
            'contato_telefone' => '3133334444',
            'ativo' => true,
        ]);
    }

    private function envelope(array $dados): array
    {
        return ['contrato' => '1.0', 'sistema' => 'alfagym', 'dados' => $dados];
    }

    /**
     * O ciclo inteiro num fake só, para o teste falhar no passo exato em que
     * alguém mudar o payload.
     */
    private function fakeDoCicloCompleto(): void
    {
        // Nenhuma requisição pode escapar para a rede: um fake que não casa
        // deixaria o teste conversando com o gym de verdade.
        Http::preventStrayRequests();

        Http::fake([
            '*/api/matriz/v1/revendas' => Http::response($this->envelope([
                'id_externo' => '42', 'nome' => 'Invest Soluções',
                'admin_id_externo' => '7', 'email_admin' => 'admin@invest.com.br',
            ]), 201),

            '*/api/matriz/v1/clientes' => Http::response($this->envelope([
                'id_externo' => '501', 'nome' => 'Academia Corpo em Movimento',
                'status' => 'pendente', 'revenda_id_externo' => '42',
            ]), 201),

            '*/api/matriz/v1/licencas/91/renovar' => Http::response($this->envelope([
                'id_externo' => '92', 'cliente_id_externo' => '501',
                'status' => 'ativa', 'plano' => 'mensal',
                'inicio_em' => '2026-09-01', 'fim_em' => '2026-10-01',
            ]), 201),

            // 92, não 91: renovar emite uma licença nova e o vínculo passa a
            // apontar para ela. Bloquear depois de renovar tem de usar o id
            // novo — apontar para a licença encerrada não surtiria efeito.
            '*/api/matriz/v1/licencas/92/bloquear' => Http::response($this->envelope([
                'id_externo' => '501', 'status' => 'bloqueado',
            ])),

            '*/api/matriz/v1/licencas/92/desbloquear' => Http::response($this->envelope([
                'id_externo' => '501', 'status' => 'ativo',
            ])),

            '*/api/matriz/v1/licencas' => Http::response($this->envelope([
                'id_externo' => '91', 'cliente_id_externo' => '501',
                'status' => 'ativa', 'plano' => 'mensal',
                'inicio_em' => '2026-08-01', 'fim_em' => '2026-09-01',
            ]), 201),
        ]);
    }

    /**
     * O cliente já provisionado ganha o id da licença no vínculo — é o que a
     * renovação e o bloqueio usam para montar a rota.
     */
    private function comLicencaAncorada(Cliente $cliente): Cliente
    {
        $cliente->sistemas()->syncWithoutDetaching([$this->gym->id => [
            'ativo' => true, 'status_saas' => 'pendente', 'licenca_id_externo' => '91',
        ]]);

        return $cliente->fresh();
    }

    /**
     * O ciclo de vida completo, do jeito que o AlfaGym o recebe hoje: verbo,
     * rota, header e chaves do corpo.
     */
    public function test_o_formato_do_fio_do_alfagym_nao_muda(): void
    {
        $this->fakeDoCicloCompleto();

        // 1. provisionar revenda
        (new ProvisionadorRevendaService($this->gym))->provisionar($this->revenda, [
            'nome' => 'Fulano', 'email' => 'admin@invest.com.br', 'senha' => 'senha-forte-123',
        ]);

        $this->assertEnviado('POST', 'https://gym.alfasolucoes.cloud/api/matriz/v1/revendas', [
            'nome_revenda' => 'Invest Soluções',
            'cnpj' => '12345678000199',
            'contato_adm' => 'João',
            'nome_admin' => 'Fulano',
            'email_admin' => 'admin@invest.com.br',
            'senha_admin' => 'senha-forte-123',
        ]);

        // 2. cadastrar cliente (nasce pendente de licença)
        $cliente = Cliente::create([
            'nome' => 'Academia Corpo em Movimento', 'cpf_cnpj' => '98765432000110',
            'cidade' => 'Belo Horizonte', 'uf' => 'MG',
            'revenda_id' => $this->revenda->id, 'ativo' => true,
        ]);

        (new ProvisionadorClienteService($this->gym))->provisionar($cliente->fresh(), [
            'nome_admin' => 'Ciclana', 'email_admin' => 'ciclana@corpo.com.br', 'senha_admin' => 'senha-forte-456',
        ]);

        $this->assertEnviado('POST', 'https://gym.alfasolucoes.cloud/api/matriz/v1/clientes', [
            'revenda_id_externo' => '42',
            'nome' => 'Academia Corpo em Movimento',
            'cnpj' => '98765432000110',
            'cidade' => 'Belo Horizonte',
            'uf' => 'MG',
            'nome_admin' => 'Ciclana',
        ]);

        // 3. liberar licença
        $ancorado = $this->comLicencaAncorada($cliente->fresh());
        $gerenciador = new GerenciadorLicencaService($this->gym);
        $gerenciador->liberar($ancorado, ['tipo' => 'mensal', 'valor' => 99.0, 'obs' => 'primeira']);

        $this->assertEnviado('POST', 'https://gym.alfasolucoes.cloud/api/matriz/v1/licencas', [
            'cliente_id_externo' => '501',
            'tipo' => 'mensal',
            'valor' => 99.0,
            'obs' => 'primeira',
        ]);

        // 4. renovar — o id da licença vai na ROTA, não no corpo
        $gerenciador->renovar($ancorado->fresh(), ['tipo' => 'anual', 'valor' => 999.0, 'obs' => null]);

        $this->assertEnviado('POST', 'https://gym.alfasolucoes.cloud/api/matriz/v1/licencas/91/renovar', [
            'tipo' => 'anual',
            'valor' => 999.0,
        ]);

        // 5 e 6. bloquear e desbloquear — corpo vazio, tudo na rota, e sobre a
        // licença vigente (92, emitida pela renovação acima).
        $gerenciador->bloquear($ancorado->fresh());
        $this->assertEnviado('POST', 'https://gym.alfasolucoes.cloud/api/matriz/v1/licencas/92/bloquear');

        $gerenciador->desbloquear($ancorado->fresh());
        $this->assertEnviado('POST', 'https://gym.alfasolucoes.cloud/api/matriz/v1/licencas/92/desbloquear');
    }

    /**
     * A leitura fala nas mesmas três coleções, paginadas do mesmo jeito.
     */
    public function test_a_leitura_do_alfagym_pede_as_mesmas_colecoes(): void
    {
        Http::fake(['*' => Http::response($this->envelope([]))]);

        (new SincronizadorSistemaService($this->gym))->sincronizar();

        foreach (['revendas', 'clientes', 'licencas'] as $colecao) {
            Http::assertSent(fn ($req) => $req->method() === 'GET'
                && str_starts_with($req->url(), "https://gym.alfasolucoes.cloud/api/matriz/v1/{$colecao}?")
                && $req->hasHeader('X-Matriz-Key', 'chave-de-teste')
                && str_contains($req->url(), 'pagina=1')
                && str_contains($req->url(), 'tamanho=200'));
        }
    }

    /**
     * A chave viaja no header combinado, em toda requisição. Perder isso deixa
     * o outro lado devolvendo 401 para tudo.
     */
    public function test_toda_requisicao_leva_a_chave_no_header(): void
    {
        $this->fakeDoCicloCompleto();

        (new ProvisionadorRevendaService($this->gym))->provisionar($this->revenda, [
            'nome' => 'Fulano', 'email' => 'admin@invest.com.br', 'senha' => 'senha-forte-123',
        ]);

        Http::assertSent(fn ($req) => $req->hasHeader('X-Matriz-Key', 'chave-de-teste'));
    }

    /**
     * @param  array<string, mixed>  $corpo
     */
    private function assertEnviado(string $verbo, string $url, array $corpo = []): void
    {
        Http::assertSent(function ($req) use ($verbo, $url, $corpo) {
            if ($req->method() !== $verbo || $req->url() !== $url) {
                return false;
            }

            if (! $req->hasHeader('X-Matriz-Key', 'chave-de-teste')) {
                return false;
            }

            foreach ($corpo as $chave => $valor) {
                if (($req->data()[$chave] ?? null) !== $valor) {
                    return false;
                }
            }

            return true;
        });
    }
}
