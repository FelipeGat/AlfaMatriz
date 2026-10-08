<?php

namespace Tests\Feature\OQueEsperaVoce;

use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\Tarefa;
use App\Models\TarefaEvento;
use App\Models\TarefaRelatorioTeste;
use App\Models\User;
use App\Services\OQueEsperaVoce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "O que espera você" (#300): o que no quadro está parado esperando a pessoa.
 *
 * Nasceu de perguntas respondidas só quando alguém cobrava e de tarefas 40 dias
 * em staging sem ninguém apontado para validar. É CONDIÇÃO, recalculada a cada
 * abertura — por isso os testes montam o estado e perguntam, sem evento no meio.
 */
class OQueEsperaVoceTest extends TestCase
{
    use RefreshDatabase;

    private function espera(): OQueEsperaVoce
    {
        return app(OQueEsperaVoce::class);
    }

    /** @return list<string> */
    private function tipos(User $usuario): array
    {
        return $this->espera()->pendencias($usuario)->pluck('tipo')->all();
    }

    /** Uma tarefa num portão, com a passagem aberta apontada (ou não) para alguém. */
    private function noPortao(string $status, ?User $apontado, array $atributos = [], ?Carbon $desde = null): Tarefa
    {
        $tarefa = Tarefa::factory()->create(['status' => $status] + $atributos);

        TarefaEvento::create([
            'tarefa_id' => $tarefa->id,
            'apontado_id' => $apontado?->id,
            'de_status' => 'em_desenvolvimento',
            'para_status' => $status,
            'entrou_em' => $desde ?? now(),
        ]);

        return $tarefa;
    }

    public function test_pergunta_esperando_a_pessoa_aparece_e_arquivada_ou_encerrada_nao(): void
    {
        $alexandre = User::factory()->membro()->create();
        $quemPergunta = User::factory()->membro()->create(['name' => 'Felipe Gat']);

        $pergunta = ['pergunta_em' => now()->subHours(3), 'pergunta_para_id' => $alexandre->id, 'pergunta_de_id' => $quemPergunta->id];

        $viva = Tarefa::factory()->create(['status' => 'em_revisao']);
        $viva->forceFill($pergunta)->save();

        $arquivada = Tarefa::factory()->create(['status' => 'em_revisao']);
        $arquivada->forceFill($pergunta + ['arquivada_em' => now()])->save();

        $encerrada = Tarefa::factory()->create(['status' => 'concluida']);
        $encerrada->forceFill($pergunta)->save();

        $itens = $this->espera()->pendencias($alexandre);

        $this->assertSame([$viva->id], $itens->pluck('tarefa.id')->all());
        $this->assertSame('pergunta', $itens->first()['tipo']);
        $this->assertStringContainsString('Felipe Gat', $itens->first()['motivo']);
        $this->assertSame('3h', $itens->first()['ha']);

        // E quem perguntou não tem nada esperando: a vez é do outro.
        $this->assertSame([], $this->tipos($quemPergunta));
    }

    public function test_quem_valida_e_o_apontado_e_uma_pergunta_na_conversa_nao_o_tira_da_lista(): void
    {
        $dev = User::factory()->membro()->create();
        $validador = User::factory()->membro()->create();

        $tarefa = Tarefa::factory()->create([
            'status' => 'em_revisao', 'responsavel_id' => $dev->id, 'criado_por_id' => $dev->id,
        ]);

        // O caminho de verdade: mover para o staging apontando quem testa.
        $this->actingAs($dev)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_staging', 'de_status' => 'em_revisao', 'interlocutor_id' => $validador->id,
        ])->assertSessionMissing('erro');

        $this->assertSame(['validar'], $this->tipos($validador));

        // O validador pergunta ao dev: a vez da CONVERSA passa para o dev, e o
        // interlocutor muda — o exame continua sendo do validador.
        $this->actingAs($validador)->post(route('tarefas.conversar', $tarefa), ['corpo' => 'Qual academia uso no teste?'])
            ->assertSessionMissing('erro');

        $this->assertSame(['validar'], $this->tipos($validador->fresh()));
        $this->assertSame(['pergunta'], $this->tipos($dev->fresh()));
    }

    public function test_aprovada_na_passagem_sai_da_lista_de_quem_valida(): void
    {
        $validador = User::factory()->membro()->create();
        $tarefa = $this->noPortao('em_staging', $validador);

        $this->assertSame(['validar'], $this->tipos($validador));

        TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id,
            'tarefa_evento_id' => $tarefa->eventos()->first()->id,
            'aprovado' => true,
        ]);

        $this->assertSame([], $this->tipos($validador));
    }

    public function test_revisao_parada_pede_revisar_e_esquenta_com_o_tempo(): void
    {
        $revisor = User::factory()->membro()->create();
        $this->noPortao('em_revisao', $revisor, [], now()->subDays(9));

        $item = $this->espera()->pendencias($revisor)->first();

        $this->assertSame('Revisar', $item['acao']);
        $this->assertSame('critico', $item['nivel']);
        $this->assertSame('9d', $item['ha']);
    }

    public function test_tarefa_que_voltou_aparece_para_o_responsavel(): void
    {
        $dev = User::factory()->membro()->create();
        $tarefa = Tarefa::factory()->create(['status' => 'em_desenvolvimento', 'responsavel_id' => $dev->id]);
        $tarefa->forceFill(['retorno_de' => 'em_staging', 'retorno_motivo' => 'Quebrou no check-in'])->save();

        $item = $this->espera()->pendencias($dev)->first();

        $this->assertSame('retorno', $item['tipo']);
        $this->assertSame($tarefa->rotuloDoRetorno(), $item['motivo']);
    }

    public function test_prazo_vencido_e_vencendo_aparecem_e_o_de_depois_de_amanha_nao(): void
    {
        Carbon::setTestNow('2026-10-07 09:00:00');
        $dev = User::factory()->membro()->create();

        foreach (['2026-10-05', '2026-10-07', '2026-10-08', '2026-10-09'] as $prazo) {
            Tarefa::factory()->create([
                'status' => 'em_desenvolvimento', 'responsavel_id' => $dev->id, 'prazo' => $prazo, 'titulo' => $prazo,
            ]);
        }

        $itens = $this->espera()->pendencias($dev);

        $this->assertSame(['2026-10-05', '2026-10-07', '2026-10-08'], $itens->pluck('tarefa.titulo')->all());
        $this->assertSame(
            ['Prazo venceu há 2 dias', 'Prazo vence hoje', 'Prazo vence amanhã'],
            $itens->pluck('motivo')->all(),
        );
        $this->assertSame(['critico', 'atencao', 'atencao'], $itens->pluck('nivel')->all());
    }

    public function test_portao_sem_validador_vai_para_quem_faz_triagem_e_nao_para_o_membro(): void
    {
        $admin = User::factory()->create();
        $membro = User::factory()->membro()->create();

        $parada = $this->noPortao('em_staging', null, ['responsavel_id' => $membro->id], now()->subDays(40));

        $this->assertTrue($admin->podeTriarTarefas());
        $item = $this->espera()->pendencias($admin)->firstWhere('tipo', 'sem_validador');
        $this->assertSame($parada->id, $item['tarefa']->id);
        $this->assertSame('critico', $item['nivel']);

        $this->assertNotContains('sem_validador', $this->tipos($membro));
    }

    public function test_minhas_tarefas_traz_o_papel_e_as_mais_paradas_primeiro(): void
    {
        $eu = User::factory()->membro()->create();

        $faco = $this->noPortao('em_revisao', null, ['responsavel_id' => $eu->id], now()->subHours(2));
        $valido = $this->noPortao('em_staging', $eu, [], now()->subDays(3));
        $abri = $this->noPortao('em_producao', null, ['criado_por_id' => $eu->id], now()->subDay());
        $this->noPortao('em_revisao', null); // de outra pessoa

        $minhas = $this->espera()->minhas($eu);

        $this->assertSame([$valido->id, $abri->id, $faco->id], $minhas->pluck('tarefa.id')->all());
        $this->assertSame(['você valida', 'você abriu', 'você faz'], $minhas->pluck('papel')->all());
        $this->assertSame('3d', $minhas->first()['parada']);
    }

    public function test_o_aviso_aparece_uma_vez_e_se_adia_por_duas_horas(): void
    {
        Carbon::setTestNow('2026-10-07 09:00:00');
        $validador = User::factory()->membro()->create();
        $this->noPortao('em_staging', $validador);

        $this->actingAs($validador)->get(route('espera.aviso'))
            ->assertOk()
            ->assertSee('O que espera você')
            ->assertSee('Lembrar mais tarde');

        // Entregar o aviso já o adia: fechar de qualquer jeito vale "mais tarde".
        $this->assertEquals(now()->addHours(2), $validador->fresh()->espera_silenciada_ate);
        $this->actingAs($validador->fresh())->get(route('espera.aviso'))->assertNoContent();

        Carbon::setTestNow('2026-10-07 11:01:00');
        $this->actingAs($validador->fresh())->get(route('espera.aviso'))->assertOk();
    }

    public function test_ok_vi_silencia_ate_o_fim_do_dia(): void
    {
        Carbon::setTestNow('2026-10-07 09:00:00');
        $validador = User::factory()->membro()->create();
        $this->noPortao('em_staging', $validador);

        $this->actingAs($validador)->post(route('espera.visto'))->assertNoContent();

        Carbon::setTestNow('2026-10-07 23:30:00');
        $this->actingAs($validador->fresh())->get(route('espera.aviso'))->assertNoContent();

        Carbon::setTestNow('2026-10-08 08:00:00');
        $this->actingAs($validador->fresh())->get(route('espera.aviso'))->assertOk();
    }

    public function test_sem_pendencia_nao_ha_aviso(): void
    {
        $tranquilo = User::factory()->membro()->create();

        $this->actingAs($tranquilo)->get(route('espera.aviso'))->assertNoContent();
        $this->assertNull($tranquilo->fresh()->espera_silenciada_ate);
    }

    public function test_a_moldura_so_busca_o_aviso_de_quem_pode_agir(): void
    {
        $membro = User::factory()->membro()->create();
        $this->actingAs($membro)->get(route('tarefas.index'))->assertSee(route('espera.aviso'), false);

        // Silenciado: a moldura nem pergunta.
        $membro->forceFill(['espera_silenciada_ate' => now()->addHour()])->save();
        $this->actingAs($membro->fresh())->get(route('tarefas.index'))->assertDontSee(route('espera.aviso'), false);

        // Só leitura (o painel de parede): nem o aviso nem o botão.
        $exibicao = $this->usuarioDeExibicao();
        $this->actingAs($exibicao)->get(route('tarefas.index'))
            ->assertOk()
            ->assertDontSee(route('espera.aviso'), false)
            ->assertDontSee('data-botao-espera', false);
    }

    public function test_quem_nao_ve_o_quadro_nao_alcanca_o_aviso(): void
    {
        $semPerfil = User::factory()->semPerfil()->create();

        $this->actingAs($semPerfil)->get(route('espera.aviso'))->assertForbidden();
    }

    public function test_centro_de_controle_e_quadro_mostram_as_listas(): void
    {
        $admin = User::factory()->create();
        $tarefa = $this->noPortao('em_staging', $admin, ['titulo' => 'Subir AlfaMobi Play Store']);

        $this->actingAs($admin)->get(route('centro-controle'))
            ->assertOk()
            ->assertSee('data-painel-espera', false)
            ->assertSee('data-painel-minhas', false)
            ->assertSee('Subir AlfaMobi Play Store')
            ->assertSee(route('tarefas.index', ['tarefa' => $tarefa->id]), false);

        // No quadro, só a contagem: as listas chegam ao clicar no botão.
        $this->actingAs($admin)->get(route('tarefas.index'))
            ->assertOk()
            ->assertSee('O que espera você · 1')
            ->assertDontSee('data-painel-espera', false);

        $this->actingAs($admin)->get(route('espera.listas'))
            ->assertOk()
            ->assertSee('data-painel-espera', false)
            ->assertSee('data-painel-minhas', false)
            ->assertSee('Subir AlfaMobi Play Store');
    }

    /**
     * O card diz o mesmo que o painel: sem apontado, "Ninguém apontado" — e não
     * o nome da pessoa da conversa, que antes servia de reserva (08/10/2026).
     */
    public function test_card_sem_apontado_diz_ninguem_apontado_mesmo_com_conversa(): void
    {
        $admin = User::factory()->create();
        $dev = User::factory()->membro()->create(['name' => 'Alexandre Blank']);

        $this->noPortao('em_revisao', null, ['responsavel_id' => $dev->id, 'interlocutor_id' => $dev->id]);

        $this->actingAs($admin)->get(route('tarefas.index'))
            ->assertOk()
            ->assertSee('Ninguém apontado para revisar')
            ->assertDontSee('Revisão com');
    }

    public function test_o_quadro_filtrado_nao_traz_titulo_de_tarefa_que_o_filtro_escondeu(): void
    {
        $admin = User::factory()->create();
        Tarefa::factory()->create([
            'status' => 'em_desenvolvimento', 'responsavel_id' => $admin->id, 'titulo' => 'Tarefa fora do recorte',
        ]);

        $this->actingAs($admin)->get(route('tarefas.index', ['busca' => 'outra coisa']))
            ->assertOk()
            ->assertDontSee('Tarefa fora do recorte');
    }

    public function test_o_link_com_tarefa_abre_o_detalhe_so_se_ela_existe(): void
    {
        $admin = User::factory()->create();
        $tarefa = Tarefa::factory()->create(['status' => 'backlog']);

        $this->actingAs($admin)->get(route('tarefas.index', ['tarefa' => $tarefa->id]))
            ->assertOk()
            ->assertSee("{ detail: {$tarefa->id} }", false);

        // Id que não existe não abre nada — o modal que falha recarrega a página.
        $this->actingAs($admin)->get(route('tarefas.index', ['tarefa' => 999999]))
            ->assertOk()
            ->assertDontSee('{ detail: 999999 }', false);
    }

    private function usuarioDeExibicao(): User
    {
        $perfil = Perfil::updateOrCreate(['slug' => 'exibicao'], ['nome' => 'Exibição', 'nao_expira_por_ociosidade' => true]);
        $perfil->permissoes()->sync([
            Permissao::firstOrCreate(['recurso' => 'tarefas'], ['descricao' => 'tarefas'])->id => [
                'ler' => true, 'incluir' => false, 'editar' => false, 'imprimir' => false, 'excluir' => false,
            ],
        ]);

        $usuario = User::factory()->semPerfil()->create();
        $usuario->perfis()->attach($perfil->id);

        return $usuario;
    }
}
