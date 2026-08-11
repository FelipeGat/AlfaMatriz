<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Revenda;
use App\Models\Tarefa;
use App\Models\TarefaComentario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comentários da tarefa: a conversa que o título e o resumo não cabem.
 *
 * O comentário não tem envio próprio — ele é mais um campo do formulário da
 * tarefa e é publicado pelo mesmo Salvar (T-085). Apagar continua sendo
 * caminho próprio: é destrutivo, e não pode ir de carona no salvar.
 */
class ComentariosTarefaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O envio do modal de edição: o cadastro da tarefa mais, quando há, o
     * comentário novo.
     *
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function envioDoModal(Tarefa $tarefa, array $extra = []): array
    {
        return array_merge([
            'titulo' => $tarefa->titulo,
            'prioridade' => $tarefa->prioridade,
        ], $extra);
    }

    /**
     * @spec:AC-134 O comentário é publicado junto com o Salvar da tarefa, com
     * autor e data, e passa a aparecer nela.
     */
    public function test_comentario_e_publicado_junto_com_o_salvar_da_tarefa(): void
    {
        $autor = User::factory()->create(['name' => 'Marina']);
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $autor->id,
            'status' => 'em_desenvolvimento',
            'prioridade' => 'media',
        ]);

        $resposta = $this->actingAs($autor)->put(route('tarefas.update', $tarefa), $this->envioDoModal($tarefa, [
            'titulo' => 'Corrigir boleto vencido',
            'prioridade' => 'alta',
            'comentario' => 'O cliente confirmou que o erro só acontece no boleto vencido.',
        ]));

        $resposta->assertRedirect(route('tarefas.index'));

        // Um envio, duas coisas: o cadastro mudou E o comentário entrou.
        $tarefa->refresh();
        $this->assertSame('Corrigir boleto vencido', $tarefa->titulo);
        $this->assertSame('alta', $tarefa->prioridade);
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
     * @spec:AC-134 Campo em branco não publica nada: quem abriu o modal só
     * para trocar o responsável não ganha um comentário vazio na tarefa.
     */
    public function test_salvar_sem_comentario_nao_publica_nada(): void
    {
        $usuario = User::factory()->create();
        $responsavel = User::factory()->create();
        $tarefa = Tarefa::factory()->create(['criado_por_id' => $usuario->id]);

        $this->actingAs($usuario)
            ->put(route('tarefas.update', $tarefa), $this->envioDoModal($tarefa, [
                'responsavel_id' => $responsavel->id,
                'comentario' => '',
            ]))
            ->assertRedirect(route('tarefas.index'));

        // Espaço em branco também não é comentário.
        $this->actingAs($usuario)
            ->put(route('tarefas.update', $tarefa), $this->envioDoModal($tarefa, ['comentario' => "  \n "]))
            ->assertRedirect(route('tarefas.index'));

        $this->assertSame($responsavel->id, $tarefa->fresh()->responsavel_id);
        $this->assertSame(0, $tarefa->comentarios()->count());
    }

    /**
     * @spec:AC-134 Clique duplo no Salvar não publica o comentário duas vezes
     * — nem quando o botão que se tranca não chega a rodar.
     */
    public function test_clique_duplo_no_salvar_nao_duplica_o_comentario(): void
    {
        $autor = User::factory()->create();
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $autor->id,
            'status' => 'em_desenvolvimento',
        ]);

        $envio = $this->envioDoModal($tarefa, ['comentario' => 'O cliente ligou de novo hoje.']);

        $this->actingAs($autor)->put(route('tarefas.update', $tarefa), $envio);
        $this->actingAs($autor)->put(route('tarefas.update', $tarefa), $envio);

        $this->assertSame(1, $tarefa->comentarios()->count());

        // A trava é uma janela, não uma mordaça: passado o acidente, a mesma
        // frase volta a ser publicável.
        $this->travel(2)->minutes();
        $this->actingAs($autor)->put(route('tarefas.update', $tarefa), $envio);
        $this->assertSame(2, $tarefa->comentarios()->count());
        $this->travelBack();
    }

    /**
     * @spec:AC-134 A trava do reenvio é estreita: outro texto no mesmo minuto
     * é comentário de verdade e entra.
     */
    public function test_comentario_diferente_no_mesmo_minuto_continua_entrando(): void
    {
        $autor = User::factory()->create();
        $tarefa = Tarefa::factory()->create(['criado_por_id' => $autor->id]);

        $this->actingAs($autor)->put(
            route('tarefas.update', $tarefa),
            $this->envioDoModal($tarefa, ['comentario' => 'Primeiro ponto.']),
        );
        $this->actingAs($autor)->put(
            route('tarefas.update', $tarefa),
            $this->envioDoModal($tarefa, ['comentario' => 'Segundo ponto, logo em seguida.']),
        );

        $this->assertSame(2, $tarefa->comentarios()->count());
    }

    /**
     * @spec:AC-135 O comentário é texto puro: o que se digita é o que fica
     * gravado, sem nenhuma marcação interpretada — o traço continua traço.
     */
    public function test_comentario_e_guardado_e_exibido_como_texto_puro(): void
    {
        $usuario = User::factory()->create();
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $usuario->id,
            'status' => 'em_desenvolvimento',
        ]);

        $corpo = "Falta fechar:\n- ajustar o filtro\n1. subir o banco";

        $this->actingAs($usuario)->put(
            route('tarefas.update', $tarefa),
            $this->envioDoModal($tarefa, ['comentario' => $corpo]),
        );

        $this->assertSame($corpo, TarefaComentario::first()->corpo);

        $quadro = $this->actingAs($usuario)->get(route('tarefas.index'));
        $quadro->assertSee('- ajustar o filtro');
        $quadro->assertSee('1. subir o banco');
        // Nenhuma tag de lista sai da conversa: a quebra de linha é do CSS.
        $quadro->assertDontSee('<li>', escape: false);
    }

    /**
     * @spec:AC-135 HTML digitado no campo chega à tela como texto: o corpo sai
     * pelo escape normal do Blade, sem nenhuma conversão pelo caminho.
     */
    public function test_html_digitado_no_comentario_nao_vira_html(): void
    {
        $usuario = User::factory()->create();
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $usuario->id,
            'status' => 'em_desenvolvimento',
        ]);

        $this->actingAs($usuario)->put(
            route('tarefas.update', $tarefa),
            $this->envioDoModal($tarefa, ['comentario' => '<script>alert(1)</script> confere isto']),
        );

        $quadro = $this->actingAs($usuario)->get(route('tarefas.index'));

        $quadro->assertDontSee('<script>alert(1)</script>', escape: false);
        $quadro->assertSee('&lt;script&gt;alert(1)&lt;/script&gt; confere isto', escape: false);
    }

    /**
     * @spec:AC-136 Só o autor apaga o próprio comentário, e apagar é envio
     * próprio: o botão do lixo aponta para um formulário FORA do formulário da
     * tarefa, senão o clique publicaria o comentário que estivesse escrito.
     */
    public function test_apenas_o_autor_apaga_o_proprio_comentario(): void
    {
        $autor = User::factory()->create();
        $outro = User::factory()->create();
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $autor->id,
            'status' => 'em_desenvolvimento',
        ]);

        $comentario = $tarefa->comentarios()->create([
            'autor_id' => $autor->id,
            'corpo' => 'Anotação com erro de digitação.',
        ]);

        // Botão e formulário se acham pelo mesmo id: sem o par, o lixo não
        // apaga nada e a falha é silenciosa na tela.
        $quadro = $this->actingAs($autor)->get(route('tarefas.index'));
        $quadro->assertSee('form="apagar-comentario-'.$comentario->id.'"', escape: false);
        $quadro->assertSee('id="apagar-comentario-'.$comentario->id.'"', escape: false);

        $this->actingAs($outro)
            ->delete(route('tarefas.comentarios.destroy', $comentario))
            ->assertForbidden();
        $this->assertDatabaseHas('tarefa_comentarios', ['id' => $comentario->id]);

        $remocao = $this->actingAs($autor)->delete(route('tarefas.comentarios.destroy', $comentario));
        $remocao->assertRedirect();
        // Apagar recarrega a tela no meio da leitura: o modal volta aberto.
        $remocao->assertSessionHas('tarefa-aberta', $tarefa->id);
        $this->assertDatabaseMissing('tarefa_comentarios', ['id' => $comentario->id]);
    }

    /**
     * @spec:AC-136 A conversa da tarefa encerrada continua legível no
     * histórico — e só legível: lá não há campo de comentário.
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
        $historico->assertDontSee('name="comentario"', escape: false);
    }

    /**
     * @spec:AC-095 O bloqueio por escopo de revenda vale também para a
     * conversa — a porta nova não pode ser a única sem tranca.
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
            ->put(route('tarefas.update', $tarefa), $this->envioDoModal($tarefa, [
                'comentario' => 'Tentativa de comentário',
            ]))
            ->assertForbidden();
        $this->assertDatabaseMissing('tarefa_comentarios', ['corpo' => 'Tentativa de comentário']);

        $this->actingAs($daRevenda)
            ->delete(route('tarefas.comentarios.destroy', $comentario))
            ->assertForbidden();
        $this->assertDatabaseHas('tarefa_comentarios', ['id' => $comentario->id]);
    }
}
