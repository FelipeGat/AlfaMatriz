<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Revenda;
use App\Models\Tarefa;
use App\Models\TarefaComentario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comentários da tarefa: a conversa que o título e o resumo não cabem, com os
 * marcadores de lista que fazem uma enumeração ser conferível item a item
 * (T-083).
 */
class ComentariosTarefaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-134 O comentário é gravado com autor e data, e aparece na tarefa.
     */
    public function test_comentario_e_gravado_com_autor_e_aparece_no_quadro(): void
    {
        $autor = User::factory()->create(['name' => 'Marina']);
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $autor->id,
            'status' => 'em_desenvolvimento',
        ]);

        $resposta = $this->actingAs($autor)->post(route('tarefas.comentarios.store', $tarefa), [
            'corpo' => 'O cliente confirmou que o erro só acontece no boleto vencido.',
        ]);

        $resposta->assertRedirect();
        // O modal da tarefa volta aberto: conversa em que cada frase fecha a
        // janela não é conversa.
        $resposta->assertSessionHas('tarefa-aberta', $tarefa->id);
        $this->assertDatabaseHas('tarefa_comentarios', [
            'tarefa_id' => $tarefa->id,
            'autor_id' => $autor->id,
            'corpo' => 'O cliente confirmou que o erro só acontece no boleto vencido.',
        ]);

        $quadro = $this->actingAs($autor)->get(route('tarefas.index'));
        $quadro->assertOk();
        $quadro->assertSee('O cliente confirmou que o erro só acontece no boleto vencido.');
        $quadro->assertSee('Marina');
        // O selo do card é o único aviso de que existe conversa lá dentro.
        $quadro->assertSee('1 comentário');
    }

    /**
     * @spec:AC-134 Comentar devolve o quadro com o modal da tarefa aberto:
     * conversa em que cada frase fecha a janela não é conversa.
     */
    public function test_quadro_volta_com_o_modal_da_tarefa_aberto(): void
    {
        $autor = User::factory()->create();
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $autor->id,
            'status' => 'em_desenvolvimento',
        ]);

        $quadro = $this->actingAs($autor)
            ->from(route('tarefas.index'))
            ->followingRedirects()
            ->post(route('tarefas.comentarios.store', $tarefa), ['corpo' => 'Primeira frase da conversa.']);

        $quadro->assertOk();
        $quadro->assertSee("detail: 'editar-tarefa-{$tarefa->id}'", escape: false);
    }

    /**
     * @spec:AC-134 Comentário vazio não entra: sem texto não há o que detalhar.
     */
    public function test_comentario_sem_texto_e_recusado(): void
    {
        $usuario = User::factory()->create();
        $tarefa = Tarefa::factory()->create(['criado_por_id' => $usuario->id]);

        $this->actingAs($usuario)
            ->post(route('tarefas.comentarios.store', $tarefa), ['corpo' => '   '])
            ->assertSessionHasErrors('corpo');

        $this->assertSame(0, $tarefa->comentarios()->count());
    }

    /**
     * @spec:AC-135 Linhas com "-" viram lista com marcador e linhas com "1."
     * viram lista numerada, na mesma leitura.
     */
    public function test_marcadores_viram_lista_com_ponto_e_lista_numerada(): void
    {
        $usuario = User::factory()->create();
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $usuario->id,
            'status' => 'em_desenvolvimento',
        ]);

        $this->actingAs($usuario)->post(route('tarefas.comentarios.store', $tarefa), [
            'corpo' => "Falta fechar:\n- ajustar o filtro\n- refazer o teste\n\nNa ordem:\n1. subir o banco\n2. rodar a migração",
        ]);

        $html = TarefaComentario::first()->corpoEmHtml();

        $this->assertStringContainsString('<p>Falta fechar:</p>', $html);
        $this->assertStringContainsString('<ul><li>ajustar o filtro</li><li>refazer o teste</li></ul>', $html);
        $this->assertStringContainsString('<ol><li>subir o banco</li><li>rodar a migração</li></ol>', $html);

        $quadro = $this->actingAs($usuario)->get(route('tarefas.index'));
        $quadro->assertSee('<li>ajustar o filtro</li>', escape: false);
        $quadro->assertSee('<ol><li>subir o banco</li>', escape: false);
    }

    /**
     * @spec:AC-135 A lista numerada começa no número que a pessoa escreveu:
     * quem retoma no "4." está falando do quarto item.
     */
    public function test_lista_numerada_comeca_no_numero_escrito(): void
    {
        $this->assertStringContainsString(
            '<ol start="4"><li>revisar o layout</li><li>publicar</li></ol>',
            TarefaComentario::marcadoresEmHtml("4. revisar o layout\n5. publicar"),
        );
    }

    /**
     * @spec:AC-135 O corpo é escapado antes de virar HTML: só as tags de lista
     * e de parágrafo saem da conversão, o resto chega à tela como texto.
     */
    public function test_html_digitado_no_comentario_nao_vira_html(): void
    {
        $usuario = User::factory()->create();
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $usuario->id,
            'status' => 'em_desenvolvimento',
        ]);

        $this->actingAs($usuario)->post(route('tarefas.comentarios.store', $tarefa), [
            'corpo' => '- <script>alert(1)</script> confere isto',
        ]);

        $html = TarefaComentario::first()->corpoEmHtml();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);

        $quadro = $this->actingAs($usuario)->get(route('tarefas.index'));
        $quadro->assertDontSee('<script>alert(1)</script>', escape: false);
    }

    /**
     * @spec:AC-136 Só o autor apaga o próprio comentário.
     */
    public function test_apenas_o_autor_apaga_o_proprio_comentario(): void
    {
        $autor = User::factory()->create();
        $outro = User::factory()->create();
        $tarefa = Tarefa::factory()->create(['criado_por_id' => $autor->id]);

        $comentario = $tarefa->comentarios()->create([
            'autor_id' => $autor->id,
            'corpo' => 'Anotação com erro de digitação.',
        ]);

        $this->actingAs($outro)
            ->delete(route('tarefas.comentarios.destroy', $comentario))
            ->assertForbidden();
        $this->assertDatabaseHas('tarefa_comentarios', ['id' => $comentario->id]);

        $this->actingAs($autor)
            ->delete(route('tarefas.comentarios.destroy', $comentario))
            ->assertRedirect();
        $this->assertDatabaseMissing('tarefa_comentarios', ['id' => $comentario->id]);
    }

    /**
     * @spec:AC-136 A conversa da tarefa encerrada continua legível no
     * histórico — e só legível: lá não se comenta.
     */
    public function test_historico_mostra_a_conversa_sem_campo_de_escrever(): void
    {
        $usuario = User::factory()->create();
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $usuario->id,
            'status' => 'cancelada',
        ]);

        $tarefa->comentarios()->create([
            'autor_id' => $usuario->id,
            'corpo' => 'Cancelada porque o cliente desistiu do módulo.',
        ]);

        $historico = $this->actingAs($usuario)->get(route('tarefas.historico'));

        $historico->assertOk();
        $historico->assertSee('Cancelada porque o cliente desistiu do módulo.');
        $historico->assertDontSee(route('tarefas.comentarios.store', $tarefa));
    }

    /**
     * @spec:AC-095 O bloqueio por escopo de revenda vale também para as rotas
     * de comentário — a porta nova não pode ser a única sem tranca.
     */
    public function test_usuario_de_revenda_nao_comenta_nem_apaga(): void
    {
        $revenda = Revenda::create(['nome' => 'Alpha Rev', 'ativo' => true]);
        $daMatriz = User::factory()->create();
        $daRevenda = User::factory()->create(['revenda_id' => $revenda->id]);
        $tarefa = Tarefa::factory()->create(['criado_por_id' => $daMatriz->id]);

        $comentario = $tarefa->comentarios()->create([
            'autor_id' => $daMatriz->id,
            'corpo' => 'Conversa interna da matriz.',
        ]);

        $this->actingAs($daRevenda)
            ->post(route('tarefas.comentarios.store', $tarefa), ['corpo' => 'Tentativa de comentário'])
            ->assertForbidden();
        $this->assertDatabaseMissing('tarefa_comentarios', ['corpo' => 'Tentativa de comentário']);

        $this->actingAs($daRevenda)
            ->delete(route('tarefas.comentarios.destroy', $comentario))
            ->assertForbidden();
        $this->assertDatabaseHas('tarefa_comentarios', ['id' => $comentario->id]);
    }
}
