<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Notificacao;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\ArquivoDeTarefas;
use App\Services\DuplicidadeDeTarefas;
use App\Services\FluxoTarefaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Arquivar tarefa (#208): tirar do quadro o que não vai andar agora, sem
 * encerrar.
 *
 * As quatro metades da tarefa: arquivar com motivo (só triagem) e avisar quem
 * abriu; a arquivada fora do quadro, das contagens e dos lembretes, mas
 * visível na aba dela; a volta — pelo botão ou pela resposta de quem abriu —
 * para a mesma coluna; e a lista de candidatas da triagem.
 */
class ArquivoDeTarefasTest extends TestCase
{
    use RefreshDatabase;

    private function tarefa(array $atributos = []): Tarefa
    {
        return Tarefa::create(array_merge([
            'titulo' => 'Exportar relatório de presença',
            'tipo' => 'desenvolvimento',
            'criado_por_id' => User::factory()->membro()->create()->id,
        ], $atributos));
    }

    private function arquivar(Tarefa $tarefa, User $quem, string $motivo = 'depois', ?string $nota = null): Tarefa
    {
        return app(ArquivoDeTarefas::class)->arquivar($tarefa, $motivo, $nota, $quem);
    }

    // --- Arquivar -------------------------------------------------------------

    public function test_arquivar_guarda_a_etapa_registra_na_conversa_e_avisa_quem_abriu(): void
    {
        $admin = User::factory()->create();
        $dev = User::factory()->membro()->create();
        $tarefa = $this->tarefa(['responsavel_id' => $dev->id]);
        $quemAbriu = $tarefa->criadoPor;

        $this->actingAs($admin)
            ->post(route('tarefas.arquivar', $tarefa), ['motivo' => 'sem_retorno', 'nota' => 'Pedi o print duas vezes.'])
            ->assertSessionHas('status', 'Tarefa arquivada.');

        $tarefa->refresh();

        $this->assertTrue($tarefa->estaArquivada());
        $this->assertSame('backlog', $tarefa->status, 'A etapa não muda: é ela que guarda para onde a tarefa volta.');
        $this->assertSame($dev->id, $tarefa->responsavel_id);
        $this->assertSame($admin->id, $tarefa->arquivada_por_id);
        $this->assertSame('Sem retorno', $tarefa->rotuloDoArquivamento());

        $aviso = $tarefa->comentarios()->sole();
        $this->assertSame($admin->id, $aviso->autor_id);
        $this->assertStringContainsString('Arquivada · Sem retorno: Pedi o print duas vezes.', $aviso->corpo);
        $this->assertStringContainsString($quemAbriu->name.', se ainda precisar disto, responda aqui', $aviso->corpo);

        $this->assertEqualsCanonicalizing(
            [$quemAbriu->id, $dev->id],
            Notificacao::where('tipo', 'arquivamento')->pluck('destinatario_id')->all(),
        );
    }

    public function test_quem_nao_faz_triagem_nao_arquiva(): void
    {
        $membro = User::factory()->membro()->create();
        $tarefa = $this->tarefa(['responsavel_id' => $membro->id]);

        $this->actingAs($membro)
            ->post(route('tarefas.arquivar', $tarefa), ['motivo' => 'depois'])
            ->assertSessionHas('erro', 'Só quem faz triagem arquiva tarefa.');

        $this->assertFalse($tarefa->fresh()->estaArquivada());
    }

    public function test_recusa_sem_motivo_encerrada_ja_arquivada_e_com_filha_em_curso(): void
    {
        $admin = User::factory()->create();
        $tarefa = $this->tarefa();

        $this->actingAs($admin)->post(route('tarefas.arquivar', $tarefa), ['motivo' => ''])
            ->assertSessionHas('erro', fn ($erro) => str_starts_with($erro, 'Diga por que está arquivando'));
        $this->actingAs($admin)->post(route('tarefas.arquivar', $tarefa), ['motivo' => 'duplicada'])
            ->assertSessionHas('erro', fn ($erro) => str_starts_with($erro, 'Diga por que está arquivando'));

        $concluida = $this->tarefa(['status' => 'concluida']);
        $this->actingAs($admin)->post(route('tarefas.arquivar', $concluida), ['motivo' => 'depois'])
            ->assertSessionHas('erro', 'A tarefa '.$concluida->codigo().' já está encerrada. Só tarefa em curso é arquivada.');

        $this->arquivar($tarefa, $admin);
        $this->actingAs($admin)->post(route('tarefas.arquivar', $tarefa), ['motivo' => 'depois'])
            ->assertSessionHas('erro', 'A tarefa '.$tarefa->codigo().' já está arquivada.');

        $mae = $this->tarefa(['titulo' => 'Mãe']);
        // A mãe fica fora do `fillable`: quem a grava é o `TarefaService`.
        $filha = $this->tarefa(['titulo' => 'Filha']);
        $filha->forceFill(['tarefa_pai_id' => $mae->id])->save();
        $this->actingAs($admin)->post(route('tarefas.arquivar', $mae), ['motivo' => 'depois'])
            ->assertSessionHas('erro', 'A tarefa '.$mae->codigo().' tem subtarefas em curso ('.$filha->codigo().'). Arquive ou encerre as subtarefas antes.');

        // Com a filha arquivada, a mãe pode ir também.
        $this->arquivar($filha, $admin);
        $this->assertTrue($this->arquivar($mae->fresh(), $admin)->estaArquivada());
    }

    public function test_arquivada_nao_se_move_nem_pela_rota_nem_pelo_motor(): void
    {
        $admin = User::factory()->create();
        $tarefa = $this->arquivar($this->tarefa(), $admin);

        $this->actingAs($admin)
            ->post(route('tarefas.mover', $tarefa), ['status' => 'em_desenvolvimento', 'de_status' => 'backlog'])
            ->assertSessionHas('erro', 'Esta tarefa está arquivada. Desarquive antes de mover.');

        $this->assertSame([], $tarefa->destinosPara($admin), 'Sem destinos: o card não arrasta e o modal não oferece Mover.');

        $this->expectExceptionMessage('está arquivada. Desarquive antes de mover.');
        app(FluxoTarefaService::class)->mover($tarefa, 'em_desenvolvimento');
    }

    // --- Fora do quadro, dentro da aba ----------------------------------------

    public function test_some_do_quadro_e_aparece_na_aba_arquivadas_com_a_tarja(): void
    {
        $admin = User::factory()->create();
        $andando = $this->tarefa(['titulo' => 'Tarefa que segue no quadro']);
        $arquivada = $this->arquivar($this->tarefa(['titulo' => 'Ideia para o ano que vem']), $admin, 'depois', 'Depois da migração.');

        $this->actingAs($admin)->get(route('tarefas.index'))
            ->assertOk()
            ->assertSee($andando->titulo)
            ->assertDontSee($arquivada->titulo);

        $this->actingAs($admin)->get(route('tarefas.index', ['situacao' => 'arquivadas']))
            ->assertOk()
            ->assertSee($arquivada->titulo)
            ->assertSee('Arquivada · Fica para depois')
            ->assertSee('Depois da migração.')
            ->assertDontSee($andando->titulo)
            ->assertSee('1 tarefas arquivadas');

        // O motivo recorta o arquivo.
        $this->actingAs($admin)->get(route('tarefas.index', ['situacao' => 'arquivadas', 'motivo' => 'sem_retorno']))
            ->assertOk()
            ->assertDontSee($arquivada->titulo);
    }

    public function test_modal_da_arquivada_mostra_a_tarja_e_o_desarquivar_so_para_a_triagem(): void
    {
        $admin = User::factory()->create();
        $membro = User::factory()->membro()->create();
        $tarefa = $this->arquivar($this->tarefa(), $admin, 'nao_confirmado');

        $this->actingAs($admin)->get(route('tarefas.modal', $tarefa))
            ->assertOk()
            ->assertSee('Não confirmado')
            ->assertSee('por '.$admin->name, false)
            ->assertSee('form="desarquivar-'.$tarefa->id.'"', false)
            ->assertDontSee('Não vai andar agora? Arquivar');

        $this->actingAs($membro)->get(route('tarefas.modal', $tarefa))
            ->assertOk()
            ->assertSee('Não confirmado')
            ->assertDontSee('form="desarquivar-'.$tarefa->id.'"', false);

        // Fora do arquivo, o gesto é só da triagem.
        $outra = $this->tarefa(['responsavel_id' => $membro->id]);
        $this->actingAs($admin)->get(route('tarefas.modal', $outra))->assertSee('Não vai andar agora? Arquivar');
        $this->actingAs($membro)->get(route('tarefas.modal', $outra))->assertDontSee('Não vai andar agora? Arquivar');
    }

    public function test_fica_fora_do_lembrete_de_prazo_e_do_relatorio_mas_entra_nas_parecidas(): void
    {
        $admin = User::factory()->create();
        $dev = User::factory()->membro()->create();
        $arquivada = $this->arquivar($this->tarefa([
            'titulo' => 'Integração com Wellhub',
            'responsavel_id' => $dev->id,
            'prazo' => today(),
        ]), $admin);

        $this->artisan('agenda:lembrar-prazos')->assertSuccessful();
        $this->assertSame(0, Notificacao::where('destinatario_id', $dev->id)->where('tipo', '!=', 'arquivamento')->count(),
            '"Agora não" não tem prazo a cobrar.');

        // O pedido novo igual a um "fica para depois" é o caso de lembrar dele.
        $parecidas = app(DuplicidadeDeTarefas::class)->parecidas('Wellhub: integração do check-in');
        $this->assertSame([$arquivada->id], $parecidas->pluck('id')->all());

        $this->actingAs($admin)->get(route('tarefas.parecidas', ['titulo' => 'Wellhub: integração do check-in']))
            ->assertSee('(arquivada)');
    }

    // --- A volta ----------------------------------------------------------------

    public function test_desarquivar_volta_para_a_mesma_coluna_e_avisa(): void
    {
        $admin = User::factory()->create();
        $dev = User::factory()->membro()->create();
        $tarefa = $this->tarefa(['responsavel_id' => $dev->id]);
        app(FluxoTarefaService::class)->mover($tarefa, 'em_desenvolvimento');
        $this->arquivar($tarefa->fresh(), $admin);

        $this->actingAs($admin)
            ->post(route('tarefas.desarquivar', $tarefa))
            ->assertSessionHas('status', 'Tarefa desarquivada: voltou para Em andamento.');

        $tarefa->refresh();
        $this->assertFalse($tarefa->estaArquivada());
        $this->assertNull($tarefa->arquivamento_motivo);
        $this->assertSame('em_desenvolvimento', $tarefa->status);
        $this->assertSame($dev->id, $tarefa->responsavel_id);
        $this->assertSame('Desarquivada. Voltou para Em andamento.', $tarefa->comentarios()->latest('id')->first()->corpo);
        $this->assertTrue(Notificacao::where('tipo', 'desarquivamento')->where('destinatario_id', $dev->id)->exists());

        $this->actingAs($admin)->post(route('tarefas.desarquivar', $tarefa))
            ->assertSessionHas('erro', 'A tarefa '.$tarefa->codigo().' não está arquivada.');
    }

    public function test_quem_nao_faz_triagem_nao_desarquiva(): void
    {
        $admin = User::factory()->create();
        $membro = User::factory()->membro()->create();
        $tarefa = $this->arquivar($this->tarefa(['responsavel_id' => $membro->id]), $admin);

        $this->actingAs($membro)->post(route('tarefas.desarquivar', $tarefa))
            ->assertSessionHas('erro', 'Só quem faz triagem desarquiva tarefa.');

        $this->assertTrue($tarefa->fresh()->estaArquivada());
    }

    public function test_comentario_de_quem_abriu_traz_de_volta_e_o_de_outra_pessoa_nao(): void
    {
        $admin = User::factory()->create();
        $outro = User::factory()->membro()->create();
        $tarefa = $this->tarefa();
        $etapa = $tarefa->status;
        $this->arquivar($tarefa, $admin, 'sem_retorno');
        $quemAbriu = $tarefa->criadoPor;

        $this->actingAs($outro)->post(route('tarefas.comentarios.store', $tarefa), ['corpo' => 'Também vi isso.']);
        $this->assertTrue($tarefa->fresh()->estaArquivada(), 'Só quem abriu sabe se ainda precisa.');

        $this->actingAs($quemAbriu)->post(route('tarefas.comentarios.store', $tarefa), ['corpo' => 'Ainda acontece, segue o print.']);

        $tarefa->refresh();
        $this->assertFalse($tarefa->estaArquivada());
        $this->assertSame($etapa, $tarefa->status);

        $aviso = Notificacao::where('tipo', 'desarquivamento')->where('destinatario_id', $admin->id)->sole();
        $this->assertStringContainsString($quemAbriu->name.' respondeu: Ainda acontece', $aviso->meta);
    }

    public function test_quem_arquiva_a_propria_tarefa_nao_a_desarquiva_com_o_aviso(): void
    {
        $admin = User::factory()->create();
        $tarefa = $this->tarefa(['criado_por_id' => $admin->id]);

        $this->arquivar($tarefa, $admin);

        $this->assertTrue($tarefa->fresh()->estaArquivada());
        $this->assertStringContainsString('Um comentário de quem abriu a traz de volta.', $tarefa->comentarios()->sole()->corpo);
        $this->assertSame(0, Notificacao::where('tipo', 'arquivamento')->count(), 'Ninguém além de quem arquivou.');
    }

    // --- Candidatas -------------------------------------------------------------

    public function test_candidatas_sao_as_paradas_ha_um_mes_ou_quinze_dias_esperando(): void
    {
        $admin = User::factory()->create();

        $this->travel(-31)->days();
        $parada = $this->tarefa(['titulo' => 'Parada há um mês']);
        $comentada = $this->tarefa(['titulo' => 'Antiga mas comentada']);
        $arquivada = $this->tarefa(['titulo' => 'Já arquivada']);
        $concluida = $this->tarefa(['titulo' => 'Concluída', 'status' => 'concluida']);
        $this->travelBack();

        $this->travel(-16)->days();
        $travada = $this->tarefa(['titulo' => 'Travada há 16 dias']);
        app(FluxoTarefaService::class)->bloquear($travada, 'Esperando o cliente');
        $semMarca = $this->tarefa(['titulo' => 'Parada há 16 dias sem marca']);
        $this->travelBack();

        $recente = $this->tarefa(['titulo' => 'Recente']);
        $comentada->comentarios()->create(['autor_id' => $admin->id, 'corpo' => 'Ainda vale.']);
        $this->arquivar($arquivada, $admin);

        $this->assertEqualsCanonicalizing(
            [$parada->id, $travada->id],
            Tarefa::candidatasAoArquivo()->pluck('id')->all(),
        );

        $this->actingAs($admin)->get(route('tarefas.index'))
            ->assertSee('2 p/ arquivar');

        $this->actingAs($admin)->get(route('tarefas.index', ['situacao' => 'para_arquivar']))
            ->assertSee($parada->titulo)
            ->assertSee($travada->titulo)
            ->assertDontSee($recente->titulo)
            ->assertDontSee($semMarca->titulo);

        // Sugestão só para quem arquiva.
        $this->actingAs(User::factory()->membro()->create())->get(route('tarefas.index'))
            ->assertDontSee('p/ arquivar');
    }
}
