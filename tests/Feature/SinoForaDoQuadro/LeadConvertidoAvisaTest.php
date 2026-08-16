<?php

namespace Tests\Feature\SinoForaDoQuadro;

use App\Models\Lead;
use App\Models\Notificacao;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O lead convertido é a conclusão do funil (US-094): quem acompanha o
 * comercial fica sabendo sem estar com o quadro aberto. A condição "N leads
 * parados" continua na fila de ação — aqui é o evento, pontual e com dono.
 */
class LeadConvertidoAvisaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-339 Converter avisa quem vê o painel comercial — menos o próprio
     * vendedor que fechou, e nunca quem só trabalha no quadro de tarefas.
     */
    public function test_converter_avisa_quem_ve_o_painel_comercial(): void
    {
        $admin = User::factory()->create(['name' => 'Camila Reis']);
        $membro = User::factory()->membro()->create();

        $vendedor = User::factory()->semPerfil()->create(['name' => 'Sueli Prado']);
        $vendedor->perfis()->attach(Perfil::where('slug', 'comercial')->value('id'));

        $lead = Lead::create([
            'nome' => 'Academia Nova Era',
            'estagio' => 'implantacao',
            'origem' => 'Indicação',
            'vendedor_id' => $vendedor->id,
        ]);

        $this->actingAs($vendedor)->post(route('leads.mover', $lead), [
            'estagio' => 'cliente_ativo',
        ]);

        $avisos = Notificacao::where('tipo', 'lead_convertido')->get();

        $this->assertSame([$admin->id], $avisos->pluck('destinatario_id')->all());

        $aviso = $avisos->first();

        $this->assertSame('marca', $aviso->nivel);
        $this->assertStringContainsString('Academia Nova Era virou cliente', $aviso->titulo);
        $this->assertStringContainsString('Sueli Prado', $aviso->meta);
        $this->assertSame(0, Notificacao::where('destinatario_id', $membro->id)->count());
    }

    /** @spec:AC-339 Mover entre estágios comuns não é conversão e não avisa. */
    public function test_mover_entre_estagios_comuns_nao_avisa(): void
    {
        User::factory()->create();

        $vendedor = User::factory()->create();

        $lead = Lead::create([
            'nome' => 'Academia Nova Era',
            'estagio' => 'qualificacao',
            'origem' => 'Site',
            'vendedor_id' => $vendedor->id,
        ]);

        $this->actingAs($vendedor)->post(route('leads.mover', $lead), [
            'estagio' => 'proposta',
        ]);

        $this->assertSame(0, Notificacao::where('tipo', 'lead_convertido')->count());
    }
}
