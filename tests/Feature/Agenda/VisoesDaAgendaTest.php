<?php

namespace Tests\Feature\Agenda;

use App\Models\Compromisso;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\AgendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * As três visões, o filtro de pessoas, o drawer do dia e os conflitos.
 *
 * A visão se chama **Lista**, e não "Agenda": uma visão Agenda dentro da tela
 * Agenda faria a mesma palavra nomear duas coisas na mesma barra.
 */
class VisoesDaAgendaTest extends TestCase
{
    use RefreshDatabase;

    public function test_as_tres_visoes_abrem_e_a_terceira_se_chama_lista(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->get(route('agenda.index', ['visao' => 'semana']))->assertOk();
        $this->actingAs($usuario)->get(route('agenda.index', ['visao' => 'mes']))->assertOk();

        $this->actingAs($usuario)
            ->get(route('agenda.index', ['visao' => 'lista']))
            ->assertOk()
            ->assertSee('Lista')
            ->assertSee('Próximos '.AgendaService::DIAS_DA_LISTA.' dias');
    }

    /** O mês é sempre 42 células — seis semanas fixas, para a grade não saltar. */
    public function test_o_mes_tem_sempre_42_celulas(): void
    {
        $faixa = (new AgendaService)->faixaDoMes(Carbon::parse('2026-02-15'));

        $this->assertSame(42, (int) $faixa['de']->diffInDays($faixa['ate']) + 1);
        $this->assertSame(Carbon::SUNDAY, $faixa['de']->dayOfWeek);
    }

    /**
     * Atrasadas vêm num grupo próprio no topo da Lista.
     *
     * Espalhadas pelos dias em que venceram, ficariam acima da dobra em ordem
     * cronológica — que é onde ninguém olha depois de o prazo passar.
     */
    public function test_lista_agrupa_as_atrasadas_no_topo(): void
    {
        $usuario = User::factory()->create();

        Tarefa::factory()->create([
            'criado_por_id' => $usuario->id,
            'status' => 'em_desenvolvimento',
            'titulo' => 'Venceu semana passada',
            'prazo' => Carbon::today()->subWeek()->toDateString(),
        ]);

        $this->actingAs($usuario)
            ->get(route('agenda.index', ['visao' => 'lista']))
            ->assertOk()
            ->assertSee('Atrasadas · 1')
            ->assertSee('Venceu semana passada');
    }

    /** Compromisso que já passou NÃO é atraso: reunião de ontem não pede ação. */
    public function test_compromisso_passado_nao_conta_como_atrasado(): void
    {
        $usuario = User::factory()->create();

        Compromisso::factory()->em(Carbon::today()->subDays(3)->toDateString(), '09:00')->create([
            'criado_por_id' => $usuario->id,
            'titulo' => 'Reunião que já aconteceu',
        ]);

        $this->actingAs($usuario)
            ->get(route('agenda.index', ['visao' => 'lista']))
            ->assertOk()
            ->assertDontSee('Atrasadas');
    }

    /**
     * Filtro vazio quer dizer TODO MUNDO, não ninguém.
     *
     * A tela abre sem filtro, e a leitura errada a faria nascer em branco.
     */
    public function test_filtro_por_pessoa_recorta_sem_esvaziar_a_tela(): void
    {
        $ana = User::factory()->create(['name' => 'Ana Souza']);
        $beto = User::factory()->create(['name' => 'Beto Lima']);

        $hoje = Carbon::today()->toDateString();

        Tarefa::factory()->create([
            'criado_por_id' => $ana->id, 'responsavel_id' => $ana->id,
            'status' => 'em_desenvolvimento', 'titulo' => 'Trabalho da Ana', 'prazo' => $hoje,
        ]);
        Tarefa::factory()->create([
            'criado_por_id' => $beto->id, 'responsavel_id' => $beto->id,
            'status' => 'em_desenvolvimento', 'titulo' => 'Trabalho do Beto', 'prazo' => $hoje,
        ]);

        // Pelos ITENS da tela, e não pelo HTML: o select "Vincular a uma
        // tarefa" do modal lista todo card aberto, filtrado ou não — vincular
        // um compromisso a uma tarefa de outra pessoa é legítimo. Um
        // `assertDontSee` no corpo da página mediria aquele select, não o
        // recorte da Agenda.
        $semFiltro = $this->actingAs($ana)->get(route('agenda.index'));
        $semFiltro->assertOk();
        $this->assertEqualsCanonicalizing(
            ['Trabalho da Ana', 'Trabalho do Beto'],
            $semFiltro->viewData('itens')->pluck('titulo')->all()
        );

        $comFiltro = $this->actingAs($ana)->get(route('agenda.index', ['pessoas' => [$ana->id]]));
        $comFiltro->assertOk();
        $this->assertSame(
            ['Trabalho da Ana'],
            $comFiltro->viewData('itens')->pluck('titulo')->all()
        );
    }

