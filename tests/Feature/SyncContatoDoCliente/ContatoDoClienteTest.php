<?php

namespace Tests\Feature\SyncContatoDoCliente;

use App\Models\Cliente;
use App\Models\Sistema;
use App\Services\SincronizadorAlfaGymService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O contato do cliente sobrevivendo à sincronização.
 *
 * O AlfaGym manda e-mail e telefone de cada cliente; eles se perdiam na
 * atribuição em massa porque a tabela `clientes` não tem essas colunas — o
 * contato mora em `cliente_emails` e `cliente_telefones`.
 */
class ContatoDoClienteTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $sistema;

    /** O contato que o gym responde agora — trocável entre sincronizações. */
    private ?string $email = null;

    private ?string $telefone = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sistema = Sistema::factory()->alfagym()->create([
            'slug' => 'alfagym',
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => 'chave-de-teste',
            'ativo' => true,
        ]);

        $this->fakeGym();
    }

    /** O contato que o gym passa a responder a partir da próxima chamada. */
    private function contatoNoGym(?string $email, ?string $telefone): void
    {
        $this->email = $email;
        $this->telefone = $telefone;
    }

    /**
     * O gym respondendo com um cliente. Registrado UMA vez, no setUp.
     *
     * `Http::fake()` soma stubs e o primeiro que casa vence: registrar de novo
     * para "trocar" a resposta não troca nada — o teste passaria sem nunca
     * exercitar a mudança. Por isso o contato sai de propriedades lidas no
     * momento da resposta (o stub é uma closure), e trocá-lo é mexer nelas.
     */
    private function fakeGym(): void
    {
        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/revendas*' => fn () => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym',
                'pagina' => ['numero' => 1, 'tamanho' => 200, 'total_itens' => 0, 'total_paginas' => 1],
                'dados' => [],
            ]),
            '*gym.alfasolucoes.cloud/api/matriz/v1/clientes*' => fn () => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym',
                'pagina' => ['numero' => 1, 'tamanho' => 200, 'total_itens' => 1, 'total_paginas' => 1],
                'dados' => [
                    [
                        'id_externo' => '128',
                        'nome' => 'Academia Corpo em Movimento',
                        'razao_social' => 'Corpo em Movimento LTDA',
                        'cpf_cnpj' => '98.765.432/0001-55',
                        'email' => $this->email,
                        'telefone' => $this->telefone,
                        'cidade' => 'Bauru', 'uf' => 'SP',
                        'ativo' => true, 'status' => 'ativo',
                        'revenda_id_externo' => null, 'unidades_ativas' => 1,
                    ],
                ],
            ]),
            '*gym.alfasolucoes.cloud/api/matriz/v1/licencas*' => fn () => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym',
                'pagina' => ['numero' => 1, 'tamanho' => 200, 'total_itens' => 0, 'total_paginas' => 1],
                'dados' => [],
            ]),
        ]);
    }

    private function sincronizar(): void
    {
        $resultado = (new SincronizadorAlfaGymService($this->sistema))->sincronizar();

        $this->assertTrue($resultado['ok'], 'A sincronização falhou: '.($resultado['motivo'] ?? ''));
    }

    private function cliente(): Cliente
    {
        return Cliente::porOrigemExterna($this->sistema, '128')?->fresh(['emails', 'telefones'])
            ?? $this->fail('O cliente não chegou pela sincronização.');
    }

    /** @spec:AC-109 O contato vindo do AlfaGym aparece na ficha do cliente. */
    public function test_contato_do_gym_chega_na_ficha_do_cliente(): void
    {
        $this->contatoNoGym('financeiro@corpo.com', '14988888888');

        $this->sincronizar();

        $cliente = $this->cliente();

        $this->assertCount(1, $cliente->emails, 'O e-mail do AlfaGym se perdeu na sincronização.');
        $this->assertCount(1, $cliente->telefones, 'O telefone do AlfaGym se perdeu na sincronização.');

        $this->assertSame('financeiro@corpo.com', $cliente->emails->first()->email);
        $this->assertSame('14988888888', $cliente->telefones->first()->telefone);

        // Cliente que não tinha contato nenhum: o do gym vira o principal.
        $this->assertTrue($cliente->emails->first()->principal);
        $this->assertTrue($cliente->telefones->first()->principal);
    }

    /** @spec:AC-110 Sincronizar de novo não duplica o contato. */
    public function test_sincronizar_de_novo_nao_duplica(): void
    {
        $this->contatoNoGym('financeiro@corpo.com', '14988888888');

        // O sync roda de hora em hora: empilhar uma cópia por ciclo encheria a
        // ficha do cliente em um dia.
        $this->sincronizar();
        $this->sincronizar();
        $this->sincronizar();

        $cliente = $this->cliente();

        $this->assertCount(1, $cliente->emails);
        $this->assertCount(1, $cliente->telefones);
    }

    /** @spec:AC-111 Contato editado na Matriz não é apagado pelo sync. */
    public function test_contato_cadastrado_na_matriz_sobrevive(): void
    {
        $this->contatoNoGym('financeiro@corpo.com', '14988888888');
        $this->sincronizar();

        // O time complementa a ficha aqui: marca o e-mail do gym como
        // financeiro e acrescenta o do dono.
        $cliente = $this->cliente();
        $cliente->emails()->where('email', 'financeiro@corpo.com')->update(['financeiro' => true]);
        $cliente->emails()->create(['email' => 'dono@corpo.com', 'principal' => false, 'financeiro' => false]);
        $cliente->telefones()->create(['telefone' => '14977777777', 'principal' => false]);

        $this->sincronizar();

        $cliente = $this->cliente();

        // Nada varrido: o sync é um convidado na ficha, não o dono dela.
        $this->assertCount(2, $cliente->emails);
        $this->assertCount(2, $cliente->telefones);
        $this->assertContains('dono@corpo.com', $cliente->emails->pluck('email')->all());
        $this->assertContains('14977777777', $cliente->telefones->pluck('telefone')->all());

        // E a marcação de financeiro, que é decisão de gente, continua de pé.
        $this->assertTrue($cliente->emails->firstWhere('email', 'financeiro@corpo.com')->financeiro);
    }

    /** @spec:AC-111 O principal escolhido na Matriz não é rebaixado pelo sync. */
    public function test_principal_escolhido_na_matriz_nao_e_rebaixado(): void
    {
        // O cliente já existe na Matriz, com um principal escolhido por alguém.
        $cliente = Cliente::create(['nome' => 'Academia Corpo em Movimento', 'ativo' => true]);
        $cliente->ancorarEm($this->sistema, '128');
        $cliente->emails()->create(['email' => 'dono@corpo.com', 'principal' => true, 'financeiro' => false]);

        $this->contatoNoGym('financeiro@corpo.com', null);
        $this->sincronizar();

        $cliente = $this->cliente();

        $this->assertCount(2, $cliente->emails);
        $this->assertTrue($cliente->emails->firstWhere('email', 'dono@corpo.com')->principal);
        // O do gym entra como adicional: o sync não desfaz escolha de gente.
        $this->assertFalse($cliente->emails->firstWhere('email', 'financeiro@corpo.com')->principal);
    }

    /** @spec:AC-112 Cliente sem contato no AlfaGym não ganha registro vazio. */
    public function test_cliente_sem_contato_nao_ganha_registro_em_branco(): void
    {
        $this->contatoNoGym(null, '   ');

        $this->sincronizar();

        $cliente = $this->cliente();

        // Ficha sem contato é a verdade; linha em branco seria ruído que a tela
        // mostraria como se fosse dado.
        $this->assertCount(0, $cliente->emails);
        $this->assertCount(0, $cliente->telefones);
    }

    /** @spec:AC-114 Contato trocado no AlfaGym entra sem apagar o anterior. */
    public function test_telefone_trocado_no_gym_entra_sem_apagar_o_antigo(): void
    {
        $this->contatoNoGym('financeiro@corpo.com', '14988888888');
        $this->sincronizar();

        // O telefone mudou lá.
        $this->contatoNoGym('financeiro@corpo.com', '14966666666');
        $this->sincronizar();

        $cliente = $this->cliente();

        $telefones = $cliente->telefones->pluck('telefone')->all();

        $this->assertContains('14966666666', $telefones, 'O telefone novo do AlfaGym não chegou.');
        // A Matriz não apaga contato sozinha — quem limpa a lista é o time.
        $this->assertContains('14988888888', $telefones);
        $this->assertCount(2, $telefones);
    }

    /** @spec:AC-113 Uma sincronização completa preenche o contato dos já migrados. */
    public function test_sincronizacao_recupera_o_contato_de_quem_ja_migrou(): void
    {
        // O estado de hoje: cliente que veio do sync antes da correção, com
        // âncora e sem contato nenhum.
        $migrado = Cliente::create(['nome' => 'Academia Corpo em Movimento', 'ativo' => true]);
        $migrado->ancorarEm($this->sistema, '128');

        $this->assertCount(0, $migrado->emails);

        $this->contatoNoGym('financeiro@corpo.com', '14988888888');
        $this->sincronizar();

        $cliente = $this->cliente();

        $this->assertSame('financeiro@corpo.com', $cliente->emails->first()?->email);
        $this->assertSame('14988888888', $cliente->telefones->first()?->telefone);

        // Sem duplicar o cliente nem perder a âncora que ele já tinha.
        $this->assertSame(1, Cliente::count());
        $this->assertSame($migrado->id, $cliente->id);
        $this->assertSame('128', $cliente->idExternoNoSistema($this->sistema));
    }
}
