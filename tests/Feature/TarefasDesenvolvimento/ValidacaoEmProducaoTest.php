<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Notificacao;
use App\Models\Tarefa;
use App\Models\TarefaRelatorioTeste;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A conferência em produção — a etapa que faltava no fim da esteira.
 *
 * O time faz três testes: local (que não entra no quadro), staging e produção.
 * O fluxo cobria os dois primeiros e parava no terceiro: `pronta_producao` ia
 * direto para `concluida` pedindo só a versão, então subir a tag e entregar
 * eram o mesmo gesto. Quem esperava alguém validar no ar não tinha onde
 * registrar a espera — e quem valida no ar nem sempre é quem testou no
 * staging.
 *
 * `em_producao` responde as duas coisas ao mesmo tempo: mostra que a tarefa
 * está no ar sem estar conferida, e nomeia quem o quadro está esperando.
 */
class ValidacaoEmProducaoTest extends TestCase
{
    use RefreshDatabase;

    private FluxoTarefaService $fluxo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fluxo = new FluxoTarefaService;
    }

    private function criarTarefa(array $atributos = []): Tarefa
    {
        return Tarefa::create(array_merge([
            'titulo' => 'Relatório de inadimplência',
            'criado_por_id' => User::factory()->create()->id,
        ], $atributos));
    }

    /** Uma tarefa no ar, com a tag já registrada e o evento da passagem aberto. */
    private function noAr(array $atributos = []): Tarefa
    {
        $tarefa = $this->criarTarefa(array_merge(['status' => 'em_staging'], $atributos));

        // O portão da subida cobra o staging validado: é o que a tag carrega.
        TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id, 'aprovado' => true, 'notas' => 'Staging conferido.',
        ]);

        return $this->fluxo->mover($tarefa, 'em_producao', ['versao_producao' => 'v1.4.2']);
    }

    /**
     * A esteira ganhou um passo, e ele fica ENTRE a subida da tag e o
     * encerramento: subir deixou de ser o mesmo gesto que entregar.
     *
     * Do staging só se vai para o ar. A antiga fila do admin, que ficava entre
     * os dois, saiu do quadro — a espera pela tag acontece dentro do próprio
     * staging, como a espera pelo merge acontece dentro da revisão.
     */
    public function test_do_staging_so_se_vai_para_o_ar(): void
    {
        $tarefa = $this->criarTarefa(['status' => 'em_staging']);

        $this->assertSame(
            ['em_producao', 'em_desenvolvimento', 'cancelada'],
            FluxoTarefaService::transicoesDe($tarefa),
            'De Em staging o caminho para frente é o ar, não o encerramento.'
        );

        try {
            $this->fluxo->mover($tarefa, 'concluida', ['versao_producao' => 'v1.4.2']);
            $this->fail('Esperava recusa: a tag subir não é a tarefa ter sido conferida.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Transição inválida', $e->getMessage());
        }

        $this->assertSame('em_staging', $tarefa->fresh()->status);

        // E a fila da tag não é mais etapa: ela sobrevive como vocabulário do
        // histórico, e não como destino.
        $this->assertArrayNotHasKey('pronta_producao', Tarefa::STATUS);
        $this->assertArrayHasKey('pronta_producao', Tarefa::ETAPAS_APOSENTADAS);
        $this->assertSame('Pronta p/ produção', Tarefa::rotuloDaEtapa('pronta_producao'));
    }

    /**
     * Subir para o ar cobra o staging validado. O portão não sumiu com a fila
     * do admin — ele mudou de porta, e agora guarda a própria subida da tag.
     */
    public function test_subir_a_tag_exige_o_staging_validado(): void
    {
        $tarefa = $this->criarTarefa(['status' => 'em_staging']);

        try {
            $this->fluxo->mover($tarefa, 'em_producao', ['versao_producao' => 'v1.4.2']);
            $this->fail('Esperava recusa: ninguém validou o staging.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('validar o staging', $e->getMessage());
        }

        $this->assertSame('em_staging', $tarefa->fresh()->status);

        TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id, 'aprovado' => true, 'notas' => 'Staging conferido.',
        ]);

        $this->assertSame(
            'em_producao',
            $this->fluxo->mover($tarefa->fresh(), 'em_producao', ['versao_producao' => 'v1.4.2'])->status,
        );
    }

    /**
     * Reprovar no ar tem dois destinos porque são fatos diferentes: o defeito é
     * do código, ou a subida é que deu errado. Um destino só obrigaria a mentir
     * num dos dois casos.
     */
    public function test_do_ar_saem_quatro_caminhos(): void
    {
        $this->assertSame(
            ['concluida', 'em_staging', 'em_desenvolvimento', 'cancelada'],
            FluxoTarefaService::transicoesDe($this->noAr()),
        );
    }

    /**
     * O portão da entrega, espelhando o do staging um passo adiante: sem o
     * veredito de quem conferiu, ninguém encerra — nem quem faz triagem.
     */
    public function test_concluir_sem_veredito_e_recusado_ate_para_quem_triaga(): void
    {
        $admin = User::factory()->create();
        $this->assertTrue($admin->podeTriarTarefas());

        $tarefa = $this->noAr();

        $this->actingAs($admin)->post(route('tarefas.mover', $tarefa), [
            'status' => 'concluida', 'de_status' => 'em_producao',
        ])->assertSessionHas('erro');

        $this->assertStringContainsString('validar em produção', session('erro'));
        $this->assertSame('em_producao', $tarefa->fresh()->status);

        $this->actingAs($admin)->post(route('tarefas.testar', $tarefa->fresh()), ['aprovado' => '1'])
            ->assertSessionMissing('erro');

        $this->actingAs($admin)->post(route('tarefas.mover', $tarefa->fresh()), [
            'status' => 'concluida', 'de_status' => 'em_producao',
        ])->assertSessionMissing('erro');

        $this->assertSame('concluida', $tarefa->fresh()->status);
        $this->assertSame('v1.4.2', $tarefa->fresh()->versao_producao);
    }

    /**
     * O carimbo do staging NÃO vale como carimbo de produção.
     *
     * É a mesma fresta que `testeDestaPassagem` fechou para a tarefa reaberta,
     * agora entre dois portões do mesmo ciclo: o relatório se prende ao evento
     * da passagem, e a passagem pelo staging não é a passagem pelo ar. Sem esse
     * recorte, o teste que provou o staging encerraria a tarefa sozinho — e o
     * terceiro teste do time voltaria a não existir.
     */
    public function test_o_carimbo_do_staging_nao_encerra_a_tarefa(): void
    {
        $tarefa = $this->criarTarefa(['status' => 'em_staging']);
        $testador = User::factory()->membro()->create();

        $this->fluxo->registrarVeredito($tarefa, $testador, true, 'Staging conferido.');
        $noAr = $this->fluxo->mover($tarefa->fresh(), 'em_producao', ['versao_producao' => 'v1.4.2']);

        $this->assertNull($noAr->testeDestaPassagem(), 'O aprovado do staging ficou preso à passagem dele.');

        try {
            $this->fluxo->mover($noAr, 'concluida');
            $this->fail('Esperava recusa: o staging aprovado não prova a produção.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('validar em produção', $e->getMessage());
        }

        $this->assertSame('em_producao', $tarefa->fresh()->status);
        $this->assertDatabaseCount('tarefa_relatorios_teste', 1);
    }

    /**
     * Quem valida no ar registra o veredito sem poder mover o card — que é o
     * ponto: travar o registro não impediria a conferência, impediria de
     * REGISTRÁ-LA, e o quadro passaria a depender de quem move relatar o teste
     * dos outros.
     */
    public function test_quem_valida_no_ar_nao_precisa_poder_mover_o_card(): void
    {
        $dono = User::factory()->membro()->create();
        $validador = User::factory()->membro()->create(['name' => 'Camila Reis']);

        $tarefa = $this->noAr(['responsavel_id' => $dono->id]);

        // Ela nem move o card: a tarefa é de outra pessoa.
        $this->assertNotNull($tarefa->motivoParaNaoMover($validador));

        $this->actingAs($validador)->post(route('tarefas.testar', $tarefa), [
            'aprovado' => '0', 'notas' => 'Boleto sai sem o código de barras.',
        ])->assertSessionMissing('erro');

        $relatorio = $tarefa->fresh()->testeDestaPassagem();

        $this->assertNotNull($relatorio);
        $this->assertFalse($relatorio->aprovado);
        $this->assertSame($validador->id, $relatorio->user_id);
        $this->assertSame('em_producao', $tarefa->fresh()->status, 'Registrar não é mover.');
    }

    /**
     * Reprovar sem dizer o quê manda o dev abrir o ar e adivinhar — a mesma
     * regra do staging e do retorno de portão.
     */
    public function test_reprovar_no_ar_sem_notas_e_recusado(): void
    {
        $tarefa = $this->noAr();

        $this->actingAs(User::factory()->membro()->create())
            ->post(route('tarefas.testar', $tarefa), ['aprovado' => '0'])
            ->assertSessionHas('erro');

        $this->assertNull($tarefa->fresh()->testeDestaPassagem());
    }

    /**
     * O veredito da produção fala como produção: aprovar no ar libera o
     * encerramento e reprovar significa que o defeito está com o cliente
     * agora. Dizer "staging" nos dois faria o responsável ler a notícia mais
     * grave do ciclo como a rotina de sempre.
     */
    public function test_o_aviso_do_veredito_nomeia_o_ar(): void
    {
        $dono = User::factory()->create();
        $validador = User::factory()->membro()->create(['name' => 'Camila Reis']);

        $tarefa = $this->noAr(['responsavel_id' => $dono->id]);

        $this->actingAs($validador)->post(route('tarefas.testar', $tarefa), ['aprovado' => '1']);

        $aviso = Notificacao::where('destinatario_id', $dono->id)->where('tipo', 'teste_staging')->sole();

        $this->assertStringContainsString('aprovou a produção', $aviso->titulo);
        $this->assertStringContainsString('Validada no ar', $aviso->meta);
    }

    /**
     * Reprovar no ar devolvendo para a bancada é retorno como qualquer portão:
     * cobra o motivo e carimba de onde a tarefa voltou. O rótulo é próprio
     * porque reprovar na conferência e reabrir depois de entregue são fatos
     * diferentes — no primeiro a tarefa nunca chegou a ser dada por entregue.
     */
    public function test_reprovar_no_ar_devolve_para_a_bancada_com_motivo(): void
    {
        $tarefa = $this->noAr();

        try {
            $this->fluxo->mover($tarefa, 'em_desenvolvimento');
            $this->fail('Esperava recusa: devolver sem dizer o que apareceu.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('o que precisa ser corrigido', $e->getMessage());
        }

        $devolvida = $this->fluxo->mover($tarefa->fresh(), 'em_desenvolvimento', [
            'motivo' => 'Boleto sai sem o código de barras.',
        ]);

        $this->assertSame('em_producao', $devolvida->retorno_de);
        $this->assertSame('Reprovou em produção', $devolvida->rotuloDoRetorno());
        $this->assertSame('Boleto sai sem o código de barras.', $devolvida->retorno_motivo);
    }

    /**
     * O outro caminho de volta: a tag é que deu errado, não o código. O card
     * volta para o staging, que é de onde a tag sai — e quem for mexer nela
     * precisa saber o que apareceu tanto quanto o dev precisaria.
     *
     * Avançar para o staging (vindo da revisão) continua sem cobrar motivo: o
     * que faz disto reprovação é a ORIGEM, e não o destino.
     */
    public function test_voltar_para_o_staging_tambem_cobra_motivo(): void
    {
        $tarefa = $this->noAr();

        try {
            $this->fluxo->mover($tarefa, 'em_staging');
            $this->fail('Esperava recusa: o rollback sem dizer o que apareceu.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('o que precisa ser corrigido', $e->getMessage());
        }

        $voltou = $this->fluxo->mover($tarefa->fresh(), 'em_staging', [
            'motivo' => 'A tag subiu sem a migração; reverter e subir de novo.',
        ]);

        $this->assertSame('em_staging', $voltou->status);
        $this->assertSame('em_producao', $voltou->retorno_de);
        $this->assertSame('Reprovou em produção', $voltou->rotuloDoRetorno());

        // O avanço normal para a mesma etapa não é reprovação.
        $daRevisao = $this->criarTarefa(['status' => 'em_revisao']);
        $this->assertSame('em_staging', $this->fluxo->mover($daRevisao, 'em_staging')->status);
        $this->assertNull($daRevisao->fresh()->retorno_de);
    }

    /**
     * A volta para a fila avisa com o nome do lugar. "Voltou para correção"
     * mandaria o dev procurar um defeito que, no rollback, pode não existir.
     */
    public function test_o_aviso_do_rollback_nomeia_o_staging(): void
    {
        $dono = User::factory()->create();
        $admin = User::factory()->create();

        $tarefa = $this->noAr(['responsavel_id' => $dono->id]);

        $this->actingAs($admin);
        $this->fluxo->mover($tarefa, 'em_staging', ['motivo' => 'Faltou a migração na tag.']);

        $aviso = Notificacao::where('destinatario_id', $dono->id)->where('tipo', 'retorno')->sole();

        $this->assertStringContainsString('voltou para o staging', $aviso->titulo);
    }

    /**
     * A versão não sobrevive à saída do ar, pelos DOIS caminhos de volta.
     *
     * É a mesma fresta que a reabertura já fechava um passo adiante: guardada,
     * ela deixaria a próxima subida passar no portão apoiada na tag do ciclo
     * anterior — e a coluna anunciaria como no ar uma tag que foi revertida.
     */
    public function test_a_versao_nao_sobrevive_a_volta_do_ar(): void
    {
        $paraBancada = $this->noAr();
        $this->fluxo->mover($paraBancada, 'em_desenvolvimento', ['motivo' => 'Boleto quebrou no ar.']);
        $this->assertNull($paraBancada->fresh()->versao_producao);

        $voltouAoStaging = $this->noAr();
        $this->fluxo->mover($voltouAoStaging, 'em_staging', ['motivo' => 'A tag subiu sem a migração.']);
        $this->assertNull($voltouAoStaging->fresh()->versao_producao);

        // A passagem nova pelo staging pede validação nova — o aprovado do
        // ciclo anterior ficou preso ao evento que fechou.
        TarefaRelatorioTeste::create([
            'tarefa_id' => $voltouAoStaging->id, 'aprovado' => true, 'notas' => 'Retestado.',
        ]);

        // E a subida seguinte cobra a versão de novo, em vez de herdar a velha.
        try {
            $this->fluxo->mover($voltouAoStaging->fresh(), 'em_producao');
            $this->fail('Esperava recusa: a versão registrada era da tag revertida.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('versão que subiu', $e->getMessage());
        }

        $this->assertSame(
            'v1.4.3',
            $this->fluxo->mover($voltouAoStaging->fresh(), 'em_producao', ['versao_producao' => 'v1.4.3'])->versao_producao,
        );
    }

    /**
     * Encerrar tarefa de desenvolvimento é de quem faz triagem: sem essa
     * separação, o mesmo dev que subiu o código assinaria sozinho que ele
     * funciona. O quadro não OFERECE o destino a quem não pode usá-lo —
     * oferecer e recusar depois é o vício que ele perdeu.
     */
    public function test_membro_nao_encerra_tarefa_de_desenvolvimento(): void
    {
        $membro = User::factory()->membro()->create();
        $tarefa = $this->noAr(['responsavel_id' => $membro->id]);

        TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id, 'aprovado' => true, 'notas' => 'Conferido no ar.',
        ]);

        $this->assertNotContains('concluida', $tarefa->destinosPara($membro));

        $this->actingAs($membro)->post(route('tarefas.mover', $tarefa), [
            'status' => 'concluida', 'de_status' => 'em_producao',
        ])->assertSessionHas('erro');

        $this->assertStringContainsString('Só quem faz triagem encerra', session('erro'));
        $this->assertSame('em_producao', $tarefa->fresh()->status);

        // O resto do quadro continua dele: a trava é sobre encerrar, não sobre
        // mover — devolver para correção é exatamente o que ele deve poder fazer.
        $this->assertContains('em_desenvolvimento', $tarefa->destinosPara($membro));
    }

    /**
     * A trava é sobre a tarefa de desenvolvimento. A operacional não tem PR,
     * staging nem tag: exigir um admin para encerrar um telefonema tiraria de
     * quem executa o registro do próprio trabalho, sem nada a proteger.
     */
    public function test_o_membro_continua_encerrando_a_operacional(): void
    {
        $membro = User::factory()->membro()->create();

        $tarefa = $this->criarTarefa([
            'tipo' => 'operacional',
            'status' => 'em_desenvolvimento',
            'responsavel_id' => $membro->id,
        ]);

        $this->assertNull($tarefa->motivoParaNaoConcluir($membro));

        $this->actingAs($membro)->post(route('tarefas.mover', $tarefa), [
            'status' => 'concluida', 'de_status' => 'em_desenvolvimento',
        ])->assertSessionMissing('erro');

        $this->assertSame('concluida', $tarefa->fresh()->status);
    }

    /**
     * Errar o CAMINHO e errar a permissão são recusas diferentes, e o mapa
     * responde primeiro: ouvir "só quem faz triagem encerra" ao tentar concluir
     * do Backlog mandaria a pessoa pedir um acesso que não resolveria nada.
     */
    public function test_o_mapa_recusa_antes_da_permissao(): void
    {
        $membro = User::factory()->membro()->create();
        $tarefa = $this->criarTarefa(['status' => 'backlog', 'responsavel_id' => $membro->id]);

        $this->actingAs($membro)->post(route('tarefas.mover', $tarefa), [
            'status' => 'concluida', 'de_status' => 'backlog',
        ])->assertSessionHas('erro');

        $this->assertStringContainsString('Transição inválida', session('erro'));
    }

    /**
     * Em produção é portão de EXAME: quem chega fala com o examinador desta
     * passagem. Sem o apontamento a coluna volta a não responder quem o quadro
     * está esperando — que é a pergunta que ela existe para responder.
     */
    public function test_apontar_quem_valida_avisa_e_recomeca_a_conversa(): void
    {
        $admin = User::factory()->create();
        $dono = User::factory()->create();
        $pediu = User::factory()->create(['name' => 'Camila Reis']);

        $tarefa = $this->criarTarefa(['status' => 'em_staging', 'responsavel_id' => $dono->id]);
        $tarefa->forceFill(['rodadas' => 2, 'interlocutor_id' => $admin->id])->save();

        TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id, 'aprovado' => true, 'notas' => 'Staging conferido.',
        ]);

        $this->actingAs($admin);
        $noAr = $this->fluxo->mover($tarefa, 'em_producao', [
            'versao_producao' => 'v1.4.2',
            'interlocutor_id' => $pediu->id,
        ]);

        $this->assertSame($pediu->id, $noAr->interlocutor_id);
        $this->assertSame(0, $noAr->rodadas, 'Cada portão recomeça a conversa: o impasse era da etapa anterior.');

        $aviso = Notificacao::where('destinatario_id', $pediu->id)->where('tipo', 'apontamento')->sole();

        $this->assertStringContainsString('Em produção', $aviso->meta);
    }

    /**
     * A coluna nasce com a régua de uma FILA que espera terceiro: sem limite de
     * WIP — um alarme ali só ensinaria a ignorar o alarme — e com o portão dito
     * no cabeçalho, que é o que separa "no ar" de "no ar e conferido".
     */
    public function test_a_coluna_declara_o_portao_e_nao_tem_limite_de_wip(): void
    {
        $this->noAr();

        $etapas = $this->actingAs(User::factory()->create())
            ->get(route('tarefas.index'))->assertOk()->viewData('etapas');

        $noAr = collect($etapas)->firstWhere('chave', 'em_producao');

        $this->assertSame('Em produção', $noAr['label']);
        $this->assertSame(1, $noAr['quantidade']);
        $this->assertNull($noAr['limite'], 'Fila que espera terceiro não tem WIP.');
        $this->assertArrayNotHasKey('em_producao', Tarefa::LIMITE_DE_WIP);
        $this->assertSame('no ar · aguardando validação', Tarefa::PORTAO_DA_ETAPA['em_producao']);
    }

    /**
     * A guarda de concorrência (AC-208) vale na etapa nova como nas outras: é
     * fácil ganhar um endpoint novo sem ela, e este não é endpoint novo — mas a
     * coluna é, e a conferência precisa continuar valendo aqui.
     */
    public function test_movimento_concorrente_e_recusado_na_etapa_nova(): void
    {
        $admin = User::factory()->create();
        $tarefa = $this->noAr();

        TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id, 'aprovado' => true, 'notas' => 'Conferido no ar.',
        ]);

        // Alguém já devolveu enquanto a tela mostrava a tarefa no ar.
        $this->fluxo->mover($tarefa->fresh(), 'em_desenvolvimento', ['motivo' => 'Quebrou o boleto.']);

        $this->actingAs($admin)->post(route('tarefas.mover', $tarefa->fresh()), [
            'status' => 'concluida', 'de_status' => 'em_producao',
        ])->assertSessionHas('erro');

        $this->assertStringContainsString('Alguém já moveu esta tarefa', session('erro'));
        $this->assertSame('em_desenvolvimento', $tarefa->fresh()->status);
    }

    /**
     * A tarefa operacional não chega ao ar nem pelo movimento livre: os portões
     * examinam código, e um telefonema numa coluna chamada "no ar · quem pediu
     * valida" faria a coluna mentir.
     */
    public function test_a_operacional_nao_entra_no_ar(): void
    {
        $tarefa = $this->criarTarefa(['tipo' => 'operacional', 'status' => 'em_desenvolvimento']);

        $this->assertNotContains('em_producao', FluxoTarefaService::transicoesDe($tarefa));
        $this->assertNotContains('em_producao', FluxoTarefaService::transicoesLivres($tarefa));
    }
}