    /**
     * A ordem é cronológica, e dentro do dia o PRAZO vem antes das reuniões.
     *
     * O teste existe porque a primeira versão embaralhava: `sortBy([fn, fn])`
     * faz o Laravel tratar cada closure como COMPARADOR, não como extrator de
     * chave, e a lista mostrava outubro antes de setembro. Nenhuma asserção de
     * conteúdo pega isso — só uma de ordem.
     */
    public function test_itens_saem_em_ordem_cronologica_com_o_prazo_abrindo_o_dia(): void
    {
        $usuario = User::factory()->create();
        $hoje = Carbon::today();

        // Cadastrados FORA de ordem de propósito: é a ordenação que se mede,
        // não a ordem de inserção.
        Compromisso::factory()->em($hoje->copy()->addDays(10)->toDateString(), '09:00')
            ->create(['criado_por_id' => $usuario->id, 'titulo' => 'Daqui a dez dias']);

        Compromisso::factory()->em($hoje->toDateString(), '15:00')
            ->create(['criado_por_id' => $usuario->id, 'titulo' => 'Hoje à tarde']);

        Compromisso::factory()->em($hoje->toDateString(), '08:00')
            ->create(['criado_por_id' => $usuario->id, 'titulo' => 'Hoje cedo']);

        Tarefa::factory()->create([
            'criado_por_id' => $usuario->id,
            'responsavel_id' => $usuario->id,
            'status' => 'em_desenvolvimento',
            'titulo' => 'Vence hoje',
            'prazo' => $hoje->toDateString(),
        ]);

        $resposta = $this->actingAs($usuario)->get(route('agenda.index', ['visao' => 'lista']));
        $resposta->assertOk();

        $this->assertSame(
            ['Vence hoje', 'Hoje cedo', 'Hoje à tarde', 'Daqui a dez dias'],
            $resposta->viewData('itens')->pluck('titulo')->all()
        );
    }

    /**
     * O select "Vincular a uma tarefa" lista por # CRESCENTE.
     *
     * O rótulo é "#<id> — título", então ordenar por título embaralhava os
     * números; quem procura ali procura pelo código.
     */
    public function test_tarefas_vinculaveis_saem_por_id_crescente(): void
    {
        $usuario = User::factory()->create();

        // Criadas fora de ordem de título de propósito.
        $z = Tarefa::factory()->create(['criado_por_id' => $usuario->id, 'status' => 'em_desenvolvimento', 'titulo' => 'Zebra']);
        $a = Tarefa::factory()->create(['criado_por_id' => $usuario->id, 'status' => 'em_desenvolvimento', 'titulo' => 'Abacaxi']);

        $resposta = $this->actingAs($usuario)->get(route('agenda.index'));
        $resposta->assertOk();

        $ids = $resposta->viewData('tarefasVinculaveis')->pluck('id')->all();
        $this->assertSame([$z->id, $a->id], $ids); // ordem de criação = id crescente, não alfabética
    }

    /* ---------- drawer do dia ---------- */

