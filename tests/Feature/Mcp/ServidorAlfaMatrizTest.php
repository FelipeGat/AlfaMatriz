<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\AlfaMatrizServer;
use App\Mcp\Tools\AdicionarItens;
use App\Mcp\Tools\AtualizarItem;
use App\Mcp\Tools\BloquearTarefa;
use App\Mcp\Tools\ComentarTarefa;
use App\Mcp\Tools\ConversarNaTarefa;
use App\Mcp\Tools\CriarTarefa;
use App\Mcp\Tools\DesmarcarCompromisso;
use App\Mcp\Tools\DestravarTarefa;
use App\Mcp\Tools\EditarTarefa;
use App\Mcp\Tools\ExcluirTarefa;
use App\Mcp\Tools\ListarTarefas;
use App\Mcp\Tools\MarcarCompromisso;
use App\Mcp\Tools\MoverTarefa;
use App\Mcp\Tools\Referencias;
use App\Mcp\Tools\RegistrarVeredito;
use App\Mcp\Tools\RemarcarCompromisso;
use App\Mcp\Tools\RemoverItem;
use App\Mcp\Tools\VerAgenda;
use App\Mcp\Tools\VerAnexo;
use App\Mcp\Tools\VerCompromisso;
use App\Mcp\Tools\VerTarefa;
use App\Models\Compromisso;
use App\Models\Notificacao;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * O quadro e a agenda pela porta do agente.
 *
 * O que se prova aqui é que a porta nova aplica a MESMA regra da tela — quem
 * não triaga cai na fila, o movimento concorrente é recusado, a permissão
 * vale — e não que o quadro funciona, que é assunto dos testes do quadro.
 */
class ServidorAlfaMatrizTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_servidor_publica_todas_as_ferramentas(): void
    {
        AlfaMatrizServer::actingAs(User::factory()->create())
            ->tools()
            ->assertRegistered([
                Referencias::class, ListarTarefas::class, VerTarefa::class, VerAnexo::class, CriarTarefa::class,
                EditarTarefa::class, MoverTarefa::class, BloquearTarefa::class, DestravarTarefa::class,
                RegistrarVeredito::class, ConversarNaTarefa::class, ComentarTarefa::class, AdicionarItens::class,
                AtualizarItem::class, RemoverItem::class, ExcluirTarefa::class, VerAgenda::class,
                VerCompromisso::class, MarcarCompromisso::class, RemarcarCompromisso::class, DesmarcarCompromisso::class,
            ]);
    }

    public function test_quem_triaga_cria_com_dono_e_a_tarefa_nasce_no_backlog(): void
    {
        $admin = User::factory()->create(['name' => 'Rossini Santos']);
        $ana = User::factory()->membro()->create(['name' => 'Ana Lima']);
        $sistema = Sistema::factory()->create(['nome' => 'AlfaGym']);

        AlfaMatrizServer::actingAs($admin)
            ->tool(CriarTarefa::class, [
                'tipo' => 'desenvolvimento',
                'titulo' => 'Corrigir importação de alunos',
                'resumo' => 'O CSV com acento quebra.',
                'sistema' => 'alfagym',
                'responsavel' => 'Ana Lima',
                'prioridade' => 'alta',
                'prazo' => '2026-10-05',
                'itens' => ['Reproduzir', 'Corrigir', ''],
            ])
            ->assertOk()
            ->assertSee('criada em Backlog')
            ->assertSee('resp. Ana Lima');

        $tarefa = Tarefa::firstOrFail();

        $this->assertSame('backlog', $tarefa->status);
        $this->assertSame($ana->id, $tarefa->responsavel_id);
        $this->assertSame($sistema->id, $tarefa->sistema_id);
        $this->assertSame('alta', $tarefa->prioridade);
        $this->assertSame('2026-10-05', $tarefa->prazo->toDateString());
        $this->assertSame($admin->id, $tarefa->criado_por_id);
        // O item em branco não vira linha, como na tela.
        $this->assertSame(['Reproduzir', 'Corrigir'], $tarefa->itens->pluck('texto')->all());
        // Quem ganhou a tarefa fica sabendo — o mesmo sino da criação pela tela.
        $this->assertTrue(Notificacao::where('destinatario_id', $ana->id)->where('tipo', 'direcionamento')->exists());
    }

    public function test_quem_nao_triaga_cai_na_fila_e_e_avisado_do_que_ficou_para_a_triagem(): void
    {
        $membro = User::factory()->membro()->create();
        User::factory()->create(['name' => 'Outra Pessoa']);

        AlfaMatrizServer::actingAs($membro)
            ->tool(CriarTarefa::class, [
                'tipo' => 'desenvolvimento',
                'titulo' => 'Renovar certificado',
                'responsavel' => 'Outra Pessoa',
                'prioridade' => 'critica',
            ])
            ->assertOk()
            ->assertSee('criada em Aberta')
            ->assertSee('ficaram para a triagem');

        $tarefa = Tarefa::firstOrFail();

        $this->assertSame('aberta', $tarefa->status);
        $this->assertNull($tarefa->responsavel_id);
        $this->assertSame('nao_definida', $tarefa->prioridade);
    }

    public function test_nome_ambiguo_e_recusado_em_vez_de_escolhido(): void
    {
        $admin = User::factory()->create();
        User::factory()->create(['name' => 'Ana Lima']);
        User::factory()->create(['name' => 'Ana Souza']);

        AlfaMatrizServer::actingAs($admin)
            ->tool(CriarTarefa::class, ['tipo' => 'desenvolvimento', 'titulo' => 'Qualquer', 'responsavel' => 'Ana'])
            ->assertHasErrors()
            ->assertSee('mais de uma pessoa');

        $this->assertSame(0, Tarefa::count());
    }

    public function test_mover_exige_a_etapa_que_o_agente_viu(): void
    {
        $admin = User::factory()->create();
        $tarefa = Tarefa::factory()->create(['status' => 'em_desenvolvimento', 'responsavel_id' => $admin->id]);

        // Alguém já moveu enquanto o agente lia.
        $tarefa->update(['status' => 'em_revisao']);

        AlfaMatrizServer::actingAs($admin)
            ->tool(MoverTarefa::class, ['tarefa' => '#'.$tarefa->id, 'para' => 'backlog', 'de' => 'em_desenvolvimento'])
            ->assertHasErrors()
            ->assertSee('Alguém já moveu esta tarefa para Em revisão');

        $this->assertSame('em_revisao', $tarefa->fresh()->status);

        AlfaMatrizServer::actingAs($admin)
            ->tool(MoverTarefa::class, [
                'tarefa' => (string) $tarefa->id,
                'para' => 'em_desenvolvimento',
                'de' => 'em_revisao',
                'motivo' => 'Falta tratar o retorno vazio.',
            ])
            ->assertOk()
            ->assertSee('movida de Em revisão para Em andamento');

        $tarefa->refresh();
        $this->assertSame('em_desenvolvimento', $tarefa->status);
        $this->assertTrue($tarefa->temRetorno());
        $this->assertSame('em_desenvolvimento', $tarefa->eventos()->latest('id')->value('para_status'));
    }

    public function test_o_motor_do_fluxo_recusa_com_a_mesma_frase_da_tela(): void
    {
        $admin = User::factory()->create();
        $tarefa = Tarefa::factory()->create(['status' => 'em_desenvolvimento', 'responsavel_id' => $admin->id]);

        // Cancelar sem motivo: a exigência é do motor, e a ferramenta só a repete.
        AlfaMatrizServer::actingAs($admin)
            ->tool(MoverTarefa::class, ['tarefa' => '#'.$tarefa->id, 'para' => 'cancelada', 'de' => 'em_desenvolvimento'])
            ->assertHasErrors();

        $this->assertSame('em_desenvolvimento', $tarefa->fresh()->status);
    }

    public function test_membro_nao_move_tarefa_de_outra_pessoa(): void
    {
        $membro = User::factory()->membro()->create();
        $dona = User::factory()->create(['name' => 'Dona da Tarefa']);
        $tarefa = Tarefa::factory()->create(['status' => 'em_desenvolvimento', 'responsavel_id' => $dona->id]);

        AlfaMatrizServer::actingAs($membro)
            ->tool(MoverTarefa::class, ['tarefa' => '#'.$tarefa->id, 'para' => 'em_revisao', 'de' => 'em_desenvolvimento'])
            ->assertHasErrors()
            ->assertSee('Só quem faz triagem move o trabalho de outra pessoa');
    }

    public function test_perguntar_e_responder_seguem_a_vez_do_quadro(): void
    {
        $revisor = User::factory()->create(['name' => 'Revisor']);
        $dev = User::factory()->membro()->create(['name' => 'Dev']);
        $tarefa = Tarefa::factory()->create(['status' => 'em_revisao', 'responsavel_id' => $dev->id]);

        AlfaMatrizServer::actingAs($revisor)
            ->tool(ConversarNaTarefa::class, ['tarefa' => '#'.$tarefa->id, 'mensagem' => 'Por que o cache foi removido?'])
            ->assertOk()
            ->assertSee('Pergunta registrada')
            ->assertSee('para Dev');

        $this->assertTrue($tarefa->fresh()->esperaRespostaDe($dev));

        AlfaMatrizServer::actingAs($dev)
            ->tool(ConversarNaTarefa::class, ['tarefa' => '#'.$tarefa->id, 'mensagem' => 'Estava servindo dado velho.'])
            ->assertOk()
            ->assertSee('Resposta registrada');

        $this->assertFalse($tarefa->fresh()->temPergunta());
        $this->assertSame(2, $tarefa->comentarios()->count());
    }

    /**
     * O "para" vence o outro lado (T-323): na #194 a pergunta era para quem
     * abriu a tarefa e a ferramenta a mandou ao interlocutor, ignorando o nome.
     */
    public function test_o_para_vence_o_outro_lado_e_o_sino_avisa_quem_foi_escolhido(): void
    {
        $eu = User::factory()->create(['name' => 'Rossini Santos']);
        $interlocutor = User::factory()->create(['name' => 'Administrador Alfa']);
        $quemAbriu = User::factory()->membro()->create(['name' => 'Alexandre Blank']);
        $tarefa = Tarefa::factory()->create([
            'status' => 'em_desenvolvimento',
            'responsavel_id' => $eu->id,
            'interlocutor_id' => $interlocutor->id,
            'criado_por_id' => $quemAbriu->id,
        ]);

        AlfaMatrizServer::actingAs($eu)
            ->tool(ConversarNaTarefa::class, [
                'tarefa' => '#'.$tarefa->id,
                'mensagem' => 'Qual versão do AlfaSync está aí?',
                'para' => 'Alexandre Blank',
            ])
            ->assertOk()
            ->assertSee('para Alexandre Blank');

        $tarefa->refresh();
        $this->assertSame($quemAbriu->id, $tarefa->pergunta_para_id);
        $this->assertSame($quemAbriu->id, $tarefa->interlocutor_id);
        $this->assertTrue(Notificacao::where('destinatario_id', $quemAbriu->id)->where('tipo', 'pergunta')->exists());
        $this->assertFalse(Notificacao::where('destinatario_id', $interlocutor->id)->where('tipo', 'pergunta')->exists());

        // Sem "para", o outro lado de sempre: quem foi perguntado por último.
        $outra = Tarefa::factory()->create([
            'status' => 'em_desenvolvimento',
            'responsavel_id' => $eu->id,
            'interlocutor_id' => $interlocutor->id,
        ]);

        AlfaMatrizServer::actingAs($eu)
            ->tool(ConversarNaTarefa::class, ['tarefa' => '#'.$outra->id, 'mensagem' => 'Dúvida.'])
            ->assertOk()
            ->assertSee('para Administrador Alfa');
    }

    public function test_ver_tarefa_mostra_o_que_o_modal_mostra_e_os_destinos_da_pessoa(): void
    {
        $admin = User::factory()->create(['name' => 'Rossini']);
        $tarefa = Tarefa::factory()->create([
            'titulo' => 'Trocar o logotipo',
            'status' => 'em_desenvolvimento',
            'responsavel_id' => $admin->id,
            'criado_por_id' => $admin->id,
        ]);
        $tarefa->itens()->create(['texto' => 'Exportar o SVG', 'feito' => true]);
        $tarefa->itens()->create(['texto' => 'Trocar no layout']);
        $tarefa->comentarios()->create(['autor_id' => $admin->id, 'corpo' => 'Já tenho o arquivo.']);

        AlfaMatrizServer::actingAs($admin)
            ->tool(VerTarefa::class, ['tarefa' => '#'.$tarefa->id])
            ->assertOk()
            ->assertSee('Trocar o logotipo')
            ->assertSee(': Exportar o SVG')
            ->assertSee(': Trocar no layout')
            ->assertSee('Já tenho o arquivo.')
            ->assertSee('Você pode mover para')
            ->assertSee('em_revisao');
    }

    public function test_listar_filtra_por_situacao_e_responsavel(): void
    {
        $admin = User::factory()->create(['name' => 'Rossini']);
        $ana = User::factory()->membro()->create(['name' => 'Ana Lima']);
        Tarefa::factory()->create(['titulo' => 'Da Ana', 'status' => 'em_desenvolvimento', 'responsavel_id' => $ana->id]);
        Tarefa::factory()->create(['titulo' => 'Encerrada', 'status' => 'concluida', 'responsavel_id' => $ana->id]);
        Tarefa::factory()->create(['titulo' => 'Na fila', 'status' => 'aberta']);

        AlfaMatrizServer::actingAs($admin)
            ->tool(ListarTarefas::class, ['responsavel' => 'Ana Lima'])
            ->assertOk()
            ->assertSee('Da Ana')
            ->assertDontSee('Encerrada')
            ->assertDontSee('Na fila');

        AlfaMatrizServer::actingAs($admin)
            ->tool(ListarTarefas::class, ['situacao' => 'todas', 'texto' => 'Encerrada'])
            ->assertOk()
            ->assertSee('Encerrada');

        AlfaMatrizServer::actingAs($admin)
            ->tool(ListarTarefas::class, ['responsavel' => 'ninguém'])
            ->assertOk()
            ->assertSee('Na fila')
            ->assertDontSee('Da Ana');
    }

    public function test_comentar_nao_repete_no_mesmo_minuto(): void
    {
        $admin = User::factory()->create();
        $tarefa = Tarefa::factory()->create();

        AlfaMatrizServer::actingAs($admin)
            ->tool(ComentarTarefa::class, ['tarefa' => '#'.$tarefa->id, 'comentario' => 'Visto.'])
            ->assertOk()
            ->assertSee('Comentário publicado');

        AlfaMatrizServer::actingAs($admin)
            ->tool(ComentarTarefa::class, ['tarefa' => '#'.$tarefa->id, 'comentario' => 'Visto.'])
            ->assertOk()
            ->assertSee('não repeti');

        $this->assertSame(1, $tarefa->comentarios()->count());
    }

    public function test_marcar_compromisso_calcula_o_termino_inclui_quem_marca_e_acusa_conflito(): void
    {
        $admin = User::factory()->create(['name' => 'Rossini']);
        $ana = User::factory()->membro()->create(['name' => 'Ana Lima']);

        // A Ana já tem algo das 10h às 11h.
        Compromisso::factory()->em('2026-10-01', '10:00')->create(['criado_por_id' => $ana->id])
            ->sincronizarParticipantes([$ana->id]);

        AlfaMatrizServer::actingAs($admin)
            ->tool(MarcarCompromisso::class, [
                'titulo' => 'Alinhar o release',
                'data' => '2026-10-01',
                'hora' => '10:30',
                'duracao_horas' => 1.5,
                'participantes' => ['Ana Lima'],
                'categoria' => 'desenvolvimento',
            ])
            ->assertOk()
            ->assertSee('01/10/2026, 10:30–12:00')
            ->assertSee('Ana Lima')
            ->assertSee('Rossini')
            ->assertSee('Ana Lima já tem outro compromisso');

        $compromisso = Compromisso::where('titulo', 'Alinhar o release')->firstOrFail();

        $this->assertSame('12:00', Carbon::parse($compromisso->hora_fim)->format('H:i'));
        $this->assertSame('desenvolvimento', $compromisso->categoria);
        $this->assertEqualsCanonicalizing([$admin->id, $ana->id], $compromisso->participantes->pluck('id')->all());
        // O participante fica sabendo; quem marcou, não.
        $this->assertTrue(Notificacao::where('destinatario_id', $ana->id)->where('tipo', 'compromisso')->exists());
        $this->assertFalse(Notificacao::where('destinatario_id', $admin->id)->where('tipo', 'compromisso')->exists());
    }

    public function test_termino_antes_do_inicio_e_recusado_com_a_frase_da_tela(): void
    {
        AlfaMatrizServer::actingAs(User::factory()->create())
            ->tool(MarcarCompromisso::class, ['titulo' => 'X', 'data' => '2026-10-01', 'hora' => '10:00', 'hora_fim' => '09:00'])
            ->assertHasErrors()
            ->assertSee('O término precisa ser depois do início.');

        $this->assertSame(0, Compromisso::count());
    }

    public function test_ver_agenda_agrupa_prazos_e_compromissos_por_dia(): void
    {
        Carbon::setTestNow('2026-09-28 09:00:00');

        $admin = User::factory()->create(['name' => 'Rossini']);
        Tarefa::factory()->create(['titulo' => 'Entregar relatório', 'status' => 'em_desenvolvimento', 'responsavel_id' => $admin->id, 'prazo' => '2026-09-30']);
        Compromisso::factory()->em('2026-09-29', '14:00')->create(['titulo' => 'Reunião com o cliente', 'criado_por_id' => $admin->id])
            ->sincronizarParticipantes([$admin->id]);

        AlfaMatrizServer::actingAs($admin)
            ->tool(VerAgenda::class)
            ->assertOk()
            ->assertSee('Ter 29/09/2026')
            ->assertSee('Reunião com o cliente')
            ->assertSee('Qua 30/09/2026')
            ->assertSee('Tarefa #')
            ->assertSee('Entregar relatório');

        Carbon::setTestNow();
    }

    public function test_referencias_lista_pessoas_sistemas_e_vocabulario(): void
    {
        $admin = User::factory()->create(['name' => 'Rossini']);
        User::factory()->membro()->create(['name' => 'Ana Lima']);
        Sistema::factory()->create(['nome' => 'AlfaGym']);

        AlfaMatrizServer::actingAs($admin)
            ->tool(Referencias::class)
            ->assertOk()
            ->assertSee('Rossini (você) · faz triagem')
            ->assertSee('Ana Lima')
            ->assertSee('AlfaGym')
            ->assertSee('em_revisao = Em revisão');
    }

    public function test_sem_permissao_a_ferramenta_recusa(): void
    {
        $semPerfil = User::factory()->semPerfil()->create();

        AlfaMatrizServer::actingAs($semPerfil)
            ->tool(ListarTarefas::class)
            ->assertHasErrors()
            ->assertSee('não tem permissão');
    }

    /**
     * A identidade do processo passa pelas mesmas portas da tela: conta
     * desativada e escopo de revenda não entram.
     */
    public function test_a_identidade_do_processo_e_conferida(): void
    {
        $ativo = User::factory()->create(['email' => 'rossini@alfa.test']);
        $this->assertTrue(AlfaMatrizServer::usuarioDoProcesso('rossini@alfa.test')->is($ativo));

        foreach ([null, '', 'ninguem@alfa.test'] as $email) {
            try {
                AlfaMatrizServer::usuarioDoProcesso($email);
                $this->fail('Deveria recusar '.var_export($email, true));
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        User::factory()->desativado()->create(['email' => 'inativo@alfa.test']);
        $revenda = Revenda::create(['nome' => 'Alpha Rev', 'ativo' => true]);
        User::factory()->create(['email' => 'revenda@alfa.test', 'revenda_id' => $revenda->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('desativada');
        AlfaMatrizServer::usuarioDoProcesso('inativo@alfa.test');
    }

    public function test_conta_de_revenda_nao_comanda_o_agente(): void
    {
        $revenda = Revenda::create(['nome' => 'Alpha Rev', 'ativo' => true]);
        User::factory()->create(['email' => 'revenda@alfa.test', 'revenda_id' => $revenda->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('revenda');
        AlfaMatrizServer::usuarioDoProcesso('revenda@alfa.test');
    }
}
