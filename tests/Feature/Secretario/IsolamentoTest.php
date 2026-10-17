<?php

namespace Tests\Feature\Secretario;

use App\Models\Perfil;
use App\Models\SecretarioItem;
use App\Models\SecretarioProposta;
use App\Services\AgendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Constitution I: item do Secretário é de uma conta só, sem exceção de admin (spec SC-005). */
class IsolamentoTest extends TestCase
{
    use RefreshDatabase, SecretarioNosTestes;

    public function test_admin_nao_recebe_o_secretario_de_fabrica(): void
    {
        $admin = $this->admin();
        $this->assertFalse($admin->canPermissao('secretario', 'ler'));
        $this->actingAs($admin)->get(route('secretario.meu-dia'))->assertForbidden();
        $this->actingAs($admin)->getJson(route('secretario.itens'))->assertForbidden();
    }

    public function test_outra_conta_com_o_perfil_recebe_404_e_nao_ve_nada(): void
    {
        $felipe = $this->dono();
        $item = SecretarioItem::create(['user_id' => $felipe->id, 'tipo' => 'lembrete', 'titulo' => 'Ligar para o Alexandre',
            'quando' => now()->addHour(), 'origem' => 'matriz']);
        $proposta = SecretarioProposta::create(['user_id' => $felipe->id, 'origem' => 'matriz', 'texto' => 'x',
            'dados' => ['tipo' => 'tarefa', 'titulo' => 'x', 'quando' => null, 'so_data' => false], 'expira_em' => now()->addMinutes(30)]);

        $outro = $this->admin();
        $outro->perfis()->attach(Perfil::where('slug', 'secretario')->value('id'));
        $this->actingAs($outro);
        $this->postJson(route('secretario.itens.acao', $item->id), ['acao' => 'concluir'])->assertNotFound();
        $this->postJson(route('secretario.itens.reagendar', $item->id), ['texto' => 'amanhã às 9h'])->assertNotFound();
        $this->postJson(route('secretario.propostas.confirmar', $proposta->id))->assertNotFound();
        $this->getJson(route('secretario.itens'))->assertOk()->assertDontSee('Alexandre');
        $this->assertSame(SecretarioItem::ABERTO, $item->fresh()->status);
    }

    public function test_item_do_secretario_nao_aparece_na_agenda_do_time(): void
    {
        $felipe = $this->dono();
        SecretarioItem::create(['user_id' => $felipe->id, 'tipo' => 'compromisso', 'titulo' => 'Reunião secreta',
            'quando' => now()->addDay()->setTime(15, 0), 'origem' => 'matriz']);
        $itens = app(AgendaService::class)->itens(now()->startOfWeek(), now()->addWeeks(2), []);
        $this->assertStringNotContainsString('Reunião secreta', json_encode($itens));
    }

    public function test_menu_so_para_quem_tem_o_perfil(): void
    {
        $this->actingAs($this->dono())->get(route('secretario.meu-dia'))->assertOk()->assertSee('Meu Dia');
        $semPerfil = $this->admin();
        $this->actingAs($semPerfil)->get(route('dashboard'))->assertDontSee(route('secretario.meu-dia'));
    }
}
