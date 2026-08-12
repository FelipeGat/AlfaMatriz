<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Triagem é capacidade, não cargo (US-061).
 *
 * Quem não a tem trabalha no quadro do mesmo jeito — abre, comenta, bloqueia,
 * mexe no checklist e toca as próprias tarefas. O que ele não faz é organizar o
 * trabalho dos outros: priorizar, direcionar e mexer no que está com alguém.
 */
class PerfisDoQuadroTest extends TestCase
{
    use RefreshDatabase;

    private function criarTarefa(array $atributos = []): Tarefa
    {
        return Tarefa::factory()->create(array_merge([
            'criado_por_id' => User::factory(),
            'status' => 'em_desenvolvimento',
        ], $atributos));
    }

    /**
     * @spec:AC-203 Quem não triaga abre tarefa, e ela nasce "A definir" e sem dono —
     * mesmo que o envio traga os dois campos. Esconder na tela não é regra: um
     * formulário guardado ou um POST à mão passariam por cima dela.
     */
    public function test_tarefa_aberta_por_quem_nao_triaga_nasce_sem_prioridade_e_sem_dono(): void
    {
        $membro = User::factory()->membro()->create();
        $outra = User::factory()->create();

        // O envio HONESTO do formulário dele: sem os dois campos, que a tela
        // não mostra. Exigi-los na rota faria a tela funcionar e o salvar dizer
        // não a um campo que a pessoa não tem como preencher.
        $this->actingAs($membro)->post(route('tarefas.store'), [
            'titulo' => 'Corrigir o boleto vencido',
        ])->assertSessionHasNoErrors();

        $tarefa = Tarefa::firstWhere('titulo', 'Corrigir o boleto vencido');

        $this->assertNotNull($tarefa, 'Abrir tarefa não é restrito a quem triaga.');
        $this->assertSame('nao_definida', $tarefa->prioridade);
        $this->assertNull($tarefa->responsavel_id);
        $this->assertSame($membro->id, $tarefa->criado_por_id);

        // E o envio FORJADO — formulário guardado, "voltar" do navegador, POST
        // à mão — não passa por cima da regra.
        $this->actingAs($membro)->post(route('tarefas.store'), [
            'titulo' => 'Tentativa de triagem',
            'prioridade' => 'critica',
            'responsavel_id' => $outra->id,
        ])->assertSessionHasNoErrors();

        $forjada = Tarefa::firstWhere('titulo', 'Tentativa de triagem');

        $this->assertSame('nao_definida', $forjada->prioridade);
        $this->assertNull($forjada->responsavel_id);
    }

    /**
     * @spec:AC-203 Na edição, o que a triagem decidiu fica como está: salvar a tarefa não
     * pode zerar a prioridade nem soltar o responsável de passagem.
     */
    public function test_quem_nao_triaga_nao_altera_prioridade_nem_responsavel_ao_salvar(): void
    {
        $membro = User::factory()->membro()->create();
        $dona = User::factory()->create();
        $tarefa = $this->criarTarefa(['responsavel_id' => $dona->id, 'prioridade' => 'alta']);

        $this->actingAs($membro)->put(route('tarefas.update', $tarefa), [
            'titulo' => 'Título corrigido pelo time',
            'prioridade' => 'baixa',
            'responsavel_id' => $membro->id,
        ])->assertSessionHasNoErrors();

        $tarefa->refresh();

        // O que ele pode mexer, mexeu.
        $this->assertSame('Título corrigido pelo time', $tarefa->titulo);

        // O que é da triagem, não.
        $this->assertSame('alta', $tarefa->prioridade);
        $this->assertSame($dona->id, $tarefa->responsavel_id);
    }

    /**
     * @spec:AC-204 Quem não triaga move as próprias tarefas e recebe a recusa DITA nas
     * dos outros — "não permitido" sem dizer de quem é a tarefa manda adivinhar.
     */
    public function test_quem_nao_triaga_move_a_propria_e_recebe_recusa_dita_na_alheia(): void
    {
        $membro = User::factory()->membro()->create();
        $camila = User::factory()->create(['name' => 'Camila Reis']);

        $minha = $this->criarTarefa(['responsavel_id' => $membro->id]);
        $dela = $this->criarTarefa(['responsavel_id' => $camila->id]);

        // A própria anda.
        $this->actingAs($membro)->post(route('tarefas.mover', $minha), ['status' => 'em_revisao'])
            ->assertSessionMissing('erro');
        $this->assertSame('em_revisao', $minha->fresh()->status);

        // A de outra pessoa, não — e a recusa diz de quem é.
        $this->actingAs($membro)->post(route('tarefas.mover', $dela), ['status' => 'em_revisao'])
            ->assertSessionHas('erro');

        $this->assertStringContainsString('Camila Reis', session('erro'));
        $this->assertStringContainsString('triagem', session('erro'));
        $this->assertSame('em_desenvolvimento', $dela->fresh()->status);
    }

