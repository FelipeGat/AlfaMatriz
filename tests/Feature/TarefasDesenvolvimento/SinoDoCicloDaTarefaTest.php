<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Notificacao;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O sino cobre o ciclo da tarefa de ponta a ponta (US-090): nascer com dono ou
 * na fila, mudar de mão, terminar, ser apagada. O quadro passa a controlar o
 * planejamento do time, e planejamento que só fala com quem está olhando não
 * planeja nada.
 */
class SinoDoCicloDaTarefaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-327 Criar já com responsável avisa quem recebeu a tarefa — e diz
     * em que etapa o card ficou e quem direcionou.
     */
    public function test_criar_com_responsavel_avisa_o_direcionado(): void
    {
        $admin = User::factory()->create(['name' => 'Camila Reis']);
        $dev = User::factory()->membro()->create(['name' => 'Rafael Lima']);

        $this->actingAs($admin)->post(route('tarefas.store'), [
            'titulo' => 'Webhook de pagamento',
            'responsavel_id' => $dev->id,
        ]);

        $aviso = Notificacao::where('tipo', 'direcionamento')->sole();

        $this->assertSame($dev->id, $aviso->destinatario_id);
        $this->assertStringContainsString('Webhook de pagamento', $aviso->titulo);
        $this->assertStringContainsString('direcionada a você', $aviso->titulo);
        $this->assertStringContainsString('Backlog', $aviso->meta);
        $this->assertStringContainsString('Camila Reis', $aviso->meta);
    }

    /** @spec:AC-327 Direcionar a si mesmo não ecoa: quem age já sabe o que fez. */
    public function test_criar_para_si_mesmo_nao_ecoa(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->post(route('tarefas.store'), [
            'titulo' => 'Tarefa minha',
            'responsavel_id' => $admin->id,
        ]);

        $this->assertSame(0, Notificacao::count());
    }

    /**
     * @spec:AC-328 A tarefa sem dono cai na fila e quem triaga fica sabendo —
     * menos quem criou; quem não triaga não recebe.
     */
    public function test_criar_sem_responsavel_avisa_quem_triaga(): void
    {
        $adminA = User::factory()->create(['name' => 'Camila Reis']);
        $adminB = User::factory()->create(['name' => 'Bruno Costa']);
        $membro = User::factory()->membro()->create(['name' => 'Rafael Lima']);

        $this->actingAs($adminA)->post(route('tarefas.store'), [
            'titulo' => 'Renovar o certificado',
        ]);

        $avisos = Notificacao::where('tipo', 'triagem')->get();

        $this->assertSame([$adminB->id], $avisos->pluck('destinatario_id')->all());
        $this->assertStringContainsString('aguarda triagem', $avisos->first()->titulo);
        $this->assertStringContainsString('Camila Reis', $avisos->first()->meta);
        $this->assertSame(0, Notificacao::where('destinatario_id', $membro->id)->count());
    }

    /**
     * @spec:AC-327 Trocar o responsável na edição avisa o novo dono; salvar sem
     * trocar não avisa ninguém.
     */
    public function test_trocar_responsavel_na_edicao_avisa_o_novo_dono(): void
    {
        $admin = User::factory()->create(['name' => 'Camila Reis']);
        $devAntigo = User::factory()->membro()->create();
        $devNovo = User::factory()->membro()->create(['name' => 'Alexandre Souza']);

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $admin->id,
            'responsavel_id' => $devAntigo->id,
            'status' => 'em_desenvolvimento',
            'titulo' => 'Relatório de vendas',
        ]);

        $this->actingAs($admin)->put(route('tarefas.update', $tarefa), [
            'titulo' => 'Relatório de vendas',
            'responsavel_id' => $devNovo->id,
        ]);

        $aviso = Notificacao::where('tipo', 'direcionamento')->sole();

        $this->assertSame($devNovo->id, $aviso->destinatario_id);
        $this->assertStringContainsString('Em andamento', $aviso->meta);

        // Salvar de novo sem mexer no responsável: nenhum aviso a mais.
        $this->actingAs($admin)->put(route('tarefas.update', $tarefa), [
            'titulo' => 'Relatório de vendas',
            'responsavel_id' => $devNovo->id,
        ]);

        $this->assertSame(1, Notificacao::where('tipo', 'direcionamento')->count());
    }

    /**
     * @spec:AC-329 Concluir avisa o criador e o responsável — menos quem moveu,
     * e uma vez só quando criador e responsável são a mesma pessoa.
     */
    public function test_concluir_avisa_criador_e_responsavel_menos_quem_moveu(): void
    {
        $criador = User::factory()->create(['name' => 'Camila Reis']);
        $dev = User::factory()->membro()->create(['name' => 'Rafael Lima']);
        $quemMove = User::factory()->create(['name' => 'Bruno Costa']);

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'responsavel_id' => $dev->id,
            'tipo' => 'operacional',
            'status' => 'em_desenvolvimento',
            'titulo' => 'Contato com o fabricante',
        ]);

        $this->actingAs($quemMove)->post(route('tarefas.mover', $tarefa), [
            'status' => 'concluida',
            'de_status' => 'em_desenvolvimento',
        ]);

        $avisos = Notificacao::where('tipo', 'conclusao')->get();

        $this->assertEqualsCanonicalizing(
            [$criador->id, $dev->id],
            $avisos->pluck('destinatario_id')->all(),
        );
        $this->assertStringContainsString('foi concluída', $avisos->first()->titulo);
    }

    /**
     * @spec:AC-329 A conclusão de desenvolvimento leva a versão de produção na
     * meta — é o que liga o aviso à tag que subiu.
     */
    public function test_conclusao_de_desenvolvimento_leva_a_versao(): void
    {
        $criador = User::factory()->create();
        $admin = User::factory()->create();

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'responsavel_id' => $criador->id,
            'tipo' => 'desenvolvimento',
            'status' => 'pronta_producao',
        ]);

        $this->actingAs($admin);
        (new FluxoTarefaService)->mover($tarefa, 'concluida', ['versao_producao' => 'v2.4.0']);

        $aviso = Notificacao::where('tipo', 'conclusao')->sole();

        $this->assertSame($criador->id, $aviso->destinatario_id);
        $this->assertStringContainsString('v2.4.0', $aviso->meta);
    }

    /** @spec:AC-329 Cancelar avisa com o motivo — a informação inteira do aviso. */
    public function test_cancelar_avisa_com_o_motivo(): void
    {
        $criador = User::factory()->create(['name' => 'Camila Reis']);
        $admin = User::factory()->create(['name' => 'Bruno Costa']);

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'responsavel_id' => null,
            'status' => 'aberta',
            'titulo' => 'Ideia antiga',
        ]);

        $this->actingAs($admin)->post(route('tarefas.mover', $tarefa), [
            'status' => 'cancelada',
            'de_status' => 'aberta',
            'motivo' => 'O cliente desistiu do projeto.',
        ]);

        $aviso = Notificacao::where('tipo', 'cancelamento')->sole();

        $this->assertSame($criador->id, $aviso->destinatario_id);
        $this->assertSame('atencao', $aviso->nivel);
        $this->assertStringContainsString('foi cancelada', $aviso->titulo);
        $this->assertStringContainsString('desistiu', $aviso->meta);
    }

    /**
     * @spec:AC-330 Excluir avisa criador e responsável ANTES de sumir, e o aviso
     * nasce sem `tarefa_id` — preso à tarefa, ele apagaria em cascata junto.
     */
    public function test_excluir_avisa_e_o_aviso_sobrevive_ao_force_delete(): void
    {
        $criador = User::factory()->membro()->create(['name' => 'Rafael Lima']);
        $admin = User::factory()->create(['name' => 'Camila Reis']);

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'responsavel_id' => $criador->id,
            'status' => 'aberta',
            'titulo' => 'Tarefa duplicada',
        ]);

        $this->actingAs($admin)->delete(route('tarefas.destroy', $tarefa));

        $this->assertDatabaseMissing('tarefas', ['id' => $tarefa->id]);

        $aviso = Notificacao::where('tipo', 'exclusao')->sole();

        $this->assertSame($criador->id, $aviso->destinatario_id);
        $this->assertNull($aviso->tarefa_id);
        $this->assertStringContainsString('Tarefa duplicada', $aviso->titulo);
        $this->assertStringContainsString('Camila Reis', $aviso->meta);
    }

    /**
     * @spec:AC-331 O carimbo do painel de mover avisa o responsável como o botão
     * de testar (AC-307): o veredito é um fato só, venha por onde vier.
     */
    public function test_carimbo_do_painel_de_mover_avisa_o_responsavel(): void
    {
        $dev = User::factory()->membro()->create(['name' => 'Rafael Lima']);
        $admin = User::factory()->create(['name' => 'Camila Reis']);

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $dev->id,
            'responsavel_id' => $dev->id,
            'tipo' => 'desenvolvimento',
            'status' => 'em_staging',
            'titulo' => 'Webhook de pagamento',
        ]);

        $this->actingAs($admin)->post(route('tarefas.mover', $tarefa), [
            'status' => 'pronta_producao',
            'de_status' => 'em_staging',
            'relatorio_aprovado' => '1',
        ]);

        $this->assertSame('pronta_producao', $tarefa->fresh()->status);

        $aviso = Notificacao::where('tipo', 'teste_staging')->sole();

        $this->assertSame($dev->id, $aviso->destinatario_id);
        $this->assertStringContainsString('Camila Reis aprovou o staging', $aviso->titulo);
    }
}
