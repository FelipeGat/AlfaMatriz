<?php

namespace Tests\Feature\IntegracaoMultiSistema;

use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A chave de integração é o que mantém o sync vivo. Ela é gravada uma vez e
 * quase nunca revista — então o modo de falha que importa não é digitar errado,
 * é apagar sem perceber ao salvar a tela por outro motivo.
 */
class ChaveDeIntegracaoTest extends TestCase
{
    use RefreshDatabase;

    private function sistema(): Sistema
    {
        return Sistema::factory()->create([
            'slug' => 'alfagym',
            'categoria' => 'saas',
            'unidade_cobranca' => 'academia ativa',
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => 'chave-que-nao-pode-sumir',
        ]);
    }

    /**
     * @spec:AC-153 Salvar a tela de Sistemas sem redigitar a chave mantém a
     * chave atual — é o que o placeholder do campo promete ("preencher para
     * trocar"). Sem isto, corrigir só o endereço desligava a integração.
     */
    public function test_salvar_sem_redigitar_a_chave_mantem_a_chave(): void
    {
        $sistema = $this->sistema();

        $this->actingAs(User::factory()->create())
            ->put(route('sistemas.update', $sistema), [
                'categoria' => 'saas',
                'unidade_cobranca' => 'academia ativa',
                'base_url' => 'https://gym-novo.alfasolucoes.cloud',
                'token' => '',
                'ativo' => '1',
            ])
            ->assertRedirect(route('produtos.index'));

        $sistema->refresh();

        $this->assertSame('chave-que-nao-pode-sumir', $sistema->token);
        $this->assertSame('https://gym-novo.alfasolucoes.cloud', $sistema->base_url);
    }

    /**
     * @spec:AC-153 Enviar uma chave nova troca a chave — o campo continua
     * servindo para o que existe.
     */
    public function test_enviar_chave_nova_troca_a_chave(): void
    {
        $sistema = $this->sistema();

        $this->actingAs(User::factory()->create())
            ->put(route('sistemas.update', $sistema), [
                'categoria' => 'saas',
                'unidade_cobranca' => 'academia ativa',
                'base_url' => 'https://gym.alfasolucoes.cloud',
                'token' => 'chave-nova',
                'ativo' => '1',
            ]);

        $this->assertSame('chave-nova', $sistema->refresh()->token);
    }

    /**
     * @spec:AC-153 Sistema que ainda não tem chave continua podendo receber a
     * primeira — a guarda protege chave existente, não impede configurar.
     */
    public function test_sistema_sem_chave_recebe_a_primeira(): void
    {
        $sistema = Sistema::factory()->create([
            'slug' => 'alfacontrol',
            'categoria' => 'saas',
            'unidade_cobranca' => 'condomínio ativo',
            'base_url' => null,
            'token' => null,
        ]);

        $this->actingAs(User::factory()->create())
            ->put(route('sistemas.update', $sistema), [
                'categoria' => 'saas',
                'unidade_cobranca' => 'condomínio ativo',
                'base_url' => 'https://control.alfasolucoes.cloud',
                'token' => 'primeira-chave',
                'ativo' => '1',
            ]);

        $this->assertSame('primeira-chave', $sistema->refresh()->token);
    }
}