    /**
     * @spec:AC-204 A fila de triagem fica fora do alcance sem precisar de regra própria:
     * entrar em Aberta solta o responsável (AC-130), então nenhuma tarefa de lá está com
     * alguém — e quem não triaga não move o que não é dele.
     */
    public function test_a_fila_de_triagem_fica_fora_do_alcance_de_quem_nao_triaga(): void
    {
        $membro = User::factory()->membro()->create();
        $naFila = $this->criarTarefa(['status' => 'aberta', 'responsavel_id' => null]);

        $this->actingAs($membro)->post(route('tarefas.mover', $naFila), ['status' => 'backlog'])
            ->assertSessionHas('erro');

        $this->assertStringContainsString('ainda não tem responsável', session('erro'));
        $this->assertSame('aberta', $naFila->fresh()->status);
    }

    /**
     * @spec:AC-205 O quadro não oferece o que vai recusar: o card de outra pessoa não
     * arrasta, não traz o chevron e explica o porquê no `title`. Oferecer e recusar
     * depois é o vício que o quadro perdeu nas regras de fluxo.
     */
    public function test_o_card_alheio_nao_arrasta_e_explica_o_porque(): void
    {
        $membro = User::factory()->membro()->create();
        $camila = User::factory()->create(['name' => 'Camila Reis']);

        $minha = $this->criarTarefa(['responsavel_id' => $membro->id, 'titulo' => 'Minha']);
        $dela = $this->criarTarefa(['responsavel_id' => $camila->id, 'titulo' => 'Dela']);

        $html = $this->actingAs($membro)->get(route('tarefas.index'))->assertOk()->getContent();

        $numaLinha = preg_replace('/\s+/', ' ', $html);

        $this->assertStringContainsString('draggable="false" title="Esta tarefa está com Camila Reis.'
            .' Só quem faz triagem move o trabalho de outra pessoa." data-tarefa="'.$dela->id.'"', $numaLinha);

        // A minha continua arrastável, e só ela traz menu — um `x-data` de
        // menu na página inteira, que é o do card dele.
        $this->assertStringContainsString('draggable="true" data-tarefa="'.$minha->id.'"', $numaLinha);
        $this->assertSame(1, substr_count($html, 'x-data="{ transicoesDoCard:'));
    }

    /**
     * @spec:AC-206 Não se restringe o que é trabalho: quem não triaga comenta, bloqueia e
     * mexe no checklist de qualquer tarefa. Travar isso não impede ninguém de trabalhar
     * em algo não pedido — impede de REGISTRAR, e aí o quadro passa a mentir.
     */
    public function test_quem_nao_triaga_comenta_bloqueia_e_mexe_no_checklist_de_qualquer_tarefa(): void
    {
        $membro = User::factory()->membro()->create();
        $camila = User::factory()->create(['name' => 'Camila Reis']);
        $dela = $this->criarTarefa(['responsavel_id' => $camila->id, 'prioridade' => 'alta']);

        // Comentário viaja no salvar da tarefa.
        $this->actingAs($membro)->put(route('tarefas.update', $dela), [
            'titulo' => $dela->titulo,
            'comentario' => 'Achei o erro: o boleto vencido entra com valor zerado.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $dela->comentarios()->count());

        // Bloquear é informação, não controle: quem descobriu que travou avisa.
        $this->actingAs($membro)->post(route('tarefas.bloquear', $dela), [
            'motivo' => 'Esperando o financeiro liberar o acesso.',
        ])->assertSessionMissing('erro');

        $this->assertTrue($dela->fresh()->estaBloqueada());

        // Checklist idem.
        $this->actingAs($membro)->post(route('tarefas.itens.store', $dela), ['texto' => 'Conferir em homologação'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $dela->itens()->count());
    }

    /**
     * @spec:AC-207 Quem entra chega numa tela que abre. O destino do login era fixo — o
     * Centro de Controle —, e valia enquanto todo perfil o enxergava: para quem só tem o
     * quadro, a senha certa levava a um 403, que se lê como conta quebrada e não como
     * tela que não é sua.
     */
    public function test_o_login_leva_a_primeira_tela_que_a_conta_abre(): void
    {
        $membro = User::factory()->membro()->create(['password' => bcrypt('segredo-do-time')]);
        $admin = User::factory()->create(['password' => bcrypt('segredo-do-time')]);

        $this->post(route('login'), ['email' => $membro->email, 'password' => 'segredo-do-time'])
            ->assertRedirect(route('tarefas.index', absolute: false));

        $this->post(route('logout'));

        // Para quem alcança o Centro de Controle, nada muda: ele é a casa.
        $this->post(route('login'), ['email' => $admin->email, 'password' => 'segredo-do-time'])
            ->assertRedirect(route('centro-controle', absolute: false));
    }

    /**
     * @spec:AC-206 Quem triaga continua movendo o trabalho de qualquer um: a capacidade
     * existe para organizar, e organizar é justamente mexer no que está com os outros.
     */
    public function test_quem_triaga_move_o_trabalho_de_qualquer_um(): void
    {
        $admin = User::factory()->create();
        $camila = User::factory()->create(['name' => 'Camila Reis']);
        $dela = $this->criarTarefa(['responsavel_id' => $camila->id]);

        $this->assertTrue($admin->podeTriarTarefas());
        $this->assertNull($dela->motivoParaNaoMover($admin));

        $this->actingAs($admin)->post(route('tarefas.mover', $dela), ['status' => 'em_revisao'])
            ->assertSessionMissing('erro');

        $this->assertSame('em_revisao', $dela->fresh()->status);
    }
}
