<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\AlfaMatrizServer;
use App\Mcp\Tools\AdicionarItens;
use App\Mcp\Tools\AtualizarItem;
use App\Mcp\Tools\BloquearTarefa;
use App\Mcp\Tools\DestravarTarefa;
use App\Mcp\Tools\EditarTarefa;
use App\Mcp\Tools\ExcluirTarefa;
use App\Mcp\Tools\RegistrarVeredito;
use App\Mcp\Tools\RemoverItem;
use App\Mcp\Tools\VerTarefa;
use App\Models\Notificacao;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O resto do quadro pela porta do agente: editar, travar, checklist, veredito
 * e excluir. Até 01/10/2026 ele criava e movia tarefa, e mais nada — não
 * corrigia um prazo, não marcava um item, não dizia que algo estava travado.
 *
 * Como nas outras suítes da pasta, o que se prova é que a porta aplica a regra
 * da TELA, com a frase da tela.
 */
class QuadroPeloAgenteTest extends TestCase
{
    use RefreshDatabase;

    public function test_editar_muda_so_o_que_foi_dito(): void
    {
        $admin = User::factory()->create();
        $tarefa = Tarefa::factory()->create(['titulo' => 'Titulo antigo', 'resumo' => 'Resumo que fica', 'prioridade' => 'media']);

        AlfaMatrizServer::actingAs($admin)
            ->tool(EditarTarefa::class, ['tarefa' => '#'.$tarefa->id, 'titulo' => 'Título novo', 'prazo' => '2026-10-09', 'prioridade' => 'alta'])
            ->assertOk()
            ->assertSee('atualizada')
            ->assertSee('Título novo')
            ->assertSee('prazo 09/10/2026');

        $tarefa->refresh();

        $this->assertSame('Título novo', $tarefa->titulo);
        $this->assertSame('alta', $tarefa->prioridade);
        $this->assertSame('2026-10-09', $tarefa->prazo->toDateString());
        // O que não veio fica como estava.
        $this->assertSame('Resumo que fica', $tarefa->resumo);
    }

    public function test_dar_e_tirar_o_responsavel_move_entre_aberta_e_backlog_e_avisa(): void
    {
        $admin = User::factory()->create();
        $ana = User::factory()->membro()->create(['name' => 'Ana Lima']);
        $tarefa = Tarefa::factory()->create(['status' => 'aberta']);

        AlfaMatrizServer::actingAs($admin)
            ->tool(EditarTarefa::class, ['tarefa' => '#'.$tarefa->id, 'responsavel' => 'Ana Lima'])
            ->assertOk()
            ->assertSee('Movida para Backlog');

        $this->assertSame('backlog', $tarefa->fresh()->status);
        $this->assertSame($ana->id, $tarefa->fresh()->responsavel_id);
        $this->assertTrue(Notificacao::where('destinatario_id', $ana->id)->where('tipo', 'direcionamento')->exists());

        AlfaMatrizServer::actingAs($admin)
            ->tool(EditarTarefa::class, ['tarefa' => '#'.$tarefa->id, 'responsavel' => 'nenhum'])
            ->assertOk()
            ->assertSee('Movida para Aberta');

        $this->assertNull($tarefa->fresh()->responsavel_id);
    }

    public function test_quem_nao_triaga_edita_o_texto_mas_nao_a_prioridade_e_e_avisado(): void
    {
        $membro = User::factory()->membro()->create();
        $outra = User::factory()->create(['name' => 'Outra Pessoa']);
        $tarefa = Tarefa::factory()->create(['status' => 'em_desenvolvimento', 'responsavel_id' => $outra->id, 'prioridade' => 'media']);

        AlfaMatrizServer::actingAs($membro)
            ->tool(EditarTarefa::class, ['tarefa' => '#'.$tarefa->id, 'resumo' => 'Contexto novo', 'prioridade' => 'critica', 'prazo' => '2026-10-09'])
            ->assertOk()
            ->assertSee('Ficou como estava: prioridade, prazo');

        $tarefa->refresh();

        $this->assertSame('Contexto novo', $tarefa->resumo);
        $this->assertSame('media', $tarefa->prioridade);
        $this->assertNull($tarefa->prazo);
    }

