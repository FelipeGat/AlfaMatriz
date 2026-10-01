<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\DuplicidadeDeTarefas;
use App\Services\FluxoTarefaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O mesmo pedido aberto duas vezes (#205).
 *
 * O caso que o pediu: a #185 aberta no AlfaControl, que não tem Wellhub, e o
 * mesmo defeito aberto de novo quatro dias depois como #191, com outra
 * prioridade. Os testes seguem as três metades da tarefa: avisar ao criar,
 * marcar duplicada com vínculo nas duas pontas, e o lembrete da triagem.
 */
class DuplicidadeDeTarefasTest extends TestCase
{
    use RefreshDatabase;

    private function tarefa(array $atributos = []): Tarefa
    {
        return Tarefa::create(array_merge([
            'titulo' => 'Tarefa qualquer',
            'resumo' => null,
            'criado_por_id' => User::factory()->create()->id,
        ], $atributos));
    }

    private function parecidas(string $titulo, ?string $resumo = null, ?int $sistemaId = null)
    {
        return app(DuplicidadeDeTarefas::class)->parecidas($titulo, $resumo, $sistemaId);
    }

    // --- A régua de "parecida" ------------------------------------------------

    public function test_acha_a_parecida_mesmo_em_outro_sistema_e_com_acento_diferente(): void
    {
        $control = Sistema::factory()->create(['nome' => 'AlfaControl']);
        $gym = Sistema::factory()->create(['nome' => 'AlfaGym']);

        $original = $this->tarefa([
            'titulo' => 'Check-in do Wellhub não registra a presença',
            'sistema_id' => $control->id,
        ]);

        // O sistema errado é justamente o caso: ele soma pontos, nunca filtra.
        $achadas = $this->parecidas('Wellhub: presenca do check-in nao registrada', null, $gym->id);

        $this->assertSame([$original->id], $achadas->pluck('id')->all());
    }

    public function test_encerradas_nao_contam_e_palavras_vazias_nao_casam(): void
    {
        $this->tarefa(['titulo' => 'Integração com Wellhub', 'status' => 'concluida']);
        $this->tarefa(['titulo' => 'Integração com Wellhub antiga', 'status' => 'cancelada']);
        $this->tarefa(['titulo' => 'Corrigir erro na tela de vendas']);

        $this->assertTrue($this->parecidas('Integração com Wellhub')->isEmpty(), 'Encerrada não é pedido em curso.');
        $this->assertTrue($this->parecidas('Corrigir erro na tela do sistema')->isEmpty(), 'Só palavras genéricas em comum.');
    }

    public function test_titulo_comprido_pede_duas_palavras_em_comum(): void
    {
        $this->tarefa(['titulo' => 'Relatório de vendas por vendedor']);
        $parecida = $this->tarefa(['titulo' => 'Relatório de comissões trimestral']);

        $this->assertTrue($this->parecidas('Relatório de inadimplência mensal')->isEmpty());
        $this->assertSame([$parecida->id], $this->parecidas('Relatórios de comissão')->pluck('id')->all(),
            'Plural e singular caem na mesma raiz.');
    }

    public function test_mesmo_sistema_vem_primeiro(): void
    {
        $gym = Sistema::factory()->create();
        $outro = Sistema::factory()->create();

        $deOutro = $this->tarefa(['titulo' => 'Boleto vencido não bloqueia aluno', 'sistema_id' => $outro->id]);
        $doMesmo = $this->tarefa(['titulo' => 'Boleto vencido não bloqueia aluno', 'sistema_id' => $gym->id]);

        $achadas = $this->parecidas('Aluno com boleto vencido não é bloqueado', null, $gym->id);

        $this->assertSame([$doMesmo->id, $deOutro->id], $achadas->pluck('id')->all());
    }

    // --- Na criação, pela tela -----------------------------------------------

    public function test_a_tela_de_criacao_traz_o_aviso_e_a_busca_devolve_as_parecidas(): void
    {
        $admin = User::factory()->create();
        $original = $this->tarefa(['titulo' => 'Wellhub não registra check-in']);

        $this->actingAs($admin)->get(route('tarefas.index'))
            ->assertOk()
            // O endereço vai por `@js`, que escapa as barras.
            ->assertSee(str_replace('/', '\\/', route('tarefas.parecidas')), false);

        $this->actingAs($admin)
            ->get(route('tarefas.parecidas', ['titulo' => 'Check-in do Wellhub falhando']))
            ->assertOk()
            ->assertSee('Já existem tarefas parecidas em curso')
            ->assertSee($original->codigo())
            ->assertSee('é só salvar');

        $this->actingAs($admin)
            ->get(route('tarefas.parecidas', ['titulo' => 'Exportar planilha de alunos']))
            ->assertOk()
            ->assertDontSee('Já existem tarefas parecidas');
    }

    public function test_o_aviso_nao_bloqueia_a_criacao(): void
    {
        $admin = User::factory()->create();
        $this->tarefa(['titulo' => 'Wellhub não registra check-in']);

        $this->actingAs($admin)->post(route('tarefas.store'), ['titulo' => 'Wellhub não registra check-in no AlfaGym'])
            ->assertSessionMissing('erro');

        $this->assertSame(2, Tarefa::where('titulo', 'like', 'Wellhub%')->count());
    }

    public function test_a_busca_e_da_matriz(): void
    {
        $this->get(route('tarefas.parecidas', ['titulo' => 'qualquer']))->assertRedirect();
    }

    // --- Marcar como duplicada -----------------------------------------------

    public function test_marcar_cancela_com_vinculo_visivel_nas_duas(): void
    {
        $admin = User::factory()->create();
        $original = $this->tarefa(['titulo' => 'Wellhub não registra check-in']);
        $copia = $this->tarefa(['titulo' => 'Check-in Wellhub falhando']);

        $this->actingAs($admin)
            ->post(route('tarefas.duplicada', $copia), ['original' => $original->codigo()])
            ->assertSessionMissing('erro');

        $copia->refresh();

        $this->assertSame('cancelada', $copia->status);
        $this->assertSame($original->id, $copia->duplicada_de_id);
        $this->assertSame('aberta', $original->fresh()->status, 'A original segue onde estava.');

        $evento = $copia->eventos()->where('para_status', 'cancelada')->sole();
        $this->assertStringContainsString('Duplicada de '.$original->codigo(), $evento->motivo);

        // A ponta da original: o modal dela diz que o pedido se repetiu.
        $this->actingAs($admin)->get(route('tarefas.modal', $original))
            ->assertOk()
            ->assertSee('Pedida de novo em')
            ->assertSee($copia->codigo());

        // A ponta da cópia.
        $this->actingAs($admin)->get(route('tarefas.modal', $copia))
            ->assertOk()
            ->assertSee('Cancelada como duplicada de')
            ->assertSee($original->codigo());
    }

    public function test_as_recusas_dizem_o_motivo(): void
    {
        $admin = User::factory()->create();
        $original = $this->tarefa(['titulo' => 'Original']);
        $copia = $this->tarefa(['titulo' => 'Cópia']);
        $cancelada = $this->tarefa(['titulo' => 'Cancelada', 'status' => 'cancelada']);

        $this->actingAs($admin)->post(route('tarefas.duplicada', $copia), ['original' => $copia->codigo()])
            ->assertSessionHas('erro', 'Uma tarefa não é duplicada de si mesma.');

        $this->actingAs($admin)->post(route('tarefas.duplicada', $copia), ['original' => '#999999'])
            ->assertSessionHas('erro', 'Não há tarefa #999999.');

        $this->actingAs($admin)->post(route('tarefas.duplicada', $copia), ['original' => $cancelada->codigo()])
            ->assertSessionHas('erro');

        $this->assertSame('aberta', $copia->fresh()->status);

        // A cadeia é cortada: apontar para uma duplicada manda apontar para a original dela.
        app(DuplicidadeDeTarefas::class)->marcar($copia, $original, $admin);
        $terceira = $this->tarefa(['titulo' => 'Terceira']);

        $this->actingAs($admin)->post(route('tarefas.duplicada', $terceira), ['original' => $copia->codigo()])
            ->assertSessionHas('erro', 'A tarefa '.$copia->codigo().' já é duplicada da '.$original->codigo().'. Aponte para a '.$original->codigo().'.');
    }

    public function test_quem_nao_pode_mover_nao_marca(): void
    {
        $membro = User::factory()->membro()->create();
        $original = $this->tarefa(['titulo' => 'Original']);
        $copia = $this->tarefa(['titulo' => 'Cópia']);

        $this->actingAs($membro)->post(route('tarefas.duplicada', $copia), ['original' => $original->codigo()]);

        $this->assertSame('aberta', $copia->fresh()->status);
        $this->assertNull($copia->fresh()->duplicada_de_id);
    }

    public function test_reabrir_a_duplicada_desfaz_o_vinculo(): void
    {
        $admin = User::factory()->create();
        $original = $this->tarefa(['titulo' => 'Original']);
        $copia = $this->tarefa(['titulo' => 'Cópia']);

        $this->actingAs($admin);
        app(DuplicidadeDeTarefas::class)->marcar($copia, $original, $admin);

        app(FluxoTarefaService::class)->mover($copia->fresh(), 'aberta', ['motivo' => 'Não era o mesmo defeito']);

        $this->assertNull($copia->fresh()->duplicada_de_id);
        $this->assertTrue($original->fresh()->duplicadas->isEmpty());
    }

    // --- O lembrete da triagem -----------------------------------------------

    public function test_a_triagem_mostra_o_lembrete_com_as_parecidas_e_seus_sistemas(): void
    {
        $admin = User::factory()->create();
        $control = Sistema::factory()->create(['nome' => 'AlfaControl']);
        $gym = Sistema::factory()->create(['nome' => 'AlfaGym']);

        $original = $this->tarefa(['titulo' => 'Wellhub não registra check-in', 'sistema_id' => $gym->id, 'prioridade' => 'alta']);
        $nova = $this->tarefa(['titulo' => 'Check-in do Wellhub falha', 'sistema_id' => $control->id]);

        $html = $this->actingAs($admin)->get(route('tarefas.modal', $nova))->assertOk()->getContent();
        $texto = preg_replace('/\s+/', ' ', strip_tags($html));

        $this->assertStringContainsString('Antes de direcionar', $texto);
        $this->assertStringContainsString('O sistema confere? Está em AlfaControl. As parecidas estão em AlfaGym.', $texto);
        $this->assertStringContainsString($original->codigo().' · '.$original->titulo, $texto);
        $this->assertStringContainsString('As parecidas estão como '.$original->codigo().' alta.', $texto);
        $this->assertStringContainsString('É esta', $texto);
    }

    public function test_o_lembrete_e_so_da_fila_e_de_quem_triaga(): void
    {
        $admin = User::factory()->create();
        $membro = User::factory()->membro()->create();

        $naFila = $this->tarefa(['titulo' => 'Na fila']);
        $andando = $this->tarefa(['titulo' => 'Andando', 'responsavel_id' => $membro->id]);

        $this->actingAs($admin)->get(route('tarefas.modal', $andando))
            ->assertOk()->assertDontSee('Antes de direcionar');

        // O membro não triaga — e esta tarefa nem é dele, então também não marca.
        $this->actingAs($membro)->get(route('tarefas.modal', $naFila))
            ->assertOk()
            ->assertDontSee('Antes de direcionar')
            ->assertDontSee('Marcar como duplicada');

        // Sendo responsável, ele pode cancelar a própria — e por isso marca.
        $this->actingAs($membro)->get(route('tarefas.modal', $andando))
            ->assertOk()
            ->assertSee('Marcar como duplicada');
    }
}
