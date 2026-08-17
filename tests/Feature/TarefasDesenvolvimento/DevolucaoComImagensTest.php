<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Tarefa;
use App\Models\TarefaAnexo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Imagens na devolução para correção.
 *
 * O motivo da reprovação tem uma metade que o texto não carrega: "o botão saiu
 * do lugar" é uma frase que só quem viu a tela entende. O painel de devolução
 * passa a levar o print JUNTO do motivo, no mesmo POST que move o card — e o
 * banner de retorno mostra essas imagens ao lado da frase de que elas são a
 * prova. Como arquivo, elas são anexos comuns da tarefa (US-064): entram no
 * acervo e ficam depois que a tarja sai.
 */
class DevolucaoComImagensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // O mesmo disco falso dos anexos: o de verdade é o `public`, único que
        // sobrevive à publicação azul/verde.
        Storage::fake('public');
    }

    private function criarTarefa(array $atributos = []): Tarefa
    {
        return Tarefa::factory()->create(array_merge([
            'criado_por_id' => User::factory(),
            'status' => 'em_revisao',
        ], $atributos));
    }

    public function test_devolver_com_imagens_grava_os_anexos_e_os_prende_a_tarja(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_desenvolvimento',
            'motivo' => 'O botão de baixa sumiu no tema escuro.',
            'anexos' => [
                UploadedFile::fake()->image('tema-claro.png', 800, 600),
                UploadedFile::fake()->image('tema-escuro.png', 800, 600),
            ],
        ]);

        $resposta->assertSessionMissing('erro');

        $tarefa = $tarefa->fresh();
        $this->assertSame('em_desenvolvimento', $tarefa->status);
        $this->assertSame('em_revisao', $tarefa->retorno_de);

        // As imagens são anexos COMUNS da tarefa — mesmo autor, mesmo disco,
        // mesma seção do modal — e a tarja guarda só QUAIS delas vieram com
        // esta devolução.
        $anexos = $tarefa->anexos;
        $this->assertCount(2, $anexos);
        $this->assertTrue($anexos->every(fn ($anexo) => $anexo->eh_imagem));
        $this->assertTrue($anexos->every(fn ($anexo) => $anexo->autor_id === $usuario->id));
        $anexos->each(fn ($anexo) => Storage::disk('public')->assertExists($anexo->caminho));

        $this->assertEqualsCanonicalizing($anexos->pluck('id')->all(), $tarefa->retorno_anexo_ids);
        $this->assertCount(2, $tarefa->retornoAnexos());
    }

    public function test_o_banner_do_retorno_mostra_as_imagens_da_devolucao(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa(['responsavel_id' => $usuario->id]);

        $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_desenvolvimento',
            'motivo' => 'O total sai errado com desconto.',
            'anexos' => [UploadedFile::fake()->image('total-errado.png', 800, 600)],
        ])->assertSessionMissing('erro');

        $modal = $this->actingAs($usuario)
            ->get(route('tarefas.modal', $tarefa))
            ->assertOk()
            ->getContent();

        // A miniatura aparece DENTRO do banner: depois do rótulo do portão e
        // antes do primeiro campo — é ali que o motivo aparece por extenso, e
        // a imagem é a metade dele que o texto não carrega.
        $anexo = $tarefa->fresh()->retornoAnexos()->sole();
        $miniatura = strpos($modal, e($anexo->url_miniatura));

        $this->assertNotFalse($miniatura, 'O banner do retorno não traz a miniatura da devolução.');
        $this->assertGreaterThan(strpos($modal, 'Voltou da revisão'), $miniatura);
        $this->assertLessThan(strpos($modal, 'name="titulo"'), $miniatura);
    }

    public function test_a_devolucao_so_aceita_imagem(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_desenvolvimento',
            'motivo' => 'Log em anexo.',
            'anexos' => [UploadedFile::fake()->create('erro.pdf', 40, 'application/pdf')],
        ]);

        // O log e a planilha continuam entrando pela tarefa aberta (US-064) —
        // o painel pede o print do que reprovou, e nada mais. A recusa segura
        // o movimento INTEIRO: mover e descartar o arquivo em silêncio seria
        // pior do que recusar dizendo.
        $resposta->assertSessionHasErrors('anexos.0');
        $this->assertSame('em_revisao', $tarefa->fresh()->status);
        $this->assertSame(0, TarefaAnexo::count());
    }

    public function test_ate_tres_imagens_por_devolucao(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_desenvolvimento',
            'motivo' => 'Prints em anexo.',
            'anexos' => [
                UploadedFile::fake()->image('a.png'),
                UploadedFile::fake()->image('b.png'),
                UploadedFile::fake()->image('c.png'),
                UploadedFile::fake()->image('d.png'),
            ],
        ]);

        $resposta->assertSessionHasErrors('anexos');
        $this->assertSame('em_revisao', $tarefa->fresh()->status);
        $this->assertSame(0, TarefaAnexo::count());
    }

    public function test_devolucao_recusada_pelo_motor_nao_grava_imagem_nenhuma(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        // Sem motivo o motor recusa a devolução — e o print não pode ter
        // tocado o disco antes dessa resposta: anexo de uma devolução que não
        // aconteceu é arquivo órfão, que não dá erro nenhum até virar disco
        // cheio.
        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_desenvolvimento',
            'anexos' => [UploadedFile::fake()->image('print.png')],
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertSame('em_revisao', $tarefa->fresh()->status);
        $this->assertSame(0, TarefaAnexo::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_seguir_em_frente_solta_a_tarja_e_preserva_os_anexos(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_desenvolvimento',
            'motivo' => 'Falta o estado vazio.',
            'anexos' => [UploadedFile::fake()->image('estado-vazio.png')],
        ])->assertSessionMissing('erro');

        $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa->fresh()), [
            'status' => 'em_revisao',
        ])->assertSessionMissing('erro');

        // A tarja morreu e levou o vínculo; o anexo fica — ele é prova da
        // tarefa, não da tarja.
        $tarefa = $tarefa->fresh();
        $this->assertNull($tarefa->retorno_de);
        $this->assertEmpty($tarefa->retorno_anexo_ids);
        $this->assertCount(0, $tarefa->retornoAnexos());
        $this->assertCount(1, $tarefa->anexos);
    }

    public function test_nova_devolucao_sem_imagem_nao_herda_as_da_anterior(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_desenvolvimento',
            'motivo' => 'Primeira reprovação, com print.',
            'anexos' => [UploadedFile::fake()->image('primeira.png')],
        ])->assertSessionMissing('erro');

        $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa->fresh()), [
            'status' => 'em_revisao',
        ])->assertSessionMissing('erro');

        $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa->fresh()), [
            'status' => 'em_desenvolvimento',
            'motivo' => 'Segunda reprovação, só texto.',
        ])->assertSessionMissing('erro');

        // O banner da segunda devolução não pode ilustrar o motivo novo com o
        // print da reprovação anterior — a imagem antiga segue no acervo, mas
        // fora da tarja.
        $tarefa = $tarefa->fresh();
        $this->assertSame('Segunda reprovação, só texto.', $tarefa->retorno_motivo);
        $this->assertEmpty($tarefa->retorno_anexo_ids);
        $this->assertCount(0, $tarefa->retornoAnexos());
        $this->assertCount(1, $tarefa->anexos);
    }

    public function test_movimento_que_nao_e_devolucao_guarda_a_imagem_sem_tarja(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa(['status' => 'em_desenvolvimento']);

        // Nenhuma tela manda imagem fora da devolução, mas a rota é uma só —
        // e um envio montado à mão não pode nem sumir com o arquivo nem
        // carimbar uma tarja que o movimento não criou.
        $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_revisao',
            'anexos' => [UploadedFile::fake()->image('print-avulso.png')],
        ])->assertSessionMissing('erro');

        $tarefa = $tarefa->fresh();
        $this->assertSame('em_revisao', $tarefa->status);
        $this->assertNull($tarefa->retorno_de);
        $this->assertEmpty($tarefa->retorno_anexo_ids);
        $this->assertCount(1, $tarefa->anexos);
    }
}
