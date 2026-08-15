<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Tarefa;
use App\Models\TarefaComentario;
use App\Models\TarefaEvento;
use App\Models\TarefaItem;
use App\Models\TarefaRelatorioTeste;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O modal de histórico completo da tarefa (US-082): clicar numa linha do
 * histórico abre a tarefa inteira — linha do tempo, relatórios, checklist,
 * anexos e conversa — sem sair da listagem. Tudo leitura: quem quer escrever
 * reabre a tarefa (AC-131).
 */
class ModalHistoricoDaTarefaTest extends TestCase
{
    use RefreshDatabase;

    private function tarefaEncerrada(array $atributos = []): Tarefa
    {
        return Tarefa::factory()->create(array_merge([
            'criado_por_id' => User::factory()->create()->id,
            'status' => 'concluida',
        ], $atributos));
    }

    private function abrirHistorico()
    {
        return $this->actingAs(User::factory()->create())
            ->get(route('tarefas.historico'))
            ->assertOk();
    }

    /**
     * @spec:AC-293 A linha da tabela abre o modal de histórico completo: o
     * clique em qualquer ponto dela dispara o modal da tarefa, e a linha se
     * anuncia clicável.
     */
    public function test_a_linha_abre_o_modal_de_historico(): void
    {
        $tarefa = $this->tarefaEncerrada(['titulo' => 'Encerrada com caminho']);

        $historico = $this->abrirHistorico();

        $historico->assertSee("open-modal', 'historico-tarefa-{$tarefa->id}", false);
        $historico->assertSee('cursor-pointer');
        // O modal em si existe na página, pronto para o evento.
        $historico->assertSee('historico-tarefa-'.$tarefa->id, false);
    }

