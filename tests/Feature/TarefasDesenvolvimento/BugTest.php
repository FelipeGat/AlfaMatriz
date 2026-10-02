<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Mcp\Servers\AlfaMatrizServer;
use App\Mcp\Tools\CriarTarefa;
use App\Mcp\Tools\EditarTarefa;
use App\Mcp\Tools\Referencias;
use App\Mcp\Tools\VerTarefa;
use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O tipo Bug e o relato que vem com ele (tarefa #204).
 *
 * As #185 e #191 chegaram só com o sintoma — sem aluno, sem data, uma sem
 * academia. O Bug anda pelo fluxo do desenvolvimento, mas nasce com quem e
 * quando, pela tela e pelo agente, com a mesma frase de recusa.
 *
 * Nasceu como "Defeito" (chave `defeito`) e foi renomeado no mesmo dia, junto
 * com o segundo ajuste: o relato perdeu "o que esperava" e "o que aconteceu"
 * (o resumo vira "O que aconteceu" no Bug), o tipo passou a ser obrigatório ao
 * criar e a criação rápida do pé da coluna passou a abrir o formulário.
 */
class BugTest extends TestCase
{
    use RefreshDatabase;

    public function test_bug_anda_pelos_portoes_como_o_desenvolvimento(): void
    {
        $bug = Tarefa::factory()->create(['tipo' => 'bug', 'status' => 'em_desenvolvimento']);

        $this->assertTrue($bug->passaPelosPortoes());
        $this->assertSame(['em_revisao', 'backlog', 'cancelada'], FluxoTarefaService::transicoesDe($bug));

        // Sem atalho de Em andamento para Concluída, como o desenvolvimento.
        try {
            (new FluxoTarefaService)->mover($bug, 'concluida');
            $this->fail('Esperava recusa: bug não fecha sem passar pelos portões.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Transição inválida', $e->getMessage());
        }

        // E o portão do staging vale para ele: sem veredito aprovado, não sobe.
        $noStaging = Tarefa::factory()->create(['tipo' => 'bug', 'status' => 'em_staging']);

        try {
            (new FluxoTarefaService)->mover($noStaging, 'em_producao', ['versao_producao' => 'v1.2.3']);
            $this->fail('Esperava recusa: bug não sobe sem validar o staging.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('validar o staging', $e->getMessage());
        }

        // Encerrar segue sendo de quem faz triagem.
        $membro = User::factory()->membro()->create();
        $this->assertNotNull($noStaging->motivoParaNaoConcluir($membro));
    }

    public function test_o_tipo_se_chama_bug_e_defeito_nao_existe_mais(): void
    {
        $this->assertSame('Bug', Tarefa::TIPOS['bug']);
        $this->assertArrayNotHasKey('defeito', Tarefa::TIPOS);
        $this->assertContains('bug', Tarefa::TIPOS_COM_PORTOES);
        $this->assertNotContains('defeito', Tarefa::TIPOS_COM_PORTOES);
    }

    public function test_a_tela_exige_quem_e_quando_para_abrir_um_bug(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->post(route('tarefas.store'), [
            'titulo' => 'Treino não aparece no app',
            'tipo' => 'bug',
        ])->assertSessionHasErrors([
            'defeito_quem' => 'Bug precisa dizer quem foi afetado: o cliente, o aluno ou a academia.',
            'defeito_quando' => 'Bug precisa dizer quando aconteceu: o dia e a hora.',
        ]);

        $this->assertSame(0, Tarefa::count());

        $this->actingAs($usuario)->post(route('tarefas.store'), [
            'titulo' => 'Treino não aparece no app',
            'resumo' => 'A lista de treinos de quarta veio vazia; deveria mostrar o treino A.',
            'tipo' => 'bug',
            'defeito_quem' => '  Aluno João Silva · Academia Centro  ',
            'defeito_quando' => '2026-09-30T14:20',
        ])->assertSessionHasNoErrors();

        $tarefa = Tarefa::sole();

        $this->assertSame('bug', $tarefa->tipo);
        $this->assertSame('Aluno João Silva · Academia Centro', $tarefa->defeito_quem);
        $this->assertSame('2026-09-30 14:20', $tarefa->defeito_quando->format('Y-m-d H:i'));
    }

    /**
     * "O que esperava" e "o que aconteceu" saíram (segundo ajuste da #204).
     * Formulário antigo em cache durante a troca ainda os manda — a tarefa
     * nasce, e eles não são gravados.
     */
    public function test_esperado_e_ocorrido_nao_sao_mais_gravados(): void
    {
        $this->actingAs(User::factory()->create())->post(route('tarefas.store'), [
            'titulo' => 'Check-in recusado',
            'tipo' => 'bug',
            'defeito_quem' => 'Academia Norte',
            'defeito_quando' => '2026-09-30T08:05',
            'defeito_esperado' => 'Entrar',
            'defeito_ocorrido' => 'Recusou',
        ])->assertSessionHasNoErrors();

        $linha = DB::table('tarefas')->sole();

        $this->assertNull($linha->defeito_esperado);
        $this->assertNull($linha->defeito_ocorrido);
        $this->assertNotContains('defeito_esperado', (new Tarefa)->getFillable());
        $this->assertNotContains('defeito_ocorrido', (new Tarefa)->getFillable());
    }

    public function test_o_clique_duplo_nao_cria_dois_bugs(): void
    {
        $usuario = User::factory()->create();
        $envio = [
            'titulo' => 'Check-in recusado',
            'tipo' => 'bug',
            'defeito_quem' => 'Academia Norte',
            'defeito_quando' => '2026-09-30T08:05',
        ];

        $this->actingAs($usuario)->post(route('tarefas.store'), $envio);
        $this->actingAs($usuario)->post(route('tarefas.store'), $envio);

        $this->assertSame(1, Tarefa::count());
    }

    public function test_outros_tipos_nao_gravam_o_relato_escondido(): void
    {
        $usuario = User::factory()->create();

        // O formulário carrega os campos escondidos: quem preencheu e voltou o
        // tipo para Desenvolvimento não pediu para gravar relato.
        $this->actingAs($usuario)->post(route('tarefas.store'), [
            'titulo' => 'Tela nova de planos',
            'tipo' => 'desenvolvimento',
            'defeito_quem' => 'Ninguém',
            'defeito_quando' => '2026-09-30T08:05',
        ])->assertSessionHasNoErrors();

        $tarefa = Tarefa::sole();

        $this->assertNull($tarefa->defeito_quem);
        $this->assertNull($tarefa->defeito_quando);
    }

    public function test_virar_bug_na_edicao_tambem_exige_o_relato(): void
    {
        $usuario = User::factory()->create();
        $tarefa = Tarefa::factory()->create(['tipo' => 'desenvolvimento', 'titulo' => 'Pagamento duplicado']);

        $this->actingAs($usuario)->put(route('tarefas.update', $tarefa), [
            'titulo' => 'Pagamento duplicado',
            'tipo' => 'bug',
        ])->assertSessionHasErrors(['defeito_quem', 'defeito_quando']);

        $this->assertSame('desenvolvimento', $tarefa->fresh()->tipo);
    }

    public function test_criar_sem_tipo_pela_tela_e_recusado(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->post(route('tarefas.store'), ['titulo' => 'Sem tipo'])
            ->assertSessionHasErrors(['tipo' => 'Escolha o tipo da tarefa: Desenvolvimento, Bug ou Operacional.']);

        // E pelo envio parcial do quadro, que é como a tela de fato manda.
        $this->actingAs($usuario)->postJson(route('tarefas.store'), ['titulo' => 'Sem tipo', 'status' => 'aberta'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tipo']);

        $this->assertSame(0, Tarefa::count());
    }

    public function test_o_formulario_traz_o_modelo_e_o_card_se_anuncia(): void
    {
        $usuario = User::factory()->create();
        $bug = Tarefa::factory()->create([
            'tipo' => 'bug', 'status' => 'backlog', 'responsavel_id' => User::factory(),
            'resumo' => 'Lista vazia',
            'defeito_quem' => 'Aluno Pedro', 'defeito_quando' => '2026-09-29 19:40:00',
        ]);
        $desenvolvimento = Tarefa::factory()->create([
            'tipo' => 'desenvolvimento', 'status' => 'backlog', 'responsavel_id' => User::factory(),
        ]);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // O modelo está no "nova tarefa", com quem, quando e o lembrete do print
        // — e sem os dois campos que saíram.
        $this->assertStringContainsString('data-relato-bug', $html);
        $this->assertStringContainsString('Relato do bug', $html);
        foreach (['defeito_quem', 'defeito_quando'] as $campo) {
            $this->assertStringContainsString('name="'.$campo.'"', $html);
        }
        foreach (['defeito_esperado', 'defeito_ocorrido', 'O que esperava'] as $saiu) {
            $this->assertStringNotContainsString($saiu, $html);
        }
        $this->assertStringContainsString('Print da tela', $html);
        $this->assertStringContainsString('<option value="bug"', $html);
        $this->assertStringNotContainsString('<option value="defeito"', $html);

        $this->assertStringContainsString('>Bug</span>', $this->trechoDoCard($html, $bug->id));
        $this->assertStringNotContainsString('>Bug</span>', $this->trechoDoCard($html, $desenvolvimento->id));

        // A edição devolve o relato gravado nos campos, com o tipo atual
        // marcado — e sem a opção vazia, que é só da criação.
        $modal = $this->actingAs($usuario)->get(route('tarefas.modal', $bug))
            ->assertOk()
            ->assertSee('value="Aluno Pedro"', false)
            ->assertSee('value="2026-09-29T19:40"', false)
            ->getContent();

        $this->assertMatchesRegularExpression('/<option value="bug"\s+selected/', $modal);
        $this->assertStringNotContainsString('escolha o tipo', $modal);
    }

    /**
     * Na criação o select começa vazio e é obrigatório; o servidor também
     * recusa (ver o teste acima). Na edição, mostra o tipo gravado.
     */
    public function test_nova_tarefa_comeca_sem_tipo_e_o_exige(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('tarefas.index'))->assertOk()->getContent();

        $select = $this->trechoEntre($html, 'id="tipo-nova"', '</select>');

        $this->assertStringContainsString('required', $select);
        $this->assertMatchesRegularExpression('/<option value=""\s+selected\s*>— escolha o tipo —<\/option>/', $select);
        $this->assertDoesNotMatchRegularExpression('/<option value="(desenvolvimento|bug|operacional)"\s+selected/', $select);

        // O estado do Alpine nasce vazio também, senão o relato e o rótulo do
        // resumo reagiriam a um tipo que o select não mostra.
        $this->assertStringContainsString("tipo: ''", $html);
    }

    /**
     * A subtarefa é a exceção decidida: abre com o tipo da mãe já escolhido —
     * visível e trocável —, porque ela nasce de dentro da mãe e o tipo dela é
     * o palpite certo quase sempre.
     */
    public function test_a_subtarefa_abre_com_o_tipo_da_mae(): void
    {
        $mae = Tarefa::factory()->create(['tipo' => 'operacional']);

        $html = $this->actingAs(User::factory()->create())
            ->get(route('tarefas.subtarefas.form', $mae))->assertOk()->getContent();

        $select = $this->trechoEntre($html, 'id="tipo-nova-de-'.$mae->id.'"', '</select>');

        $this->assertMatchesRegularExpression('/<option value="operacional"\s+selected/', $select);
        $this->assertStringContainsString('— escolha o tipo —', $select, 'A opção vazia continua lá: a herança é palpite.');
        $this->assertDoesNotMatchRegularExpression('/<option value=""\s+selected/', $select);
    }

    /**
     * No Bug, o resumo é "O que aconteceu" — o rótulo e o exemplo trocam com
     * o select, pelo Alpine, e já vêm certos na primeira pintura da edição.
     */
    public function test_o_resumo_vira_o_que_aconteceu_no_bug(): void
    {
        $usuario = User::factory()->create();
        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        $rotulo = $this->trechoEntre($html, 'for="resumo-nova"', '</label>');
        $this->assertStringContainsString('x-text="tipo === \'bug\' ? \'O que aconteceu\' : \'Resumo\'"', $rotulo);
        $this->assertStringContainsString('>Resumo', $rotulo, 'Na criação, antes de escolher, o rótulo é o de sempre.');

        $campo = $this->trechoEntre($html, 'id="resumo-nova"', '</textarea>');
        $this->assertStringContainsString(':placeholder="tipo === \'bug\' ?', $campo);
        $this->assertStringContainsString('Descreva o que aconteceu, o que deveria ter acontecido e a mensagem de erro, se houve', $campo);

        $bug = Tarefa::factory()->create(['tipo' => 'bug', 'defeito_quem' => 'Ana', 'defeito_quando' => now()]);
        $dev = Tarefa::factory()->create(['tipo' => 'desenvolvimento']);

        $modalBug = $this->actingAs($usuario)->get(route('tarefas.modal', $bug))->getContent();
        $this->assertStringContainsString('>O que aconteceu</label>', $this->trechoEntre($modalBug, 'for="resumo-'.$bug->id.'"', '</label>').'</label>');
        $this->assertStringContainsString('placeholder="Descreva o que aconteceu', $this->trechoEntre($modalBug, 'id="resumo-'.$bug->id.'"', '</textarea>'));

        $modalDev = $this->actingAs($usuario)->get(route('tarefas.modal', $dev))->getContent();
        $this->assertStringContainsString('>Resumo</label>', $this->trechoEntre($modalDev, 'for="resumo-'.$dev->id.'"', '</label>').'</label>');
    }

    /**
     * A criação rápida não cria mais: o Enter abre o formulário completo com o
     * título e a coluna. Daqui só dá para conferir o HTML — o campo não tem
     * mais `action`, o envio é interceptado e chama o modal de nova tarefa, e
     * o formulário completo tem por onde receber a coluna.
     */
    public function test_a_criacao_rapida_abre_o_formulario_completo(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('tarefas.index'))->assertOk()->getContent();

        $rapida = $this->trechoEntre($html, 'data-criacao-rapida-form', '</form>');

        $this->assertStringNotContainsString('action=', $rapida, 'O campo de uma linha não posta mais nada.');
        $this->assertStringContainsString('@submit.prevent', $rapida);
        $this->assertStringContainsString("\$dispatch('open-modal', 'nova-tarefa')", $rapida);
        $this->assertStringContainsString("\$dispatch('nova-tarefa-rapida', { titulo, etapa: 'aberta' })", $rapida);
        $this->assertStringContainsString('Enter abre o formulário', $rapida);
        $this->assertStringContainsString("etapa: 'backlog'", $html, 'A do Backlog leva a coluna dela.');

        // O formulário completo ouve o pedido e manda a coluna no `status`.
        $form = $this->trechoEntre($html, 'action="'.route('tarefas.store').'"', '</form>');
        $this->assertStringContainsString('x-on:nova-tarefa-rapida.window="comTitulo($event.detail)"', $form);
        $this->assertStringContainsString('<input type="hidden" name="status" :value="etapa">', $form);

        // E a coluna ainda vale do lado do servidor, agora junto com o tipo.
        $this->post(route('tarefas.store'), ['titulo' => 'Do pé do Backlog', 'tipo' => 'operacional', 'status' => 'backlog'])
            ->assertSessionHasNoErrors();
        $this->assertSame('backlog', Tarefa::sole()->status);
    }

    public function test_o_agente_abre_bug_so_com_quem_e_quando(): void
    {
        $admin = User::factory()->create();
        Sistema::factory()->create(['nome' => 'AlfaGym']);

        AlfaMatrizServer::actingAs($admin)
            ->tool(CriarTarefa::class, ['titulo' => 'Treino sumiu', 'tipo' => 'bug', 'sistema' => 'AlfaGym'])
            ->assertHasErrors()
            ->assertSee('Bug precisa dizer quem foi afetado');

        $this->assertSame(0, Tarefa::count());

        // Relato sem o tipo é engano de quem ditou, e não um relato a descartar.
        AlfaMatrizServer::actingAs($admin)
            ->tool(CriarTarefa::class, ['titulo' => 'Treino sumiu', 'tipo' => 'desenvolvimento', 'quem' => 'Aluno João'])
            ->assertHasErrors()
            ->assertSee('Passe tipo "bug"');

        AlfaMatrizServer::actingAs($admin)
            ->tool(CriarTarefa::class, [
                'titulo' => 'Treino sumiu', 'tipo' => 'bug', 'sistema' => 'AlfaGym',
                'resumo' => 'Lista de treinos vazia',
                'quem' => 'Aluno João · Academia Centro', 'quando' => '2026-09-30 14:20',
            ])
            ->assertOk()
            ->assertSee('criada');

        $tarefa = Tarefa::sole();
        $this->assertSame('bug', $tarefa->tipo);
        $this->assertSame('Aluno João · Academia Centro', $tarefa->defeito_quem);
        $this->assertSame('2026-09-30 14:20', $tarefa->defeito_quando->format('Y-m-d H:i'));

        AlfaMatrizServer::actingAs($admin)
            ->tool(VerTarefa::class, ['tarefa' => '#'.$tarefa->id])
            ->assertOk()
            ->assertSee('Tipo: Bug')
            ->assertSee('O que aconteceu: Lista de treinos vazia')
            ->assertSee('Relato do bug')
            ->assertSee('Quem: Aluno João · Academia Centro')
            ->assertSee('Quando: 30/09/2026 14:20')
            ->assertDontSee('Esperado:')
            ->assertDontSee('Ocorrido:');
    }

    public function test_criar_sem_tipo_pelo_agente_e_recusado_com_os_tipos(): void
    {
        $admin = User::factory()->create();

        AlfaMatrizServer::actingAs($admin)
            ->tool(CriarTarefa::class, ['titulo' => 'Sem tipo'])
            ->assertHasErrors()
            ->assertSee('Escolha o tipo da tarefa: Desenvolvimento, Bug ou Operacional.');

        $this->assertSame(0, Tarefa::count());
    }

    public function test_o_esquema_do_criar_tarefa_exige_o_tipo_e_nao_tem_esperado_nem_ocorrido(): void
    {
        $esquema = json_encode((new CriarTarefa)->toArray());

        $this->assertMatchesRegularExpression('/"required":\[[^\]]*"tipo"/', $esquema);
        $this->assertStringNotContainsString('"esperado"', $esquema);
        $this->assertStringNotContainsString('"ocorrido"', $esquema);
        $this->assertStringNotContainsString('defeito', $esquema);
        $this->assertStringContainsString('\"bug\"', $esquema);

        $edicao = json_encode((new EditarTarefa)->toArray());
        $this->assertStringNotContainsString('"esperado"', $edicao);
        $this->assertStringNotContainsString('defeito', $edicao);
    }

    public function test_o_agente_edita_o_relato_e_nao_vira_bug_sem_ele(): void
    {
        $admin = User::factory()->create();
        $tarefa = Tarefa::factory()->create(['tipo' => 'desenvolvimento']);

        AlfaMatrizServer::actingAs($admin)
            ->tool(EditarTarefa::class, ['tarefa' => '#'.$tarefa->id, 'tipo' => 'bug'])
            ->assertHasErrors()
            ->assertSee('Bug precisa dizer quando aconteceu');

        $this->assertSame('desenvolvimento', $tarefa->fresh()->tipo);

        AlfaMatrizServer::actingAs($admin)
            ->tool(EditarTarefa::class, [
                'tarefa' => '#'.$tarefa->id, 'tipo' => 'bug',
                'quem' => 'Academia Sul', 'quando' => '2026-09-28 10:00',
            ])
            ->assertOk();

        // Parcial: o que já está gravado vale, e só o que veio muda.
        AlfaMatrizServer::actingAs($admin)
            ->tool(EditarTarefa::class, ['tarefa' => '#'.$tarefa->id, 'quem' => 'Academia Sul · aluna Bia'])
            ->assertOk();

        $tarefa->refresh();
        $this->assertSame('bug', $tarefa->tipo);
        $this->assertSame('Academia Sul · aluna Bia', $tarefa->defeito_quem);
        $this->assertSame('2026-09-28 10:00', $tarefa->defeito_quando->format('Y-m-d H:i'));
    }

    public function test_referencias_explica_o_tipo_bug(): void
    {
        AlfaMatrizServer::actingAs(User::factory()->create())
            ->tool(Referencias::class, [])
            ->assertOk()
            ->assertSee('bug = Bug')
            ->assertSee('O tipo é obrigatório ao criar')
            ->assertSee('Bug segue o fluxo do desenvolvimento e exige o relato')
            ->assertDontSee('defeito');
    }

    public function test_os_relatorios_contam_bugs_por_sistema(): void
    {
        $gym = Sistema::factory()->create(['nome' => 'AlfaGym']);
        $control = Sistema::factory()->create(['nome' => 'AlfaControl']);

        $original = Tarefa::factory()->create(['tipo' => 'bug', 'sistema_id' => $gym->id]);
        Tarefa::factory()->create(['tipo' => 'bug', 'sistema_id' => $gym->id]);
        Tarefa::factory()->create(['tipo' => 'bug', 'sistema_id' => $control->id]);
        // Fora da conta: não é bug, o bug de outro mês e o mesmo bug pedido de
        // novo (#205) — a reclamação já contou na original.
        Tarefa::factory()->create(['tipo' => 'desenvolvimento', 'sistema_id' => $control->id]);
        Tarefa::factory()->create(['tipo' => 'bug', 'sistema_id' => $control->id])
            ->forceFill(['created_at' => now()->subMonths(2)])->save();
        Tarefa::factory()->create(['tipo' => 'bug', 'sistema_id' => $gym->id, 'status' => 'cancelada'])
            ->forceFill(['duplicada_de_id' => $original->id])->save();

        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('relatorios.index', ['secao' => 'desenvolvimento']))
            ->assertOk()
            ->assertSee('Bugs por sistema')
            ->assertDontSee('Defeitos por sistema');

        $ranking = collect($resposta->viewData('rankingBugs')['itens'])->pluck('valor', 'nome')->all();

        $this->assertSame(['AlfaGym' => 2.0, 'AlfaControl' => 1.0], $ranking);
    }

    /**
     * A migração converte as tarefas que nasceram como `defeito` — a chave
     * esteve no ar em produção (v2026.10.01.2) — e o `down` desfaz.
     */
    public function test_a_migracao_converte_defeito_em_bug_e_volta(): void
    {
        $antiga = Tarefa::factory()->create(['tipo' => 'desenvolvimento']);
        $outra = Tarefa::factory()->create(['tipo' => 'operacional']);
        // Pelo banco, e não pelo modelo: é a linha como a versão anterior a gravou.
        DB::table('tarefas')->where('id', $antiga->id)->update(['tipo' => 'defeito']);

        $migracao = require base_path('database/migrations/2026_10_02_090000_tipo_defeito_vira_bug.php');

        $migracao->up();
        $this->assertSame('bug', $antiga->fresh()->tipo);
        $this->assertSame('operacional', $outra->fresh()->tipo);
        $this->assertSame(0, DB::table('tarefas')->where('tipo', 'defeito')->count());

        $migracao->down();
        $this->assertSame('defeito', DB::table('tarefas')->where('id', $antiga->id)->value('tipo'));
        $this->assertSame('operacional', $outra->fresh()->tipo);
    }

    private function trechoDoCard(string $html, int $tarefaId): string
    {
        $inicio = strpos($html, 'data-tarefa="'.$tarefaId.'"');
        $this->assertNotFalse($inicio, "A tarefa {$tarefaId} não apareceu no quadro.");

        return substr($html, $inicio, strpos($html, '</article>', $inicio) - $inicio);
    }

    private function trechoEntre(string $html, string $inicio, string $fim): string
    {
        $comeco = strpos($html, $inicio);
        $this->assertNotFalse($comeco, "Não achei {$inicio} no HTML.");

        return substr($html, $comeco, strpos($html, $fim, $comeco) - $comeco);
    }
}
