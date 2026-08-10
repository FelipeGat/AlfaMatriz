<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\SincronizadorAlfaGymService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SincronizadorAlfaGymTest extends TestCase
{
    use RefreshDatabase;

    private function sistemaComConfiguracao(): Sistema
    {
        return Sistema::factory()->create([
            'slug' => 'alfagym',
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => 'chave-de-teste',
            'ativo' => true,
        ]);
    }

    private function fakeRespostas(): void
    {
        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/revendas*' => Http::response([
                'contrato' => '1.0',
                'sistema' => 'alfagym',
                'pagina' => ['numero' => 1, 'tamanho' => 200, 'total_itens' => 2, 'total_paginas' => 1],
                'dados' => [
                    ['id_externo' => '1', 'nome' => 'Invest Soluções', 'cnpj' => '12.345.678/0001-99',
                        'email' => 'contato@invest.com', 'telefone' => '14999999999', 'ativo' => true, 'clientes_ativos' => 5],
                    ['id_externo' => '2', 'nome' => 'Carla Araujo', 'cnpj' => '03555986426720',
                        'email' => null, 'telefone' => null, 'ativo' => false, 'clientes_ativos' => 0],
                ],
            ]),
            '*gym.alfasolucoes.cloud/api/matriz/v1/clientes*' => Http::response([
                'contrato' => '1.0',
                'sistema' => 'alfagym',
                'pagina' => ['numero' => 1, 'tamanho' => 200, 'total_itens' => 1, 'total_paginas' => 1],
                'dados' => [
                    ['id_externo' => '128', 'nome' => 'Academia Corpo em Movimento',
                        'razao_social' => 'Corpo em Movimento LTDA', 'cpf_cnpj' => '98.765.432/0001-55',
                        'email' => 'financeiro@corpo.com', 'telefone' => '14988888888',
                        'cidade' => 'Bauru', 'uf' => 'SP', 'ativo' => true, 'status' => 'ativo',
                        'revenda_id_externo' => '1', 'unidades_ativas' => 1,
                        'criado_em' => '2025-03-11T09:22:00-03:00', 'atualizado_em' => '2026-08-01T17:40:12-03:00'],
                ],
            ]),
            '*gym.alfasolucoes.cloud/api/matriz/v1/licencas*' => Http::response([
                'contrato' => '1.0',
                'sistema' => 'alfagym',
                'pagina' => ['numero' => 1, 'tamanho' => 200, 'total_itens' => 1, 'total_paginas' => 1],
                'dados' => [
                    ['id_externo' => '91', 'cliente_id_externo' => '128', 'revenda_id_externo' => '1',
                        'status' => 'ativa', 'plano' => 'Growth', 'plano_id_externo' => '2',
                        'tipo' => 'mensal', 'inicio_em' => '2026-07-01', 'fim_em' => '2026-08-01',
                        'dias_para_vencer' => 24, 'bloqueia_acesso' => true,
                        'liberada_por' => 'Rossini', 'liberada_em' => '2026-07-01T10:12:00-03:00'],
                ],
            ]),
        ]);
    }

    public function test_sincroniza_revendas_clientes_e_licencas_do_alfagym(): void
    {
        $sistema = $this->sistemaComConfiguracao();
        $this->fakeRespostas();

        $resultado = (new SincronizadorAlfaGymService($sistema))->sincronizar();

        $this->assertTrue($resultado['ok']);

        $this->assertSame(2, Revenda::count());
        $this->assertSame('Invest Soluções', Revenda::porOrigemExterna($sistema, '1')->nome);
        $this->assertSame('12345678000199', Revenda::porOrigemExterna($sistema, '1')->cnpj);

        $cliente = Cliente::porOrigemExterna($sistema, '128');
        $this->assertNotNull($cliente);
        $this->assertSame('Academia Corpo em Movimento', $cliente->nome);
        $this->assertSame('98765432000155', $cliente->cpf_cnpj);
        $this->assertSame(Revenda::porOrigemExterna($sistema, '1')->id, $cliente->revenda_id);

        $vinculo = $cliente->sistemas()->where('sistemas.id', $sistema->id)->first();
        $this->assertNotNull($vinculo);
        $this->assertSame('ativa', $vinculo->pivot->licenca_status);
        $this->assertSame('Growth', $vinculo->pivot->plano);
        $this->assertSame('2026-08-01', $vinculo->pivot->licenca_fim_em);
        // O campo `bloqueia_acesso` do gym é a POLÍTICA da licença (bloquear ao
        // vencer), sempre verdadeira; no vínculo ele deriva do status real do
        // cliente, então um cliente ATIVO tem acesso não bloqueado.
        $this->assertSame(0, (int) $vinculo->pivot->bloqueia_acesso);
        $this->assertSame('ativo', $vinculo->pivot->status_saas);
    }

    public function test_rodar_de_novo_nao_duplica(): void
    {
        $sistema = $this->sistemaComConfiguracao();
        $this->fakeRespostas();

        $servico = new SincronizadorAlfaGymService($sistema);

        $servico->sincronizar();
        $servico->sincronizar();

        $this->assertSame(2, Revenda::count());
        $this->assertSame(1, Cliente::count());
    }

    public function test_sistema_sem_configuracao_devolve_motivo(): void
    {
        $sistema = Sistema::factory()->create(['base_url' => null, 'token' => null]);

        $resultado = (new SincronizadorAlfaGymService($sistema))->sincronizar();

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('sem endereço', strtolower($resultado['motivo']));
    }
}
