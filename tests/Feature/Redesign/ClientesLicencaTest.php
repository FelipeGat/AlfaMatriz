<?php

namespace Tests\Feature\Redesign;

use App\Models\Cliente;
use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O licenciamento na aba Clientes.
 *
 * Depois que o AlfaGym passou a alimentar as abas existentes, a licença do
 * cliente num sistema deixou de ser um detalhe invisível e virou informação
 * operacional: a tela precisa mostrar, para cada cliente, o estado da licença
 * (ativa / vencendo / vencida / bloqueada) com a vigência, sem quebrar quando
 * o vínculo não tem licença nenhuma.
 */
class ClientesLicencaTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create();
    }

    private function sistema(): Sistema
    {
        return Sistema::create([
            'nome' => 'AlfaGym', 'slug' => 'alfagym',
            'unidade_cobranca' => 'academia ativa', 'ativo' => true,
        ]);
    }

    private function clienteComLicenca(array $licenca): Cliente
    {
        $cliente = Cliente::create([
            'nome' => 'Academia Teste', 'tipo_cliente' => 'CONTRATO',
            'valor_mensal' => 300, 'ativo' => true,
        ]);

        $cliente->sistemas()->attach($this->sistema()->id, $licenca + ['ativo' => true]);

        return $cliente;
    }

    public function test_licenca_ativa_aparece_com_vigencia(): void
    {
        $cliente = $this->clienteComLicenca([
            'licenca_status' => 'ativa', 'plano' => 'Growth',
            'licenca_inicio_em' => '2026-07-01', 'licenca_fim_em' => now()->addDays(20)->toDateString(),
            'bloqueia_acesso' => false,
        ]);

        $this->actingAs($this->operador())
            ->get(route('clientes.index'))
            ->assertOk()
            ->assertSee('ativa')
            ->assertSee('Growth')
            ->assertSee($cliente->nome);
    }

    public function test_licenca_vencida_aparece_em_destaque(): void
    {
        $this->clienteComLicenca([
            'licenca_status' => 'ativa', 'plano' => 'Growth',
            'licenca_fim_em' => now()->subDay()->toDateString(),
            'bloqueia_acesso' => false,
        ]);

        $this->actingAs($this->operador())
            ->get(route('clientes.index'))
            ->assertOk()
            ->assertSee('vencida');
    }

    public function test_licenca_bloqueada_aparece_como_critica(): void
    {
        $this->clienteComLicenca([
            'licenca_status' => 'ativa', 'plano' => 'Growth',
            'licenca_fim_em' => now()->addDays(30)->toDateString(),
            'status_saas' => 'bloqueado',
        ]);

        $this->actingAs($this->operador())
            ->get(route('clientes.index'))
            ->assertOk()
            ->assertSee('bloqueada');
    }

    public function test_cliente_sem_licenca_nao_quebra_a_tela(): void
    {
        Cliente::create([
            'nome' => 'Academia Sem Licenca', 'tipo_cliente' => 'CONTRATO',
            'valor_mensal' => 300, 'ativo' => true,
        ]);

        $this->actingAs($this->operador())
            ->get(route('clientes.index'))
            ->assertOk()
            ->assertSee('Academia Sem Licenca');
    }
}
