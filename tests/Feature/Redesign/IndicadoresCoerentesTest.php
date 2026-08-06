<?php

namespace Tests\Feature\Redesign;

use App\Models\Cliente;
use App\Models\Revenda;
use App\Models\User;
use App\Services\IndicadoresService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndicadoresCoerentesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-044 O mesmo indicador mostra o mesmo número em telas
     * diferentes. Antes, cada painel contava por conta própria — e é assim
     * que os números começam a divergir, deixando quem usa sem saber qual
     * tela está certa.
     */
    public function test_clientes_e_revendas_batem_entre_os_dois_paineis(): void
    {
        $usuario = User::factory()->create();

        $revendaA = Revenda::create(['nome' => 'Revenda A', 'ativo' => true]);
        Revenda::create(['nome' => 'Revenda B', 'ativo' => true]);
        Revenda::create(['nome' => 'Revenda Inativa', 'ativo' => false]);

        foreach (range(1, 4) as $i) {
            Cliente::create(['nome' => "Cliente {$i}", 'revenda_id' => $revendaA->id, 'ativo' => true]);
        }
        Cliente::create(['nome' => 'Cliente direto', 'ativo' => true]);
        Cliente::create(['nome' => 'Cliente inativo', 'ativo' => false]);

        $indicadores = app(IndicadoresService::class);

        // A origem única diz: 5 clientes ativos, 2 revendas ativas.
        $this->assertSame(5, $indicadores->clientesAtivos());
        $this->assertSame(2, $indicadores->revendasAtivas());

        $financeiro = $this->actingAs($usuario)->get(route('dashboard'));
        $comercial = $this->actingAs($usuario)->get(route('comercial'));

        $financeiro->assertOk();
        $comercial->assertOk();

        // As duas telas recebem o MESMO número, vindo da mesma origem.
        $this->assertSame(5, $financeiro->viewData('totalClientes'));
        $this->assertSame(5, $comercial->viewData('totalClientesAtivos'));

        $this->assertSame(2, $financeiro->viewData('totalRevendas'));
        $this->assertSame(2, $comercial->viewData('totalRevendasAtivas'));
    }

    /**
     * @spec:AC-044 Mudar o critério num lugar só não pode fazer as telas
     * divergirem: os painéis consultam o serviço, não contam por conta
     * própria. Substituindo o serviço, as duas telas mudam juntas.
     */
    public function test_as_telas_consultam_o_servico_e_nao_contam_sozinhas(): void
    {
        $usuario = User::factory()->create();

        Cliente::create(['nome' => 'Cliente real', 'ativo' => true]);

        // Um serviço falso devolvendo um número impossível de obter contando.
        $this->instance(IndicadoresService::class, new class extends IndicadoresService
        {
            public function clientesAtivos(): int
            {
                return 4242;
            }
        });

        $financeiro = $this->actingAs($usuario)->get(route('dashboard'));
        $comercial = $this->actingAs($usuario)->get(route('comercial'));

        $this->assertSame(4242, $financeiro->viewData('totalClientes'), 'O painel financeiro ainda conta sozinho.');
        $this->assertSame(4242, $comercial->viewData('totalClientesAtivos'), 'O painel comercial ainda conta sozinho.');
    }
}