    /**
     * A carga soma as DUAS fontes: tarefa conta para o responsável, compromisso
     * para cada participante. É o dado que nem o card nem o dia isolado mostram
     * — 1 prazo + 2 reuniões pesa tanto quanto 3 prazos.
     */
    public function test_drawer_do_dia_soma_a_carga_das_duas_fontes(): void
    {
        $ana = User::factory()->create(['name' => 'Ana Souza']);
        $beto = User::factory()->create(['name' => 'Beto Lima']);
        $hoje = Carbon::today()->toDateString();

        Tarefa::factory()->create([
            'criado_por_id' => $ana->id, 'responsavel_id' => $ana->id,
            'status' => 'em_desenvolvimento', 'prazo' => $hoje,
        ]);

        foreach (['09:00', '11:00'] as $hora) {
            $compromisso = Compromisso::factory()->em($hoje, $hora)->create(['criado_por_id' => $ana->id]);
            $compromisso->sincronizarParticipantes([$ana->id, $beto->id]);
        }

        $resposta = $this->actingAs($ana)
            ->getJson(route('agenda.dia', ['data' => $hoje]))
            ->assertOk();

        $carga = collect($resposta->json('carga'))->keyBy('id');

        // Ana: um prazo + duas reuniões. Beto: só as duas reuniões.
        $this->assertSame(3, $carga[$ana->id]['qtd']);
        $this->assertTrue($carga[$ana->id]['cheio']);
        $this->assertSame(2, $carga[$beto->id]['qtd']);
        $this->assertFalse($carga[$beto->id]['cheio']);
    }

    /** Com uma pessoa só, a tira não aparece: comparar alguém consigo não diz nada. */
    public function test_drawer_com_uma_pessoa_nao_traz_tira_de_carga(): void
    {
        $ana = User::factory()->create();
        $hoje = Carbon::today()->toDateString();

        Tarefa::factory()->create([
            'criado_por_id' => $ana->id, 'responsavel_id' => $ana->id,
            'status' => 'em_desenvolvimento', 'prazo' => $hoje,
        ]);

        $this->actingAs($ana)
            ->getJson(route('agenda.dia', ['data' => $hoje]))
            ->assertOk()
            ->assertJsonPath('carga', []);
    }

    /* ---------- conflitos ---------- */

    public function test_conflito_aponta_quem_ja_tem_compromisso_sobreposto(): void
    {
        $ana = User::factory()->create();
        $beto = User::factory()->create();
        $hoje = Carbon::today()->toDateString();

        $existente = Compromisso::factory()->em($hoje, '10:00', 2)->create(['criado_por_id' => $ana->id]);
        $existente->sincronizarParticipantes([$beto->id]);

        $resposta = $this->actingAs($ana)->getJson(route('compromissos.conflitos', [
            'data' => $hoje, 'hora' => '11:00',
            'data_fim' => $hoje, 'hora_fim' => '12:00',
            'participantes' => [$beto->id],
        ]))->assertOk();

        $this->assertSame([$beto->id], $resposta->json('conflitos'));
    }

    /**
     * Encostar não é chocar.
     *
     * Uma reunião que termina às 10h e outra que começa às 10h se encostam — e
     * marcar isso como conflito faria o aviso disparar no caso mais comum da
     * agenda de todo mundo, que é uma reunião atrás da outra.
     */
    public function test_compromissos_encostados_nao_sao_conflito(): void
    {
        $ana = User::factory()->create();
        $beto = User::factory()->create();
        $hoje = Carbon::today()->toDateString();

        $existente = Compromisso::factory()->em($hoje, '09:00', 1)->create(['criado_por_id' => $ana->id]);
        $existente->sincronizarParticipantes([$beto->id]);

        $resposta = $this->actingAs($ana)->getJson(route('compromissos.conflitos', [
            'data' => $hoje, 'hora' => '10:00',
            'data_fim' => $hoje, 'hora_fim' => '11:00',
            'participantes' => [$beto->id],
        ]))->assertOk();

        $this->assertSame([], $resposta->json('conflitos'));
    }

    /** Reabrir um compromisso não pode acusá-lo de conflitar consigo mesmo. */
    public function test_compromisso_em_edicao_nao_conflita_com_ele_mesmo(): void
    {
        $ana = User::factory()->create();
        $beto = User::factory()->create();
        $hoje = Carbon::today()->toDateString();

        $compromisso = Compromisso::factory()->em($hoje, '10:00', 1)->create(['criado_por_id' => $ana->id]);
        $compromisso->sincronizarParticipantes([$beto->id]);

        $resposta = $this->actingAs($ana)->getJson(route('compromissos.conflitos', [
            'data' => $hoje, 'hora' => '10:00',
            'data_fim' => $hoje, 'hora_fim' => '11:00',
            'participantes' => [$beto->id],
            'ignorar' => $compromisso->id,
        ]))->assertOk();

        $this->assertSame([], $resposta->json('conflitos'));
    }
}
