<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Criar e editar tarefa pelo formulário do quadro: status inicial de acordo
 * com o responsável escolhido e vínculo com um sistema já cadastrado (T-063).
 */
class FormularioTarefaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-083 Tarefa salva sem responsável nasce na coluna Aberta.
     */
    public function test_tarefa_criada_sem_responsavel_nasce_aberta(): void
    {
        $usuario = User::factory()->create();

        $resposta = $this->actingAs($usuario)->post(route('tarefas.store'), [
            'titulo' => 'Ajustar relatório de vendas',
            'prioridade' => 'media',
        ]);

        $resposta->assertRedirect(route('tarefas.index'));
        $this->assertDatabaseHas('tarefas', [
            'titulo' => 'Ajustar relatório de vendas',
            'responsavel_id' => null,
            'status' => 'aberta',
        ]);
    }

    /**
     * @spec:AC-083 Tarefa salva com responsável já nasce direto no Backlog.
     */
    public function test_tarefa_criada_com_responsavel_nasce_no_backlog(): void
    {
        $usuario = User::factory()->create();
        $responsavel = User::factory()->create();

        $resposta = $this->actingAs($usuario)->post(route('tarefas.store'), [
            'titulo' => 'Corrigir tela de login',
            'responsavel_id' => $responsavel->id,
            'prioridade' => 'alta',
        ]);

        $resposta->assertRedirect(route('tarefas.index'));
        $this->assertDatabaseHas('tarefas', [
            'titulo' => 'Corrigir tela de login',
            'responsavel_id' => $responsavel->id,
            'status' => 'backlog',
        ]);
    }

    /**
     * @spec:AC-084 A tarefa criada fica vinculada ao sistema escolhido no formulário.
     */
    public function test_tarefa_criada_fica_vinculada_ao_sistema_escolhido(): void
    {
        $usuario = User::factory()->create();
        $sistema = Sistema::factory()->create(['nome' => 'AlfaControl', 'ativo' => true]);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.store'), [
            'titulo' => 'Integrar boleto',
            'sistema_id' => $sistema->id,
            'prioridade' => 'baixa',
        ]);

        $resposta->assertRedirect(route('tarefas.index'));
        $this->assertDatabaseHas('tarefas', [
            'titulo' => 'Integrar boleto',
            'sistema_id' => $sistema->id,
        ]);
    }

    /**
     * @spec:AC-084 Só sistemas ativos aparecem como opção no formulário de nova tarefa.
     */
    public function test_apenas_sistemas_ativos_sao_oferecidos_no_formulario(): void
    {
        $usuario = User::factory()->create();
        Sistema::factory()->create(['nome' => 'AlfaGym', 'slug' => 'alfagym', 'ativo' => true]);
        Sistema::factory()->create(['nome' => 'SistemaDesativado', 'slug' => 'desativado', 'ativo' => false]);

        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'));

        $resposta->assertOk();
        $resposta->assertSee('AlfaGym');
        $resposta->assertDontSee('SistemaDesativado');
    }

    /**
     * @spec:AC-137 Clique duplo no Salvar não cria a tarefa duas vezes.
     */
    public function test_clique_duplo_no_salvar_nao_cria_a_tarefa_duas_vezes(): void
    {
        $usuario = User::factory()->create();

        $envio = ['titulo' => 'Renovar certificado', 'prioridade' => 'alta'];

        $this->actingAs($usuario)->post(route('tarefas.store'), $envio);
        $this->actingAs($usuario)->post(route('tarefas.store'), $envio);

        $this->assertSame(1, Tarefa::where('titulo', 'Renovar certificado')->count());

        // A trava é uma janela, não uma mordaça: a mesma tarefa volta a ser
        // criável depois — refazer um trabalho é decisão de quem cadastra.
        $this->travel(2)->minutes();
        $this->actingAs($usuario)->post(route('tarefas.store'), $envio);
        $this->assertSame(2, Tarefa::where('titulo', 'Renovar certificado')->count());
        $this->travelBack();
    }

    /**
     * @spec:AC-137 A trava compara o formulário inteiro: cadastrar em série o
     * mesmo título para sistemas diferentes continua criando as duas.
     */
    public function test_mesmo_titulo_para_outro_sistema_continua_criando(): void
    {
        $usuario = User::factory()->create();
        $gym = Sistema::factory()->create(['nome' => 'AlfaGym', 'slug' => 'alfagym']);
        $control = Sistema::factory()->create(['nome' => 'AlfaControl', 'slug' => 'alfacontrol']);

        $this->actingAs($usuario)->post(route('tarefas.store'), [
            'titulo' => 'Renovar certificado', 'prioridade' => 'alta', 'sistema_id' => $gym->id,
        ]);
        $this->actingAs($usuario)->post(route('tarefas.store'), [
            'titulo' => 'Renovar certificado', 'prioridade' => 'alta', 'sistema_id' => $control->id,
        ]);

        $this->assertSame(2, Tarefa::where('titulo', 'Renovar certificado')->count());
    }

    /**
     * @spec:AC-216 O resumo digitado no modal chega ao banco — na criação e na
     * edição. Ele existia no formulário e no card, mas não na validação das
     * rotas, e `validate()` devolve só o que validou: o campo era preenchido,
     * enviado e descartado sem que nada na tela dissesse isso.
     */
    public function test_o_resumo_do_modal_e_gravado_na_criacao_e_na_edicao(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->post(route('tarefas.store'), [
            'titulo' => 'Exportar frequência',
            'resumo' => 'Academia reclamou que o export não traz linhas.',
            'prioridade' => 'alta',
        ]);

        $tarefa = Tarefa::where('titulo', 'Exportar frequência')->sole();
        $this->assertSame('Academia reclamou que o export não traz linhas.', $tarefa->resumo);

        $this->actingAs($usuario)->put(route('tarefas.update', $tarefa), [
            'titulo' => $tarefa->titulo,
            'resumo' => 'O filtro de data derrubava todas as linhas.',
            'prioridade' => $tarefa->prioridade,
        ]);

        $this->assertSame('O filtro de data derrubava todas as linhas.', $tarefa->fresh()->resumo);
    }

    /**
     * @spec:AC-216 Textarea limpo apaga o resumo; envio SEM o campo mantém o que
     * está gravado. A diferença importa porque a criação rápida do pé da coluna
     * manda só o título, e o modal de quem não triaga é um formulário curto —
     * nenhum dos dois pode zerar de passagem o resumo de uma tarefa.
     */
    public function test_resumo_vazio_apaga_e_envio_sem_o_campo_preserva(): void
    {
        $usuario = User::factory()->create();
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $usuario->id,
            'resumo' => 'Baixa automática ao receber o retorno do gateway.',
            'status' => 'backlog',
        ]);

        $this->actingAs($usuario)->put(route('tarefas.update', $tarefa), [
            'titulo' => $tarefa->titulo,
            'prioridade' => $tarefa->prioridade,
        ]);

        $this->assertSame('Baixa automática ao receber o retorno do gateway.', $tarefa->fresh()->resumo,
            'Envio sem o campo não pode apagar o resumo gravado.');

        $this->actingAs($usuario)->put(route('tarefas.update', $tarefa), [
            'titulo' => $tarefa->titulo,
            'resumo' => '',
            'prioridade' => $tarefa->prioridade,
        ]);

        $this->assertNull($tarefa->fresh()->resumo, 'Textarea limpo apaga o resumo.');
    }

    /**
     * @spec:AC-084 Editar uma tarefa existente troca o sistema vinculado, e o card passa a mostrar o novo sistema.
     */
    public function test_editar_tarefa_troca_o_sistema_vinculado(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();
        $sistemaAntigo = Sistema::factory()->create(['nome' => 'AlfaGym', 'slug' => 'alfagym']);
        $sistemaNovo = Sistema::factory()->create(['nome' => 'AlfaControl', 'slug' => 'alfacontrol']);

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'sistema_id' => $sistemaAntigo->id,
            'status' => 'backlog',
        ]);

        $resposta = $this->actingAs($usuario)->put(route('tarefas.update', $tarefa), [
            'titulo' => $tarefa->titulo,
            'sistema_id' => $sistemaNovo->id,
            'prioridade' => $tarefa->prioridade,
        ]);

        $resposta->assertRedirect(route('tarefas.index'));
        $this->assertSame($sistemaNovo->id, $tarefa->fresh()->sistema_id);

        $quadro = $this->actingAs($usuario)->get(route('tarefas.index'));
        $quadro->assertSee('AlfaControl');
    }
}