    public function test_editar_sem_dizer_o_que_muda_e_recusado(): void
    {
        $tarefa = Tarefa::factory()->create();

        AlfaMatrizServer::actingAs(User::factory()->create())
            ->tool(EditarTarefa::class, ['tarefa' => '#'.$tarefa->id])
            ->assertHasErrors()
            ->assertSee('Diga o que muda');
    }

    public function test_bloquear_exige_motivo_e_destravar_avisa_quando_nao_ha_o_que_destravar(): void
    {
        $admin = User::factory()->create();
        $tarefa = Tarefa::factory()->create(['status' => 'em_desenvolvimento', 'responsavel_id' => $admin->id]);

        AlfaMatrizServer::actingAs($admin)
            ->tool(DestravarTarefa::class, ['tarefa' => '#'.$tarefa->id])
            ->assertHasErrors()
            ->assertSee('não está bloqueada');

        AlfaMatrizServer::actingAs($admin)
            ->tool(BloquearTarefa::class, ['tarefa' => '#'.$tarefa->id, 'motivo' => 'Aguardando a chave da API do fabricante.'])
            ->assertOk()
            ->assertSee('bloqueada');

        $tarefa->refresh();
        $this->assertTrue($tarefa->estaBloqueada());
        $this->assertSame('Aguardando a chave da API do fabricante.', $tarefa->bloqueio_motivo);
        // Travar não tira da etapa.
        $this->assertSame('em_desenvolvimento', $tarefa->status);

        // Já travada: o motor recusa com a frase dele.
        AlfaMatrizServer::actingAs($admin)
            ->tool(BloquearTarefa::class, ['tarefa' => '#'.$tarefa->id, 'motivo' => 'De novo'])
            ->assertHasErrors()
            ->assertSee('já está bloqueada');

        AlfaMatrizServer::actingAs($admin)
            ->tool(DestravarTarefa::class, ['tarefa' => '#'.$tarefa->id])
            ->assertOk()
            ->assertSee('destravada');

        $this->assertFalse($tarefa->fresh()->estaBloqueada());
    }

    public function test_checklist_acrescenta_marca_reescreve_e_remove_pelo_numero_do_item(): void
    {
        $admin = User::factory()->create();
        $tarefa = Tarefa::factory()->create();

        AlfaMatrizServer::actingAs($admin)
            ->tool(AdicionarItens::class, ['tarefa' => '#'.$tarefa->id, 'itens' => ['Reproduzir', 'Corrigir']])
            ->assertOk()
            ->assertSee('2 item(ns)');

        [$primeiro, $segundo] = $tarefa->itens()->get()->all();

        // `ver_tarefa` mostra o número que as outras ferramentas recebem.
        AlfaMatrizServer::actingAs($admin)
            ->tool(VerTarefa::class, ['tarefa' => '#'.$tarefa->id])
            ->assertSee('- [ ] item '.$primeiro->id.': Reproduzir');

        AlfaMatrizServer::actingAs($admin)
            ->tool(AtualizarItem::class, ['item' => $primeiro->id, 'feito' => true])
            ->assertOk()
            ->assertSee('- [x] item '.$primeiro->id.': Reproduzir');

        AlfaMatrizServer::actingAs($admin)
            ->tool(AtualizarItem::class, ['item' => $segundo->id, 'texto' => 'Corrigir e cobrir com teste'])
            ->assertOk();

        $this->assertTrue($primeiro->fresh()->feito);
        $this->assertSame('Corrigir e cobrir com teste', $segundo->fresh()->texto);

        AlfaMatrizServer::actingAs($admin)
            ->tool(RemoverItem::class, ['item' => $primeiro->id])
            ->assertOk()
            ->assertSee('removido');

        $this->assertSame(['Corrigir e cobrir com teste'], $tarefa->itens()->pluck('texto')->all());

        AlfaMatrizServer::actingAs($admin)
            ->tool(AtualizarItem::class, ['item' => 9999, 'feito' => true])
            ->assertHasErrors()
            ->assertSee('Não há item 9999');
    }

