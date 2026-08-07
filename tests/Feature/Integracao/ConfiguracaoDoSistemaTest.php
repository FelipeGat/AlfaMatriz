<?php

namespace Tests\Feature\Integracao;

use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfiguracaoDoSistemaTest extends TestCase
{
    use RefreshDatabase;

    private function comoUsuario(): User
    {
        $usuario = User::factory()->create();
        $this->actingAs($usuario);

        return $usuario;
    }

    /**
     * @spec:AC-080 Salvar o cadastro do sistema sem digitar a chave de novo
     * mantém a chave que já existia.
     *
     * O campo é oculto por segurança e chega sempre vazio. Antes desta
     * correção, salvar qualquer outro campo gravava vazio por cima e desligava
     * a integração em silêncio — invisível enquanto ninguém lia a chave, fatal
     * a partir do momento em que a matriz passa a depender dela.
     */
    public function test_salvar_o_sistema_sem_digitar_a_chave_mantem_a_chave(): void
    {
        $this->comoUsuario();

        $sistema = Sistema::factory()->create([
            'nome' => 'AlfaGym',
            'unidade_cobranca' => 'academia ativa',
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => 'chave-que-nao-pode-sumir',
        ]);

        $this->put(route('sistemas.update', $sistema), [
            'categoria' => 'saas',
            'unidade_cobranca' => 'academia ativa (revisado)',
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => '', // o campo oculto chega assim
            'ativo' => '1',
        ])->assertRedirect();

        $sistema->refresh();

        $this->assertSame('chave-que-nao-pode-sumir', $sistema->token, 'A chave não pode sumir ao salvar outro campo.');
        $this->assertSame('academia ativa (revisado)', $sistema->unidade_cobranca, 'O campo editado precisa ter sido salvo.');
    }

    /**
     * @spec:AC-080 Digitar uma chave nova substitui a anterior — a proteção
     * não pode virar impedimento de trocar a chave.
     */
    public function test_digitar_uma_chave_nova_substitui_a_anterior(): void
    {
        $this->comoUsuario();

        $sistema = Sistema::factory()->create(['token' => 'chave-antiga']);

        $this->put(route('sistemas.update', $sistema), [
            'categoria' => 'saas',
            'unidade_cobranca' => 'academia ativa',
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => 'chave-nova',
            'ativo' => '1',
        ])->assertRedirect();

        $this->assertSame('chave-nova', $sistema->refresh()->token);
    }

    /**
     * @spec:AC-080 A chave fica cifrada no banco: quem abrir a tabela não lê a
     * credencial de integração de cinco sistemas.
     */
    public function test_a_chave_fica_cifrada_no_banco(): void
    {
        $sistema = Sistema::factory()->create(['token' => 'chave-secreta-da-matriz']);

        $guardado = \DB::table('sistemas')->where('id', $sistema->id)->value('token');

        $this->assertNotSame('chave-secreta-da-matriz', $guardado);
        $this->assertStringNotContainsString('chave-secreta-da-matriz', (string) $guardado);
        $this->assertSame('chave-secreta-da-matriz', $sistema->refresh()->token, 'E continua legível pelo painel.');
    }

    /**
     * @spec:AC-079 Sistema sem endereço de integração é recusado dizendo
     * exatamente o que falta — não com um erro técnico nem com um "não foi
     * possível" que manda a pessoa adivinhar entre quatro causas.
     */
    public function test_sistema_sem_endereco_diz_que_falta_o_endereco(): void
    {
        $sistema = Sistema::factory()->create(['base_url' => null, 'token' => 'chave']);

        $this->assertSame('sem_endereco', $sistema->motivoIntegracaoIndisponivel());
        $this->assertFalse($sistema->integracaoConfigurada());
    }

    /** @spec:AC-079 Sistema sem chave é recusado dizendo que falta a chave. */
    public function test_sistema_sem_chave_diz_que_falta_a_chave(): void
    {
        $sistema = Sistema::factory()->create([
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => null,
        ]);

        $this->assertSame('sem_chave', $sistema->motivoIntegracaoIndisponivel());
    }

    /**
     * @spec:AC-079 Sistema desativado e produto fora do escopo têm motivos
     * próprios: o primeiro é uma escolha temporária, o segundo é permanente, e
     * a tela não pode tratar os dois como a mesma coisa.
     */
    public function test_sistema_desativado_e_produto_fora_do_escopo_tem_motivos_proprios(): void
    {
        $desativado = Sistema::factory()->inativo()->create([
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => 'chave',
        ]);
        $this->assertSame('sistema_inativo', $desativado->motivoIntegracaoIndisponivel());

        // O Gestor é da categoria crm e ficou fora do escopo da integração.
        $gestor = Sistema::factory()->create([
            'nome' => 'Gestor',
            'categoria' => 'crm',
            'base_url' => 'https://gestor.alfasolucoes.cloud',
            'token' => 'chave',
        ]);
        $this->assertSame('fora_do_escopo', $gestor->motivoIntegracaoIndisponivel());
    }

    /** @spec:AC-079 Sistema completo e ativo está pronto para sincronizar. */
    public function test_sistema_completo_esta_pronto_para_sincronizar(): void
    {
        $sistema = Sistema::factory()->integrado('https://gym.alfasolucoes.cloud')->create();

        $this->assertNull($sistema->motivoIntegracaoIndisponivel());
        $this->assertTrue($sistema->integracaoConfigurada());
    }

    /**
     * @spec:AC-095 O sistema nasce apenas sendo observado; ser dono do cadastro
     * é uma marca explícita, com data — nunca um estado em que se cai por
     * acidente.
     */
    public function test_o_sistema_nasce_apenas_observado_e_o_corte_e_explicito(): void
    {
        $sistema = Sistema::factory()->create();

        $this->assertFalse($sistema->cadastroNaMatriz(), 'Nenhum sistema nasce com a matriz mandando nele.');
        $this->assertNull($sistema->sincronizado_em);
        $this->assertSame(0, $sistema->falhas_consecutivas);

        $sistema->update(['cadastro_na_matriz_desde' => now()]);

        $this->assertTrue($sistema->refresh()->cadastroNaMatriz());
    }
}
