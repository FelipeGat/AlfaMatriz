<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Notificacao;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quem revisa e quem testa é escolhido por tarefa, no movimento (US-087).
 *
 * O processo tem três atores — dev, revisor, testador — e os papéis rodam. O
 * quadro só conhecia dois lados, e o interlocutor ficava preso: apontado o
 * revisor, a pergunta do staging continuava indo para ele. Agora quem move
 * para um portão de exame pode apontar quem fica com a bola, e cada portão
 * recomeça a conversa.
 */
class QuemRevisaEQuemTestaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Uma tarefa de desenvolvimento em Em andamento, do próprio admin que a
     * move — o caso da pergunta original: "e se EU fizer e outro revisar?".
     *
     * @return array{0: Tarefa, 1: User}
     */
    private function minhaTarefaEmAndamento(): array
    {
        $dono = User::factory()->create(['name' => 'Rossini Alves']);

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $dono->id,
            'responsavel_id' => $dono->id,
            'status' => 'em_desenvolvimento',
        ]);

        return [$tarefa, $dono];
    }

    /**
     * @spec:AC-315 O painel do movimento oferece a escolha nas entradas dos
     * portões de exame — "Quem revisa?" / "Quem testa?" —, o menu anuncia
     * "apontar quem" nesses destinos, e o seletor envia o apontado.
     */
    public function test_o_quadro_oferece_o_seletor_nas_entradas_dos_portoes(): void
    {
        [, $dono] = $this->minhaTarefaEmAndamento();

        $resposta = $this->actingAs($dono)->get(route('tarefas.index'));

        $resposta->assertSee('Quem revisa?');
        $resposta->assertSee('Quem testa?');
        $resposta->assertSee('apontar quem');
        $resposta->assertSee('name="interlocutor_id"', false);
        $resposta->assertSee('sem apontar · a coluna é a fila');
    }

    /**
     * @spec:AC-316 Quem é apontado ao mover para um portão vira o outro lado da
     * tarefa e recebe o aviso no sino — a bola chega com o nome de quem a segura.
     */
    public function test_apontado_vira_interlocutor_e_recebe_aviso(): void
    {
        [$tarefa, $dono] = $this->minhaTarefaEmAndamento();
        $revisor = User::factory()->create(['name' => 'Felipe Torres']);

        $this->actingAs($dono)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_revisao',
            'de_status' => 'em_desenvolvimento',
            'interlocutor_id' => $revisor->id,
        ]);

        $tarefa->refresh();

        $this->assertSame('em_revisao', $tarefa->status);
        $this->assertSame($revisor->id, $tarefa->interlocutor_id);

        $aviso = Notificacao::where('tipo', 'apontamento')->first();

        $this->assertNotNull($aviso, 'O apontado precisa saber que a bola chegou nele.');
        $this->assertSame($revisor->id, $aviso->destinatario_id);
        $this->assertSame($tarefa->id, $aviso->tarefa_id);
    }

    /**
     * @spec:AC-317 A pergunta seguinte do responsável já aponta para quem foi
     * apontado — inclusive quando o apontado MUDA de um portão para o outro.
     */
    public function test_pergunta_do_responsavel_vai_para_o_apontado_da_passagem_atual(): void
    {
        [$tarefa, $dono] = $this->minhaTarefaEmAndamento();
        $revisor = User::factory()->create(['name' => 'Felipe Torres']);
        $testador = User::factory()->membro()->create(['name' => 'Alexandre Souza']);

        // Revisão com o Felipe; staging apontando o Alexandre.
        $this->actingAs($dono)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_revisao', 'interlocutor_id' => $revisor->id,
        ]);
        $this->actingAs($dono)->post(route('tarefas.mover', $tarefa->fresh()), [
            'status' => 'em_staging', 'interlocutor_id' => $testador->id,
        ]);

        $this->actingAs($dono);
        (new FluxoTarefaService)->perguntar($tarefa->fresh(), $dono, 'Staging no ar — pode validar?');

        // A bola foi para o testador desta passagem, não para o revisor de ontem.
        $this->assertSame($testador->id, $tarefa->fresh()->pergunta_para_id);
    }

    /**
     * @spec:AC-318 Entrar num portão sem apontar recomeça a conversa: o
     * interlocutor anterior é esquecido, as rodadas zeram, e a pergunta volta a
     * oferecer a escolha do destinatário.
     */
    public function test_entrar_no_portao_sem_apontar_recomeca_a_conversa(): void
    {
        [$tarefa, $dono] = $this->minhaTarefaEmAndamento();
        $revisor = User::factory()->create(['name' => 'Felipe Torres']);

        $this->actingAs($dono)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_revisao', 'interlocutor_id' => $revisor->id,
        ]);

        // Uma conversa que andou: rodadas acumuladas na revisão.
        $tarefa->fresh()->forceFill(['rodadas' => 2])->save();

        $this->actingAs($dono)->post(route('tarefas.mover', $tarefa->fresh()), [
            'status' => 'em_staging', 'de_status' => 'em_revisao',
        ]);

        $tarefa->refresh();

        $this->assertSame('em_staging', $tarefa->status);
        $this->assertNull($tarefa->interlocutor_id);
        $this->assertSame(0, $tarefa->rodadas);

        // Sem outro lado, a tela volta a perguntar a quem passar a vez.
        $this->assertNull($tarefa->outroLadoDe($dono));
    }

    /**
     * @spec:AC-319 O responsável não pode ser o apontado: a bola do portão vai
     * para quem examina, e ele já está na tarefa — a recusa explica isso.
     */
    public function test_apontar_o_responsavel_e_recusado(): void
    {
        [$tarefa, $dono] = $this->minhaTarefaEmAndamento();

        $this->actingAs($dono)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_revisao',
            'interlocutor_id' => $dono->id,
        ]);

        $tarefa->refresh();

        // O movimento inteiro é recusado: nem o status anda, nem a bola.
        $this->assertSame('em_desenvolvimento', $tarefa->status);
        $this->assertNull($tarefa->interlocutor_id);
    }
}