    /**
     * @spec:AC-294 Os controles da linha continuam agindo sem abrir o modal:
     * o Reabrir segura o clique (`@click.stop`) e segue funcionando.
     */
    public function test_reabrir_continua_agindo_sem_abrir_o_modal(): void
    {
        $tarefa = $this->tarefaEncerrada(['titulo' => 'Volta para a bancada']);

        $this->abrirHistorico()->assertSee('@click.stop', false);

        // E reabrir continua reabrindo, como antes da linha virar alvo.
        $this->actingAs(User::factory()->create())->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_desenvolvimento',
            'de_status' => 'concluida',
        ])->assertSessionMissing('erro');

        $this->assertSame('em_desenvolvimento', $tarefa->fresh()->status);
    }

    /**
     * @spec:AC-295 A linha do tempo mostra cada movimento: etapa de destino,
     * quando entrou, quanto ficou e o motivo quando houve — na ordem em que
     * aconteceu, começando pela criação.
     */
    public function test_linha_do_tempo_mostra_cada_movimento(): void
    {
        $tarefa = $this->tarefaEncerrada(['status' => 'cancelada', 'titulo' => 'Cancelada com motivo']);

        TarefaEvento::create([
            'tarefa_id' => $tarefa->id,
            'de_status' => 'aberta',
            'para_status' => 'em_desenvolvimento',
            'entrou_em' => '2026-08-10 10:00',
            'saiu_em' => '2026-08-12 10:00',
            'duracao_segundos' => 2 * 86400,
        ]);
        TarefaEvento::create([
            'tarefa_id' => $tarefa->id,
            'de_status' => 'em_desenvolvimento',
            'para_status' => 'cancelada',
            'motivo' => 'Cliente desistiu do pedido',
            'entrou_em' => '2026-08-12 10:00',
        ]);

        $historico = $this->abrirHistorico();

        $historico->assertSeeInOrder(['Linha do tempo', 'Criada', 'Em andamento', 'Cancelada']);
        $historico->assertSee('10/08/2026 10:00');
        $historico->assertSee('ficou 2d');
        $historico->assertSee('Cliente desistiu do pedido');
    }

    /**
     * @spec:AC-296 Etapa aposentada aparece com o nome, nunca a chave crua: o
     * histórico antigo passou por etapas que já saíram do fluxo, e é para
     * isso que `ETAPAS_APOSENTADAS` existe.
     */
    public function test_etapa_aposentada_aparece_com_o_nome(): void
    {
        $tarefa = $this->tarefaEncerrada(['titulo' => 'Do tempo do Em testes']);

        TarefaEvento::create([
            'tarefa_id' => $tarefa->id,
            'de_status' => 'em_desenvolvimento',
            'para_status' => 'em_testes',
            'entrou_em' => '2026-07-01 09:00',
            'saiu_em' => '2026-07-02 09:00',
            'duracao_segundos' => 86400,
        ]);

        $historico = $this->abrirHistorico();

        $historico->assertSee('Em testes');
        $historico->assertDontSee('em_testes');
    }

    /**
     * @spec:AC-297 A conversa e os anexos vivem dentro do mesmo modal, e o
     * modal separado de comentários deixa de existir — um modal só por
     * tarefa.
     */
    public function test_conversa_e_anexos_no_mesmo_modal(): void
    {
        $tarefa = $this->tarefaEncerrada(['titulo' => 'Com conversa e prova']);
        $autor = User::factory()->create();

        TarefaComentario::create([
            'tarefa_id' => $tarefa->id,
            'autor_id' => $autor->id,
            'corpo' => 'O cliente confirmou por telefone que podia encerrar.',
        ]);
        $tarefa->anexos()->create([
            'autor_id' => $autor->id,
            'nome_original' => 'print-da-tela.png',
            'nome_arquivo' => 'abc123.png',
            'mime' => 'image/png',
            'caminho' => 'tarefas/abc123.png',
            'tamanho' => 1024,
        ]);

        $historico = $this->abrirHistorico();

        $historico->assertSee('Conversa');
        $historico->assertSee('O cliente confirmou por telefone que podia encerrar.');
        $historico->assertSee('Anexos');
        $historico->assertSee('print-da-tela.png');
        $historico->assertDontSee('comentarios-tarefa-');
    }

    /**
     * @spec:AC-298 O checklist aparece com o estado final dos itens: o que
     * ficou feito e o que não ficou, somente leitura.
     */
    public function test_checklist_com_estado_final(): void
    {
        $tarefa = $this->tarefaEncerrada(['titulo' => 'Com lista de conferência']);

        TarefaItem::create(['tarefa_id' => $tarefa->id, 'texto' => 'Migrar o banco', 'feito' => true, 'ordem' => 1]);
        TarefaItem::create(['tarefa_id' => $tarefa->id, 'texto' => 'Avisar a revenda', 'feito' => false, 'ordem' => 2]);

        $historico = $this->abrirHistorico();

        $historico->assertSee('Checklist');
        $historico->assertSee('1/2');
        $historico->assertSee('Migrar o banco');
        $historico->assertSee('Avisar a revenda');
        $historico->assertSee('line-through');
    }

    /**
     * @spec:AC-299 Os relatórios de teste aparecem com veredito, notas e
     * data.
     */
    public function test_relatorios_de_teste_com_veredito_e_notas(): void
    {
        $tarefa = $this->tarefaEncerrada(['titulo' => 'Validada no staging']);

        TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id,
            'aprovado' => true,
            'notas' => 'Validado no staging pela revenda.',
        ]);
        TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id,
            'aprovado' => false,
            'notas' => 'Primeira rodada reprovou o boleto.',
        ]);

        $historico = $this->abrirHistorico();

        $historico->assertSee('Relatórios de teste');
        $historico->assertSee('Aprovado');
        $historico->assertSee('Reprovado');
        $historico->assertSee('Validado no staging pela revenda.');
        $historico->assertSee('Primeira rodada reprovou o boleto.');
    }

    /**
     * @spec:AC-300 Seção sem conteúdo não aparece: a tarefa sem conversa,
     * anexo, checklist e relatório abre só com a linha do tempo — que sempre
     * existe.
     */
    public function test_secao_vazia_nao_aparece(): void
    {
        $this->tarefaEncerrada(['titulo' => 'Encerrada sem rastro extra']);

        $historico = $this->abrirHistorico();

        $historico->assertSee('Linha do tempo');
        $historico->assertDontSee('Relatórios de teste');
        $historico->assertDontSee('Checklist');
        $historico->assertDontSee('Conversa');
        $historico->assertDontSee('>Anexos<', false);
    }

    /**
     * @spec:AC-302 A linha do tempo mostra o autor quando ele existe: o
     * movimento novo vem assinado, o antigo — sem autor — aparece igual, sem
     * quebrar.
     */
    public function test_autor_aparece_quando_existe(): void
    {
        $quemMoveu = User::factory()->create(['name' => 'Rita Signatária']);
        $tarefa = $this->tarefaEncerrada(['titulo' => 'Meio assinada']);

        // O passado, sem autor: anterior à coluna.
        TarefaEvento::create([
            'tarefa_id' => $tarefa->id,
            'de_status' => 'aberta',
            'para_status' => 'em_desenvolvimento',
            'entrou_em' => '2026-08-01 09:00',
            'saiu_em' => '2026-08-14 09:00',
            'duracao_segundos' => 13 * 86400,
        ]);
        // O presente, assinado.
        TarefaEvento::create([
            'tarefa_id' => $tarefa->id,
            'user_id' => $quemMoveu->id,
            'de_status' => 'em_desenvolvimento',
            'para_status' => 'concluida',
            'entrou_em' => '2026-08-14 09:00',
        ]);

        $historico = $this->abrirHistorico();

        $historico->assertSee('por Rita Signatária');
        $historico->assertSeeInOrder(['Em andamento', 'Concluída', 'Rita Signatária']);
    }
}
