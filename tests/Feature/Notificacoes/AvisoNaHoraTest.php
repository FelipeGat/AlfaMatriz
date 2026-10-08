<?php

namespace Tests\Feature\Notificacoes;

use App\Mcp\Servers\AlfaMatrizServer;
use App\Mcp\Tools\ComentarTarefa;
use App\Models\Notificacao;
use App\Models\Tarefa;
use App\Models\TarefaEvento;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O aviso na hora, com som (#312).
 *
 * O som é marca do EVENTO (`notificacoes.sonora`), gravada por quem avisa: o
 * que depende da pessoa toca, o que é só notícia fica no sino. E o comentário
 * comum passou a avisar quem a tarefa envolve.
 */
class AvisoNaHoraTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Uma tarefa em revisão com responsável, validador apontado no evento
     * aberto e quem a abriu — as três pessoas que ela envolve.
     *
     * @return array{Tarefa, User, User, User}
     */
    private function tarefaComEnvolvidos(): array
    {
        $dev = User::factory()->create(['name' => 'Dev']);
        $validador = User::factory()->create(['name' => 'Validador']);
        $quemAbriu = User::factory()->create(['name' => 'Quem Abriu']);

        $tarefa = Tarefa::factory()->create([
            'status' => 'em_revisao',
            'responsavel_id' => $dev->id,
            'criado_por_id' => $quemAbriu->id,
        ]);

        $tarefa->eventos()->whereNull('saiu_em')->update(['saiu_em' => now()]);
        TarefaEvento::create([
            'tarefa_id' => $tarefa->id,
            'user_id' => $dev->id,
            'apontado_id' => $validador->id,
            'de_status' => 'em_desenvolvimento',
            'para_status' => 'em_revisao',
            'entrou_em' => now(),
        ]);

        return [$tarefa, $dev, $validador, $quemAbriu];
    }

    private function avisoDe(User $u): ?Notificacao
    {
        return Notificacao::where('destinatario_id', $u->id)->where('tipo', 'comentario')->first();
    }

    public function test_o_som_segue_o_tipo_e_quem_avisa_pode_decidir(): void
    {
        $ana = User::factory()->create();
        $base = ['destinatario_id' => $ana->id, 'nivel' => 'atencao', 'icone' => 'bell', 'titulo' => 'x'];

        $this->assertTrue(Notificacao::create($base + ['tipo' => 'direcionamento'])->sonora);
        $this->assertTrue(Notificacao::create($base + ['tipo' => 'pergunta'])->sonora);
        $this->assertFalse(Notificacao::create($base + ['tipo' => 'triagem'])->sonora);
        $this->assertFalse(Notificacao::create($base + ['tipo' => 'lembrete'])->sonora);
        $this->assertTrue(Notificacao::create($base + ['tipo' => 'lembrete', 'sonora' => true])->sonora);
        $this->assertFalse(Notificacao::create($base + ['tipo' => 'pergunta', 'sonora' => false])->sonora);
    }

    public function test_comentario_avisa_quem_a_tarefa_envolve_e_toca_so_para_responsavel_e_validador(): void
    {
        [$tarefa, $dev, $validador, $quemAbriu] = $this->tarefaComEnvolvidos();
        $outro = User::factory()->create(['name' => 'Outro']);

        $this->actingAs($outro)->post(route('tarefas.comentarios.store', $tarefa), [
            'corpo' => 'Olhei o PR e tenho uma dúvida.',
        ])->assertSessionMissing('erro');

        $this->assertTrue($this->avisoDe($dev)->sonora);
        $this->assertTrue($this->avisoDe($validador)->sonora);
        $this->assertFalse($this->avisoDe($quemAbriu)->sonora);
        $this->assertNull($this->avisoDe($outro), 'quem comentou não é avisado');
        $this->assertStringContainsString('Outro comentou', $this->avisoDe($dev)->titulo);
    }

    public function test_o_salvar_com_comentario_tambem_avisa(): void
    {
        [$tarefa, $dev, , $quemAbriu] = $this->tarefaComEnvolvidos();

        $this->actingAs($quemAbriu)->put(route('tarefas.update', $tarefa), [
            'titulo' => $tarefa->titulo,
            'prioridade' => $tarefa->prioridade,
            'comentario' => 'Mais um detalhe do cliente.',
        ]);

        $this->assertNotNull($this->avisoDe($dev));
        $this->assertNull($this->avisoDe($quemAbriu));
    }

    /** O MCP passa pelo mesmo serviço — e quem o agente representa não é avisado. */
    public function test_comentario_pelo_mcp_avisa_os_outros_e_cala_quem_o_agente_representa(): void
    {
        [$tarefa, $dev, $validador] = $this->tarefaComEnvolvidos();

        AlfaMatrizServer::actingAs($validador)
            ->tool(ComentarTarefa::class, ['tarefa' => '#'.$tarefa->id, 'comentario' => 'Revisei o PR.'])
            ->assertOk();

        $this->assertTrue($this->avisoDe($dev)->sonora);
        $this->assertNull($this->avisoDe($validador));
    }

    /** Pergunta e resposta têm aviso próprio: o comentário não vira eco. */
    public function test_pergunta_nao_gera_aviso_de_comentario_em_dobro(): void
    {
        [$tarefa, $dev, $validador] = $this->tarefaComEnvolvidos();

        $this->actingAs($validador)->post(route('tarefas.conversar', $tarefa), [
            'corpo' => 'Esse retorno vazio acontece?',
        ])->assertSessionMissing('erro');

        $this->assertSame(0, Notificacao::where('tipo', 'comentario')->count());
        $this->assertTrue(Notificacao::where('destinatario_id', $dev->id)->where('tipo', 'pergunta')->first()->sonora);
    }

    public function test_veredito_so_toca_quando_reprova(): void
    {
        [$tarefa, $dev, $validador] = $this->tarefaComEnvolvidos();
        $fluxo = app(FluxoTarefaService::class);

        $fluxo->avisarTesteRegistrado($tarefa, $validador, true);
        $fluxo->avisarTesteRegistrado($tarefa, $validador, false);

        $this->assertSame(
            [false, true],
            Notificacao::where('destinatario_id', $dev->id)->orderBy('id')->pluck('sonora')->all()
        );
    }

    /** O resumo dá o último id SONORO à parte: um aviso mudo depois não engole o som. */
    public function test_resumo_traz_o_ultimo_id_sonoro(): void
    {
        $ana = User::factory()->create();
        $base = ['destinatario_id' => $ana->id, 'nivel' => 'atencao', 'icone' => 'bell', 'titulo' => 'x'];

        $sonora = Notificacao::create($base + ['tipo' => 'direcionamento']);
        $muda = Notificacao::create($base + ['tipo' => 'triagem']);

        $this->actingAs($ana)->getJson(route('notificacoes.resumo'))
            ->assertOk()
            ->assertJson(['ultimo_id' => $muda->id, 'ultimo_sonoro_id' => $sonora->id]);
    }

    public function test_o_som_e_preferencia_da_conta(): void
    {
        $ana = User::factory()->create();
        $this->assertTrue($ana->fresh()->aviso_sonoro, 'nasce ligado');

        $this->actingAs($ana)->postJson(route('notificacoes.som'), ['ligado' => false])
            ->assertOk()
            ->assertJson(['ligado' => false]);

        $this->assertFalse($ana->fresh()->aviso_sonoro);

        $this->actingAs($ana->fresh())->postJson(route('notificacoes.som'), ['ligado' => 'talvez'])
            ->assertStatus(422);
    }
}
