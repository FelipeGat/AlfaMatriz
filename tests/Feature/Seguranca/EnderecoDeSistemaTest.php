<?php

namespace Tests\Feature\Seguranca;

use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `base_url` carrega o token de integração a cada chamada
 * (`SincronizadorSistemaService`). Um endereço fora dos limites do AC-269 e do
 * AC-270 faria o painel entregar a chave a um servidor escolhido por quem
 * edita o catálogo.
 */
class EnderecoDeSistemaTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $extra */
    private function campos(array $extra = []): array
    {
        return array_merge([
            'nome' => 'AlfaHome',
            'natureza' => 'produto',
            'categoria' => 'saas',
            'unidade_cobranca' => 'família ativa',
            'ativo' => '1',
        ], $extra);
    }

    /** @spec:AC-269 Endereço sem HTTPS é recusado no cadastro. */
    public function test_o_cadastro_recusa_endereco_sem_https(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('sistemas.store'), $this->campos([
                'base_url' => 'http://sistema.alfasolucoes.cloud',
            ]))
            ->assertSessionHasErrors('base_url');

        $this->assertSame(0, Sistema::count());
    }

    /** @spec:AC-269 Endereço sem HTTPS é recusado na edição, e o registro não muda. */
    public function test_a_edicao_recusa_endereco_sem_https_e_nao_muda_o_registro(): void
    {
        $sistema = Sistema::factory()->create([
            'nome' => 'AlfaGym',
            'base_url' => 'https://gym.alfasolucoes.cloud',
        ]);

        $this->actingAs(User::factory()->create())
            ->put(route('sistemas.update', $sistema), $this->campos([
                'nome' => 'AlfaGym',
                'base_url' => 'http://gym.alfasolucoes.cloud',
            ]))
            ->assertSessionHasErrors('base_url');

        $this->assertSame('https://gym.alfasolucoes.cloud', $sistema->refresh()->base_url);
    }

    /**
     * @spec:AC-270 Endereço de máquina interna é recusado no cadastro — a
     * chave de integração não chega a ser enviada a lugar nenhum porque o
     * registro nem se cria.
     *
     * @dataProvider enderecosInternos
     */
    public function test_o_cadastro_recusa_endereco_de_rede_interna(string $host): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('sistemas.store'), $this->campos([
                'base_url' => "https://{$host}",
            ]))
            ->assertSessionHasErrors('base_url');

        $this->assertSame(0, Sistema::count());
    }

    /**
     * @spec:AC-270 Endereço de máquina interna é recusado na edição, e o
     * registro — inclusive a chave já salva — não muda.
     *
     * @dataProvider enderecosInternos
     */
    public function test_a_edicao_recusa_endereco_de_rede_interna(string $host): void
    {
        $sistema = Sistema::factory()->create([
            'nome' => 'AlfaGym',
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => 'chave-legitima',
        ]);

        $this->actingAs(User::factory()->create())
            ->put(route('sistemas.update', $sistema), $this->campos([
                'nome' => 'AlfaGym',
                'base_url' => "https://{$host}",
                'token' => 'chave-roubada',
            ]))
            ->assertSessionHasErrors('base_url');

        $sistema->refresh();
        $this->assertSame('https://gym.alfasolucoes.cloud', $sistema->base_url);
        $this->assertSame('chave-legitima', $sistema->token);
    }

    /** @return array<string, array{0: string}> */
    public static function enderecosInternos(): array
    {
        return [
            'localhost' => ['localhost'],
            'loopback' => ['127.0.0.1'],
            'rede classe A' => ['10.0.0.5'],
            'rede classe B (limite inferior)' => ['172.16.0.1'],
            'rede classe B (limite superior)' => ['172.31.255.254'],
            'rede classe C' => ['192.168.1.1'],
            'link-local' => ['169.254.169.254'],
        ];
    }

    /** Endereço público em HTTPS continua sendo aceito — nada em uso pode quebrar. */
    public function test_endereco_publico_em_https_e_aceito(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('sistemas.store'), $this->campos([
                'base_url' => 'https://gym.alfasolucoes.cloud',
            ]))
            ->assertSessionDoesntHaveErrors('base_url');

        $this->assertSame('https://gym.alfasolucoes.cloud', Sistema::sole()->base_url);
    }
}
