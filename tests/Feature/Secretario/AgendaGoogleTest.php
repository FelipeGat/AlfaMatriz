<?php

namespace Tests\Feature\Secretario;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaMensagem;
use App\Models\Compromisso;
use App\Models\SecretarioGoogleEvento;
use App\Models\SecretarioItem;
use App\Models\SecretarioProposta;
use App\Services\CentralIa\TelegramCanal;
use App\Services\Secretario\AgendaConsolidada;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** US2–US4 da Etapa 2: visão, conflito/livre, perguntas sem IA e evento confirmado no Google. */
class AgendaGoogleTest extends TestCase
{
    use GoogleNosTestes, RefreshDatabase, SecretarioNosTestes;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.canal_exclusivo' => false]);
        Carbon::setTestNow(Carbon::parse('2026-10-16 09:00', 'America/Sao_Paulo'));
        $this->configurarGoogle();
    }

    private function cenario(): \App\Models\User
    {
        $u = $this->dono();
        // Amanhã (sáb 17/10): Google 10:00–11:00 e 14:30–15:30 (VixCard); Matriz 10:30–11:30; Secretário lembrete 16:00.
        $this->evento('felipe@gmail.com', 'g1', 'Reunião comercial', '2026-10-17T10:00:00-03:00', '2026-10-17T11:00:00-03:00');
        $this->evento('vixcard@group.calendar.google.com', 'g2', 'Visita Condonal', '2026-10-17T14:30:00-03:00', '2026-10-17T15:30:00-03:00');
        $this->evento('felipe@gmail.com', 'g3', 'Aniversário', '2026-10-17', '2026-10-18', diaInteiro: true);
        $this->fakeGoogle();
        $this->conectarGoogle($u);
        $c = Compromisso::create(['titulo' => 'Revisão do quadro', 'data' => '2026-10-17', 'data_fim' => '2026-10-17', 'hora' => '10:30', 'hora_fim' => '11:30',
            'categoria' => 'interna', 'criado_por_id' => $u->id]);
        $c->sincronizarParticipantes([$u->id]);
        SecretarioItem::create(['user_id' => $u->id, 'tipo' => 'lembrete', 'titulo' => 'Ligar para o Alexandre', 'quando' => '2026-10-17 16:00:00', 'origem' => 'matriz']);

        return $u;
    }

    public function test_visao_junta_as_tres_fontes_com_origem_e_conflito(): void
    {
        $u = $this->cenario();
        $dia = CarbonImmutable::parse('2026-10-17', 'America/Sao_Paulo');
        $itens = app(AgendaConsolidada::class)->periodo($u, $dia, $dia->endOfDay());
        $this->assertSame(['Aniversário', 'Reunião comercial', 'Revisão do quadro', 'Visita Condonal', 'Ligar para o Alexandre'], $itens->pluck('titulo')->all());
        $this->assertSame(['google', 'google', 'matriz', 'google', 'secretario'], $itens->pluck('origem')->all());
        $this->assertSame([false, true, true, false, false], $itens->pluck('conflito')->all());
        $livres = array_map(fn ($l) => $l[0]->format('H:i').'–'.$l[1]->format('H:i'), app(AgendaConsolidada::class)->livres($itens, $dia));
        $this->assertSame(['08:00–10:00', '11:30–14:30', '15:30–18:00'], $livres);

        // Etapa 8: a tela é calendário; os eventos vêm do JSON do intervalo.
        $this->actingAs($u)->get(route('secretario.agenda'))->assertOk();
        $this->getJson(route('secretario.agenda.eventos', ['de' => $dia->format('Y-m-d'), 'ate' => $dia->format('Y-m-d')]))->assertOk()
            ->assertJsonPath('eventos.3.titulo', 'Visita Condonal')->assertJsonPath('eventos.3.origem_rotulo', 'Google · VixCard')->assertJsonPath('eventos.1.conflito', true);
    }

    public function test_casos_de_conflito_e_livre(): void
    {
        $a = app(AgendaConsolidada::class);
        $t = fn ($h) => CarbonImmutable::parse('2026-10-20 '.$h, 'America/Sao_Paulo');
        $i = fn ($de, $ate, $ocupa = true) => ['inicio' => $t($de), 'fim' => $t($ate), 'ocupa' => $ocupa, 'titulo' => 'x'];
        $casos = [
            [[$i('10:00', '11:00'), $i('10:30', '11:30')], [true, true]],     // parcial
            [[$i('10:00', '12:00'), $i('10:30', '11:00')], [true, true]],     // contido
            [[$i('10:00', '11:00'), $i('11:00', '12:00')], [false, false]],   // encostado
            [[$i('10:00', '11:00'), $i('10:00', '11:00')], [true, true]],     // idêntico
            [[$i('00:00', '23:59', false), $i('10:00', '11:00')], [false, false]], // dia inteiro não ocupa
            [[$i('09:00', '10:00'), $i('13:00', '14:00')], [false, false]],   // separados
        ];
        foreach ($casos as [$itens, $esperado]) {
            $this->assertSame($esperado, $a->marcarConflitos(collect($itens))->pluck('conflito')->all());
        }
        $dia = $t('00:00');
        $fmt = fn ($l) => array_map(fn ($x) => $x[0]->format('H:i').'–'.$x[1]->format('H:i'), $l);
        $this->assertSame(['08:00–18:00'], $fmt($a->livres(collect(), $dia)));
        $this->assertSame(['08:20–18:00'], $fmt($a->livres(collect([$i('07:00', '08:20')]), $dia)), 'Antes das 8 não conta');
        $this->assertSame(['08:00–09:00', '09:40–18:00'], $fmt($a->livres(collect([$i('09:00', '09:20'), $i('09:15', '09:40')]), $dia)));
        $this->assertSame(['08:00–17:40'], $fmt($a->livres(collect([$i('17:40', '20:00')]), $dia)), 'Sobra de 20 min no fim não é livre');
    }

    public function test_pergunta_pelo_telegram_responde_sem_ia_com_a_agenda_real(): void
    {
        $u = $this->cenario();
        app(TelegramCanal::class)->receber($this->mensagem(50, 'quais são meus compromissos de amanhã?'), $this->botNoTeste($u));
        $texto = collect($this->enviados())->last()['text'];
        foreach (['10:00–11:00 Reunião comercial — Google · Financeiro Grupo ⚠ conflito', '10:30–11:30 Revisão do quadro — Agenda do Matriz ⚠ conflito',
            '14:30–15:30 Visita Condonal — Google · VixCard', '16:00 Ligar para o Alexandre — Secretário · Lembrete', 'dia inteiro Aniversário',
            'Livre: 08:00–10:00, 11:30–14:30, 15:30–18:00', 'Google (lido às 09:00)'] as $esperado) {
            $this->assertStringContainsString($esperado, $texto);
        }
        $this->assertSame(0, CentralIaExecucao::count(), 'Sem IA');
        $this->assertSame(0, SecretarioProposta::count());
    }

    public function test_horario_livre_sim_e_nao(): void
    {
        $u = $this->cenario();
        $this->actingAs($u);
        $enviar = fn ($t) => $this->postJson(route('central-ia.enviar'), ['client_id' => (string) Str::uuid(), 'conteudo' => $t, 'agente' => 'auto'])->assertCreated();
        $enviar('tenho horário livre amanhã às 15h?');
        $nao = CentralIaMensagem::where('autor_nome', 'Secretário')->latest('id')->value('conteudo');
        $this->assertStringContainsString('❌ Não', $nao);
        $this->assertStringContainsString('Visita Condonal', $nao);
        $this->assertStringContainsString('Livre mais perto: 15:30–18:00', $nao);
        $enviar('estou livre amanhã às 12h?');
        $this->assertStringContainsString('✅ Sim', CentralIaMensagem::where('autor_nome', 'Secretário')->latest('id')->value('conteudo'));
    }

    public function test_sem_google_conectado_a_pergunta_segue_a_central(): void
    {
        $this->fakeGoogle();
        $u = $this->dono(telegram: false);
        // Foi para a Central (que, sem runner no teste, recusa por falta de agente).
        $this->actingAs($u)->postJson(route('central-ia.enviar'), ['client_id' => (string) Str::uuid(), 'conteudo' => 'quais compromissos tenho amanhã?', 'agente' => 'auto'])
            ->assertUnprocessable()->assertJsonValidationErrors('agente');
        $this->assertSame(0, CentralIaMensagem::where('autor_nome', 'Secretário')->count());
        // "Agenda uma reunião…" sem Google também não é do Secretário.
        $this->postJson(route('central-ia.enviar'), ['client_id' => (string) Str::uuid(), 'conteudo' => 'agenda uma reunião com o Alexandre segunda às 14h'])->assertCreated();
        $this->assertSame(0, SecretarioProposta::count());
    }

    public function test_agendar_propoe_confirma_e_cria_uma_vez_sem_convidados(): void
    {
        $u = $this->cenario();
        $this->agendaVix($u)->update(['palavras' => 'vixcard, vix-id']);
        $bot = $this->botNoTeste($u);
        app(TelegramCanal::class)->receber($this->mensagem(60, 'agenda uma reunião da VixCard com o Alexandre amanhã às 14h'), $bot);
        $proposta = SecretarioProposta::sole();
        $texto = collect($this->enviados())->last()['text'];
        $this->assertStringContainsString('Criar no Google, agenda VixCard, amanhã (sáb, 17/10) às 14:00–15:00', $texto);
        $this->assertStringContainsString('Sem convidados', $texto);
        $this->assertStringContainsString('Conflita com: 14:30 Visita Condonal', $texto);
        $this->assertSame([], $this->criados, 'Nada criado antes de confirmar');

        app(TelegramCanal::class)->receber($this->toque('ev-1', "s:pc:{$proposta->id}"), $bot);
        app(TelegramCanal::class)->receber($this->toque('ev-2', "s:pc:{$proposta->id}"), $bot);
        $this->assertCount(1, $this->criados, 'Um evento só no Google');
        $this->assertArrayNotHasKey('attendees', $this->criados[0]);
        $this->assertSame('Reunião da VixCard com o Alexandre', $this->criados[0]['summary']);
        $this->assertSame('2026-10-17T14:00:00-03:00', $this->criados[0]['start']['dateTime']);
        $this->assertStringContainsString('Agendado no Google (VixCard)', collect($this->enviados('editMessageText'))->first()['text']);

        $item = SecretarioItem::where('origem', 'google')->sole();
        $this->assertSame('2026-10-17 13:30', $item->avisos()->first()->para->format('Y-m-d H:i'), 'Aviso 30 min antes');
        $dia = CarbonImmutable::parse('2026-10-17', 'America/Sao_Paulo');
        $titulos = app(AgendaConsolidada::class)->periodo($u, $dia, $dia->endOfDay())->pluck('titulo');
        $this->assertSame(1, $titulos->filter(fn ($t) => $t === 'Reunião da VixCard com o Alexandre')->count(), 'Aparece uma vez só');
    }

    public function test_falha_do_google_diz_que_nao_agendou(): void
    {
        $u = $this->cenario();
        $this->insertFalha = true;
        $this->actingAs($u);
        $enviar = fn ($t) => $this->postJson(route('central-ia.enviar'), ['client_id' => (string) Str::uuid(), 'conteudo' => $t])->assertCreated();
        $enviar('marca call com a Condonal segunda das 10h às 10h30');
        $this->assertStringContainsString('seg, 19/10 às 10:00–10:30', CentralIaMensagem::where('autor_nome', 'Secretário')->latest('id')->value('conteudo'));
        $enviar('sim');
        $this->assertStringContainsString('❌ Não agendei', CentralIaMensagem::where('autor_nome', 'Secretário')->latest('id')->value('conteudo'));
        $this->assertSame(0, SecretarioItem::where('origem', 'google')->count());
        $this->assertSame(SecretarioProposta::PENDENTE, SecretarioProposta::sole()->status);
        $this->insertFalha = false;
        $enviar('sim');
        $this->assertCount(1, $this->criados);
        $this->assertSame(1, SecretarioGoogleEvento::where('google_id', 'evt-novo-1')->count());
    }
}