    public function test_veredito_so_onde_ha_o_que_conferir_e_reprovar_pede_o_motivo(): void
    {
        $admin = User::factory()->create();
        $naBancada = Tarefa::factory()->create(['status' => 'em_desenvolvimento', 'tipo' => 'desenvolvimento']);
        $noStaging = Tarefa::factory()->create(['status' => 'em_staging', 'tipo' => 'desenvolvimento']);

        AlfaMatrizServer::actingAs($admin)
            ->tool(RegistrarVeredito::class, ['tarefa' => '#'.$naBancada->id, 'aprovado' => true])
            ->assertHasErrors()
            ->assertSee('Só a tarefa em Em staging ou Em produção');

        AlfaMatrizServer::actingAs($admin)
            ->tool(RegistrarVeredito::class, ['tarefa' => '#'.$noStaging->id, 'aprovado' => false])
            ->assertHasErrors()
            ->assertSee('É preciso dizer o que reprovou no teste.');

        AlfaMatrizServer::actingAs($admin)
            ->tool(RegistrarVeredito::class, ['tarefa' => '#'.$noStaging->id, 'aprovado' => true, 'notas' => 'Parcelou em 3x e gerou as três cobranças.'])
            ->assertOk()
            ->assertSee('Teste do staging')
            ->assertSee('aprovado');

        $relatorio = $noStaging->relatoriosTeste()->sole();

        $this->assertTrue((bool) $relatorio->aprovado);
        $this->assertSame($admin->id, $relatorio->user_id);
    }

    public function test_excluir_exige_o_titulo_exato_e_so_quem_triaga(): void
    {
        $admin = User::factory()->create();
        $membro = User::factory()->membro()->create();
        $tarefa = Tarefa::factory()->create(['titulo' => 'Tarefa criada por engano']);

        // O número certo com o título de OUTRA tarefa não apaga nada.
        AlfaMatrizServer::actingAs($admin)
            ->tool(ExcluirTarefa::class, ['tarefa' => '#'.$tarefa->id, 'titulo' => 'Outro título'])
            ->assertHasErrors()
            ->assertSee('O título não confere')
            ->assertSee('Nada foi apagado');

        // O perfil de membro não tem `excluir` em tarefas: nem chega à regra.
        AlfaMatrizServer::actingAs($membro)
            ->tool(ExcluirTarefa::class, ['tarefa' => '#'.$tarefa->id, 'titulo' => 'Tarefa criada por engano'])
            ->assertHasErrors()
            ->assertSee('não tem permissão');

        $this->assertNotNull(Tarefa::find($tarefa->id));

        AlfaMatrizServer::actingAs($admin)
            ->tool(ExcluirTarefa::class, ['tarefa' => '#'.$tarefa->id, 'titulo' => 'Tarefa criada por engano'])
            ->assertOk()
            ->assertSee('excluída do quadro');

        $this->assertNull(Tarefa::withTrashed()->find($tarefa->id));
    }

    public function test_mae_com_filha_aberta_nao_e_excluida(): void
    {
        $admin = User::factory()->create();
        $mae = Tarefa::factory()->create(['titulo' => 'Mãe', 'status' => 'em_desenvolvimento']);
        $filha = Tarefa::factory()->create(['status' => 'aberta']);
        $filha->forceFill(['tarefa_pai_id' => $mae->id])->save();

        AlfaMatrizServer::actingAs($admin)
            ->tool(ExcluirTarefa::class, ['tarefa' => '#'.$mae->id, 'titulo' => 'Mãe'])
            ->assertHasErrors();

        $this->assertNotNull(Tarefa::find($mae->id));
    }
}
