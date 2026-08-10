<?php

namespace Tests\Feature\IntegracaoMultiSistema;

use App\Models\Cliente;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A licença é de um cliente NUM sistema. O mesmo cliente pode usar AlfaGym e
 * AlfaControl com estados diferentes, e durante a implantação de um sistema
 * novo a Matriz lê o estado dele mas não o opera.
 */
class LicencaPorSistemaTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $gym;

    private Sistema $control;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gym = Sistema::factory()->alfagym()->create(['token' => 'chave-gym']);
        $this->control = Sistema::factory()->alfacontrol()->create(['token' => 'chave-control']);

        $revenda = Revenda::create(['nome' => 'Invest Soluções', 'ativo' => true]);

        $this->cliente = Cliente::create([
            'nome' => 'Condomínio Corpo em Movimento',
            'revenda_id' => $revenda->id,
            'ativo' => true,
        ]);

        // O mesmo cliente nos dois sistemas, em estados diferentes.
        $this->cliente->sistemas()->attach($this->gym->id, [
            'ativo' => true, 'status_saas' => 'pendente',
        ]);
        $this->cliente->sistemas()->attach($this->control->id, [
            'ativo' => true, 'status_saas' => 'ativo',
            'licenca_status' => 'ativa', 'licenca_id_externo' => '77',
        ]);

        $this->cliente->ancorarEm($this->gym, '128');
    }

    private function admin(): User
    {
        return User::factory()->create();
    }

    /**
     * @spec:AC-140 Liberar a licença no sistema A não encosta no vínculo do
     * sistema B. Antes, a operação assumia um único sistema por cliente e teria
     * sobrescrito o retrato do outro.
     */
    public function test_liberar_num_sistema_nao_altera_o_vinculo_do_outro(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/licencas' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym',
                'dados' => [
                    'id_externo' => '91', 'cliente_id_externo' => '128',
                    'status' => 'ativa', 'plano' => 'mensal',
                    'inicio_em' => '2026-08-01', 'fim_em' => '2026-09-01',
                ],
            ], 201),
        ]);

        $this->actingAs($this->admin())
            ->post(route('clientes.liberarLicenca', [$this->cliente, $this->gym]), [
                'tipo' => 'mensal', 'valor' => 99, 'obs' => null,
            ])
            ->assertRedirect();

        $noControl = $this->cliente->fresh()->sistemas()
            ->where('sistemas.id', $this->control->id)->first()->pivot;

        $this->assertSame('ativo', $noControl->status_saas);
        $this->assertSame('77', $noControl->licenca_id_externo, 'O vínculo do AlfaControl não podia ser tocado.');

        // E a chamada foi para o gym, com a chave do gym.
        Http::assertSent(fn ($req) => str_starts_with($req->url(), 'https://gym.alfasolucoes.cloud/')
            && $req->hasHeader('X-Matriz-Key', 'chave-gym'));
    }

    /**
     * @spec:AC-142 Sistema que a Matriz não gerencia recusa a operação, e nada
     * sai pela rede. Esconder o botão não é autorização: durante a implantação
     * do AlfaControl quem opera licença é o painel dele.
     */
    public function test_sistema_sem_a_capacidade_recusa_e_nada_e_enviado(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->actingAs($this->admin())
            ->post(route('clientes.renovarLicenca', [$this->cliente, $this->control]), ['tipo' => 'anual'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    /**
     * @spec:AC-142 Vale para as quatro operações — não só para a que a tela
     * mostra hoje.
     */
    public function test_as_quatro_operacoes_recusam_sistema_nao_gerenciado(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $usuario = $this->admin();

        foreach (['liberarLicenca', 'renovarLicenca', 'bloquearLicenca', 'desbloquearLicenca'] as $acao) {
            $this->actingAs($usuario)
                ->post(route("clientes.{$acao}", [$this->cliente, $this->control]), ['tipo' => 'mensal'])
                ->assertStatus(422);
        }

        Http::assertNothingSent();
    }

    /**
     * @spec:AC-141 Cliente que não usa aquele sistema devolve 404 — a operação
     * não tem sobre o que agir.
     */
    public function test_cliente_que_nao_usa_o_sistema_devolve_404(): void
    {
        $outro = Cliente::create(['nome' => 'Sem sistema nenhum', 'ativo' => true]);

        $this->actingAs($this->admin())
            ->post(route('clientes.bloquearLicenca', [$outro, $this->gym]))
            ->assertStatus(404);
    }

    /**
     * @spec:AC-141 A tela não oferece ação de licença para um sistema que a
     * Matriz só lê. O cliente aqui está licenciado apenas no AlfaControl.
     */
    public function test_tela_nao_oferece_acao_para_sistema_so_de_leitura(): void
    {
        $soControl = Cliente::create(['nome' => 'Só no AlfaControl', 'ativo' => true]);
        $soControl->sistemas()->attach($this->control->id, [
            'ativo' => true, 'status_saas' => 'ativo',
            'licenca_status' => 'ativa', 'licenca_id_externo' => '78',
        ]);

        $resposta = $this->actingAs($this->admin())->get(route('clientes.index'));

        $resposta->assertOk();
        $resposta->assertDontSee('clientes/'.$soControl->id.'/sistemas/'.$this->control->id.'/licenca', escape: false);
    }
}
