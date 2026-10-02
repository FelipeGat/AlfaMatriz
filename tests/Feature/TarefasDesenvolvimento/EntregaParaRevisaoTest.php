<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Tarefa;
use App\Models\TarefaEntrega;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A entrega para a revisão (#210): o que foi feito, como testar e o PR, cobrados
 * pelo motor na saída da bancada e mostrados a quem revisa e testa.
 */
class EntregaParaRevisaoTest extends TestCase
{
    use RefreshDatabase;

    private const ENTREGA = [
        'o_que_foi_feito' => 'O boleto deixou de sair duplicado.',
        'como_testar' => "1. Gerar o boleto da Orbe\n2. Conferir que sai um só",
        'pr_commits' => 'https://github.com/alfa/matriz/pull/42 e a1b2c3d',
    ];

    private function emAndamento(array $atributos = []): array
    {
        $dono = User::factory()->create(['name' => 'Alexandre Blank']);

        $tarefa = Tarefa::factory()->create(array_merge([
            'criado_por_id' => $dono->id,
            'responsavel_id' => $dono->id,
            'status' => 'em_desenvolvimento',
        ], $atributos));

        return [$tarefa, $dono];
    }

    public function test_o_motor_recusa_a_ida_para_a_revisao_sem_a_entrega(): void
    {
        [$tarefa, $dono] = $this->emAndamento();
        $this->actingAs($dono);

        foreach ([[], ['o_que_foi_feito' => 'Algo.'], ['como_testar' => 'Assim.'], ['o_que_foi_feito' => '  ', 'como_testar' => 'Assim.']] as $dados) {
            try {
                (new FluxoTarefaService)->mover($tarefa->fresh(), 'em_revisao', $dados);
                $this->fail('A ida para a revisão sem a entrega deveria ser recusada.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Para mandar para revisão, diga o que foi feito e como testar.', $e->getMessage());
            }
        }

        $this->assertSame('em_desenvolvimento', $tarefa->fresh()->status);
        $this->assertSame(0, TarefaEntrega::count());
    }

    /**
     * O atalho da triagem também entrega (decisão do dono em 02/10/2026):
     * levar da fila direto para a revisão pula a bancada, não a entrega. A
     * reabertura direto na revisão é volta, e pede motivo.
     */
    public function test_o_atalho_da_triagem_vindo_da_fila_tambem_pede_a_entrega(): void
    {
        [$tarefa, $dono] = $this->emAndamento(['status' => 'backlog']);
        $this->actingAs($dono);
        $fluxo = new FluxoTarefaService;

        try {
            $fluxo->mover($tarefa->fresh(), 'em_revisao', [], livre: true);
            $this->fail('O atalho da fila para a revisão sem entrega deveria ser recusado.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Para mandar para revisão, diga o que foi feito e como testar.', $e->getMessage());
        }

        $fluxo->mover($tarefa->fresh(), 'em_revisao', self::ENTREGA, livre: true);
        $this->assertSame(1, $tarefa->fresh()->entregas()->count());

        $concluida = Tarefa::factory()->create(['status' => 'concluida', 'responsavel_id' => $dono->id]);
        $fluxo->mover($concluida, 'em_revisao', ['motivo' => 'Reexaminar no ar.'], livre: true);
        $this->assertSame(0, $concluida->fresh()->entregas()->count(), 'Reabrir na revisão é volta: pede motivo, não entrega.');
    }

    public function test_o_bug_tambem_entrega(): void
    {
        [$tarefa, $dono] = $this->emAndamento(['tipo' => 'bug']);
        $this->actingAs($dono);

        $this->expectExceptionMessage('Para mandar para revisão, diga o que foi feito e como testar.');

        (new FluxoTarefaService)->mover($tarefa, 'em_revisao');
    }

    public function test_a_entrega_nasce_presa_a_passagem_com_autor_e_numero(): void
    {
        [$tarefa, $dono] = $this->emAndamento();
        $this->actingAs($dono);

        (new FluxoTarefaService)->mover($tarefa, 'em_revisao', [
            'o_que_foi_feito' => '  O boleto deixou de sair duplicado.  ',
            'como_testar' => 'Gerar o boleto.',
            'pr_commits' => '   ',
        ]);

        $entrega = TarefaEntrega::sole();
        $chegada = $tarefa->eventos()->whereNull('saiu_em')->sole();

        $this->assertSame('em_revisao', $chegada->para_status);
        $this->assertSame($chegada->id, $entrega->tarefa_evento_id);
        $this->assertSame($dono->id, $entrega->user_id);
        $this->assertSame(1, $entrega->numero);
        $this->assertSame('O boleto deixou de sair duplicado.', $entrega->o_que_foi_feito);
        $this->assertNull($entrega->pr_commits);
        $this->assertTrue($entrega->is($tarefa->fresh()->entregaAtual()));
    }

    /**
     * Quem faz triagem move livre, mas a exigência de chegada vale para todos:
     * é a tela recusando com a frase do motor.
     */
    public function test_a_tela_cobra_a_entrega_ate_de_quem_triaga(): void
    {
        [$tarefa] = $this->emAndamento();
        $admin = User::factory()->create();

        $this->actingAs($admin)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_revisao', 'de_status' => 'em_desenvolvimento',
        ])->assertSessionHas('erro', 'Para mandar para revisão, diga o que foi feito e como testar.');

        $this->assertSame('em_desenvolvimento', $tarefa->fresh()->status);

        $this->actingAs($admin)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_revisao', 'de_status' => 'em_desenvolvimento',
        ] + self::ENTREGA)->assertSessionMissing('erro');

        $this->assertSame('em_revisao', $tarefa->fresh()->status);
        $this->assertSame($admin->id, TarefaEntrega::sole()->user_id);
    }

    /**
     * Voltou para correção e subiu de novo: nasce a 2ª, e a 1ª fica como
     * estava. Na bancada, nenhuma vale — a última fala do código devolvido.
     */
    public function test_a_volta_para_correcao_pede_uma_segunda_entrega_sem_tocar_a_primeira(): void
    {
        [$tarefa, $dono] = $this->emAndamento();
        $this->actingAs($dono);
        $fluxo = new FluxoTarefaService;

        $fluxo->mover($tarefa, 'em_revisao', self::ENTREGA);
        $primeira = TarefaEntrega::sole();

        $fluxo->mover($tarefa->fresh(), 'em_desenvolvimento', ['motivo' => 'Faltou o caso do boleto avulso.']);
        $this->assertNull($tarefa->fresh()->entregaAtual());

        $fluxo->mover($tarefa->fresh(), 'em_revisao', [
            'o_que_foi_feito' => 'Cobri o boleto avulso.',
            'como_testar' => 'Gerar um boleto avulso.',
        ]);

        $tarefa = $tarefa->fresh();
        $this->assertSame([1, 2], $tarefa->entregas->pluck('numero')->all());
        $this->assertSame($primeira->o_que_foi_feito, $primeira->fresh()->o_que_foi_feito);
        $this->assertSame($primeira->updated_at->toIso8601String(), $primeira->fresh()->updated_at->toIso8601String());
        $this->assertSame('Cobri o boleto avulso.', $tarefa->entregaAtual()->o_que_foi_feito);
    }

    /**
     * As voltas DENTRO dos portões reexaminam o mesmo código: cobram motivo,
     * não entrega, e a entrega da passagem continua valendo.
     */
    public function test_a_volta_do_staging_para_a_revisao_nao_pede_entrega_e_mantem_a_atual(): void
    {
        [$tarefa] = $this->emAndamento();
        $admin = User::factory()->create();
        $this->actingAs($admin);
        $fluxo = new FluxoTarefaService;

        $fluxo->mover($tarefa, 'em_revisao', self::ENTREGA);
        $fluxo->mover($tarefa->fresh(), 'em_staging');
        $this->assertNotNull($tarefa->fresh()->entregaAtual());

        $fluxo->mover($tarefa->fresh(), 'em_revisao', ['motivo' => 'Reexaminar o PR.'], livre: true);

        $this->assertSame(1, TarefaEntrega::count());
        $this->assertSame(self::ENTREGA['o_que_foi_feito'], $tarefa->fresh()->entregaAtual()->o_que_foi_feito);
    }

    /**
     * O movimento livre pode pular a revisão depois de uma devolução: o código
     * em staging já não é o da entrega antiga, e mostrá-la mentiria.
     */
    public function test_a_entrega_antiga_nao_vale_depois_de_uma_volta_a_bancada(): void
    {
        [$tarefa] = $this->emAndamento();
        $admin = User::factory()->create();
        $this->actingAs($admin);
        $fluxo = new FluxoTarefaService;

        $fluxo->mover($tarefa, 'em_revisao', self::ENTREGA);
        $fluxo->mover($tarefa->fresh(), 'em_desenvolvimento', ['motivo' => 'Refazer.']);
        $fluxo->mover($tarefa->fresh(), 'em_staging', livre: true);

        $this->assertNull($tarefa->fresh()->entregaAtual());
    }

    public function test_o_modal_mostra_a_entrega_atual_com_link_e_as_anteriores(): void
    {
        [$tarefa, $dono] = $this->emAndamento();
        $this->actingAs($dono);
        $fluxo = new FluxoTarefaService;

        $fluxo->mover($tarefa, 'em_revisao', ['o_que_foi_feito' => 'Primeira tentativa.', 'como_testar' => 'Do jeito antigo.']);
        $fluxo->mover($tarefa->fresh(), 'em_desenvolvimento', ['motivo' => 'Refazer.']);
        $fluxo->mover($tarefa->fresh(), 'em_revisao', self::ENTREGA);

        $html = $this->get(route('tarefas.modal', $tarefa))->assertOk()->getContent();

        $this->assertStringContainsString('2ª entrega', $html);
        $this->assertStringContainsString('O boleto deixou de sair duplicado.', $html);
        // As quebras de linha de quem escreveu chegam inteiras (o pre-wrap mostra).
        $this->assertStringContainsString("1. Gerar o boleto da Orbe\n2. Conferir que sai um só", $html);
        $this->assertStringContainsString('<a href="https://github.com/alfa/matriz/pull/42"', $html);
        $this->assertStringContainsString('a1b2c3d', $html);
        $this->assertStringContainsString('Ver a entrega anterior', $html);
        $this->assertStringContainsString('Primeira tentativa.', $html);
        $this->assertStringContainsString('Alexandre Blank', $html);
    }

    public function test_o_texto_do_pr_nao_vira_html(): void
    {
        [$tarefa, $dono] = $this->emAndamento();
        $this->actingAs($dono);

        (new FluxoTarefaService)->mover($tarefa, 'em_revisao', [
            'o_que_foi_feito' => '<script>alert(1)</script>',
            'como_testar' => 'Abrir.',
            'pr_commits' => 'javascript:alert(1) <b>x</b> https://exemplo.com/pr/7.',
        ]);

        $html = $this->get(route('tarefas.modal', $tarefa))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        // O ponto que fecha a frase fica fora do link.
        $this->assertStringContainsString('<a href="https://exemplo.com/pr/7"', $html);
    }

    /** Sem backfill: a tarefa que já estava na revisão não ganha bloco nem cobrança. */
    public function test_tarefa_ja_nos_portoes_segue_sem_entrega(): void
    {
        [$tarefa, $dono] = $this->emAndamento(['status' => 'em_revisao']);
        $this->actingAs($dono);

        $this->get(route('tarefas.modal', $tarefa))->assertOk()->assertDontSee('para a revisão');

        (new FluxoTarefaService)->mover($tarefa, 'em_staging');

        $this->assertSame('em_staging', $tarefa->fresh()->status);
        $this->assertSame(0, TarefaEntrega::count());
    }

    public function test_a_busca_acha_a_tarefa_pelo_hash_e_pelo_pr(): void
    {
        [$tarefa, $dono] = $this->emAndamento(['titulo' => 'Boleto duplicado']);
        Tarefa::factory()->create(['titulo' => 'Outra coisa', 'status' => 'em_revisao']);
        $this->actingAs($dono);

        (new FluxoTarefaService)->mover($tarefa, 'em_revisao', self::ENTREGA);

        foreach (['a1b2c3d', 'pull/42', 'Conferir que sai'] as $termo) {
            $titulos = $this->get(route('tarefas.index', ['busca' => $termo]))
                ->assertOk()->viewData('tarefas')->pluck('titulo')->all();

            $this->assertSame(['Boleto duplicado'], $titulos, "A busca por {$termo}");
        }
    }

    public function test_o_historico_mostra_as_entregas(): void
    {
        [$tarefa, $dono] = $this->emAndamento();
        $this->actingAs($dono);

        (new FluxoTarefaService)->mover($tarefa, 'em_revisao', self::ENTREGA);
        $tarefa->fresh()->forceFill(['status' => 'concluida'])->save();

        $this->get(route('tarefas.historico'))
            ->assertOk()
            ->assertSee('Entregas para a revisão')
            ->assertSee('O boleto deixou de sair duplicado.');
    }

    /** O quadro pede os três campos no painel e anuncia a entrega no menu. */
    public function test_o_quadro_pede_a_entrega_ao_mover_para_a_revisao(): void
    {
        [, $dono] = $this->emAndamento();

        $html = $this->actingAs($dono)->get(route('tarefas.index'))->assertOk()->getContent();

        $this->assertStringContainsString('pede entrega', $html);
        $this->assertStringContainsString('name="o_que_foi_feito"', $html);
        $this->assertStringContainsString('name="como_testar"', $html);
        $this->assertStringContainsString('name="pr_commits"', $html);
    }

    public function test_os_trechos_do_pr_separam_os_enderecos(): void
    {
        $entrega = new TarefaEntrega(['pr_commits' => "PR https://x.dev/pr/1, commit abc\nftp://y"]);

        $this->assertSame([
            ['texto' => 'PR ', 'url' => null],
            ['texto' => 'https://x.dev/pr/1', 'url' => 'https://x.dev/pr/1'],
            ['texto' => ',', 'url' => null],
            ['texto' => " commit abc\nftp://y", 'url' => null],
        ], $entrega->trechosDoPr());
    }
}
