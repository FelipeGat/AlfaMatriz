<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\SuporteConversa;
use App\Models\SuporteGestorAbertura;
use App\Models\SuporteGestorAgenda;
use Laravel\Sanctum\Sanctum;
use App\Models\User;
use App\Services\Suporte\AcessoInterno;
use App\Services\Suporte\Conhecimento;
use App\Services\Suporte\Contexto;
use App\Services\Suporte\DiretorioGestor;
use App\Services\Suporte\Pedro;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * pedro-dia-do-tecnico, FASE 1 (consulta): em 23/10/2026 o Felipe pediu "a agenda dos técnicos de
 * ontem" e o Pedro respondeu que não tinha agendamento de nenhum dia.
 */
class PedroAgendaDoTecnicoTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;

    private const RELATO = 'O Vitor chegou no Corais de Parati às 8:00, às 9:30 foi para a casa da Manuela em Vitória fazer a manutenção dos ar-condicionados, às 14:30 foi para a casa do Éder em Cariacica instalar o ar e encerrou às 18:40.';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-23 15:00', 'America/Sao_Paulo'));
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 'segredo', 'verify_token' => 'v', 'graph_version' => 'v20.0'],
            'services.suporte_pedro.conta' => 'felipe@teste.local']);
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid('', true)]]]));
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['email' => 'felipe@teste.local']);
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        app(Conhecimento::class)->gravarAjuste(AcessoInterno::AJUSTE, "+55 27 99884-5397 | Felipe | diretor\n+55 27 3109-3265 | Alexandre | suporte");
        app(DiretorioGestor::class)->gravar('wsl', '2026-10-23 14:50:00', [
            ['id' => -6176, 'nome' => 'Revizza', 'fantasia' => 'Revizza', 'razao' => '', 'cidade' => 'Vila Velha', 'telefones' => [], 'pre' => true],
            ['id' => 167, 'nome' => 'Corais de Parati', 'fantasia' => 'Corais de Parati', 'razao' => '', 'cidade' => 'Vila Velha', 'empresa' => 'GW', 'telefones' => []],
        ], [
            ['id' => 925, 'numero' => '824', 'cliente_id' => -6176, 'descricao' => 'Visita técnica para orçar elevadores', 'status' => 'aberto', 'criado_em' => '2026-10-23 11:53:00'],
            ['id' => 839, 'numero' => '756', 'cliente_id' => 167, 'descricao' => 'Recuperação estrutural da garagem', 'status' => 'em_atendimento', 'criado_em' => '2026-09-30 10:00:00'],
            ['id' => 810, 'numero' => '729', 'cliente_id' => null, 'solicitante' => 'Emanuele', 'descricao' => 'Ar-condicionado não refrigera', 'status' => 'em_atendimento', 'criado_em' => '2026-09-15 17:03:56'],
        ], [], [['id' => 9, 'nome' => 'Vitor Emanuel Oliveira de Paula', 'telefones' => []], ['id' => 21, 'nome' => 'Cleison Souza', 'telefones' => []],
            ['id' => 5, 'nome' => 'Cristiano Lopes Lucas', 'telefones' => []]], [], [
            ['id' => 1185, 'atendimento_id' => 925, 'tecnico_id' => 9, 'inicio' => '2026-10-23 08:00', 'fim' => '2026-10-23 10:00', 'situacao' => 'agendado', 'observacoes' => '', 'adicionais' => []],
            ['id' => 1181, 'atendimento_id' => 839, 'tecnico_id' => 21, 'inicio' => '2026-10-22 08:00', 'fim' => '2026-10-22 10:00', 'situacao' => 'finalizado', 'observacoes' => '', 'adicionais' => [5]],
            ['id' => 1180, 'atendimento_id' => 810, 'tecnico_id' => 9, 'inicio' => '2026-10-22 08:00', 'fim' => '2026-10-22 18:00', 'situacao' => 'finalizado', 'observacoes' => '', 'adicionais' => []],
            ['id' => 1162, 'atendimento_id' => 839, 'tecnico_id' => 21, 'inicio' => '2026-11-02 08:00', 'fim' => '2026-11-02 18:00', 'situacao' => 'agendado', 'observacoes' => '', 'adicionais' => []],
        ], [['id' => 1, 'numero' => '156/2026', 'cliente_id' => 167, 'atendimento_id' => 839, 'status' => 'aguardando_aprovacao', 'descricao' => 'Automação de porta de vidro', 'agendado_para' => null]]);
    }

    private function prompt(string $telefone, string $texto): string
    {
        return app(Contexto::class)->prompt($this->conversa($telefone, $texto));
    }

    public function test_agenda_de_ontem_e_de_hoje_com_o_pre_cliente_e_a_hora_da_leitura(): void
    {
        $p = $this->prompt('5527998845397', 'Preciso da agenda dos técnicos de ontem');

        $this->assertStringContainsString('## AGENDA DOS TÉCNICOS (visitas no Gestor, lido às 14:50', $p);
        $this->assertStringContainsString('Quinta-feira, 22/10/2026', $p);
        $this->assertStringContainsString('- Cleison: 08:00 às 10:00 — nº 756 — Corais de Parati — finalizado — junto: Cristiano', $p);
        $this->assertStringContainsString('- Vitor: 08:00 às 10:00 — nº 824 — Revizza (pré-cliente) — agendado', $p, 'pré-cliente pelo nome, nunca "sem cliente"');
        $this->assertStringContainsString('- Vitor: 08:00 às 18:00 — nº 729 — Emanuele (sem cadastro) — finalizado', $p, 'atendimento sem cadastro, pelo nome de quem pediu');
        $this->assertStringContainsString('Ao "sim", use acao "confirmar_agenda"', $p);
        $this->assertStringNotContainsString('02/11', $p, 'só os dias pedidos e os vizinhos');
    }

    public function test_dia_citado_sem_visita_e_dia_fora_do_retrato(): void
    {
        $p = $this->prompt('5527998845397', 'e a agenda do Victor em 02/11? e em 20/12?');

        $this->assertStringContainsString('- Cleison: 08:00 às 18:00 — nº 756', $p);
        $this->assertStringContainsString("Sábado, 24/10/2026\n- Nenhuma visita de técnico.", $p);
        $this->assertStringContainsString("Domingo, 20/12/2026\n- Fora do período do retrato", $p);
    }

    public function test_suporte_consulta_e_cliente_nao_ve_a_agenda(): void
    {
        $this->assertStringContainsString('## AGENDA DOS TÉCNICOS', $this->prompt('552731093265', 'agenda de hoje'));
        $this->assertStringNotContainsString('AGENDA DOS TÉCNICOS', $this->prompt('5527988887777', 'qual a agenda do Vitor hoje?'));
    }

    private function conversa(string $telefone, string $texto): SuporteConversa
    {
        $c = SuporteConversa::firstOrCreate(['telefone' => $telefone], ['ultima_entrada_em' => now()]);
        $c->mensagens()->create(['wamid' => 'wamid.'.uniqid(), 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => $texto, 'enviada_em' => now()]);

        return $c->fresh();
    }

    private function concluir(SuporteConversa $c, array $agenda): string
    {
        $p = app(Pedro::class)->enfileirar($c->id);
        $p->update(['situacao' => 'reservado']);
        app(Pedro::class)->concluir($this->felipe, $p->id, '<<<PEDRO '.json_encode(['resposta' => 'O dia do Vitor em 23/10 ficaria assim: …', 'acao' => 'previa_agenda', 'agenda' => $agenda], JSON_UNESCAPED_UNICODE).' PEDRO>>>', null);

        return (string) $c->mensagens()->reorder()->where('direcao', 'pedro')->orderByDesc('id')->value('texto');
    }

    /** FASE 2: o relato do Vitor de 23/10 vira prévia conferida — o conflito com a Revizza e o cliente que falta aparecem; nada vai ao Gestor. */
    public function test_relato_do_dia_vira_previa_conferida_sem_gravar(): void
    {
        $p = $this->prompt('5527998845397', self::RELATO);
        $this->assertStringContainsString('RELATO DO DIA DE UM TÉCNICO', $p);
        $this->assertStringContainsString('• orç. 156/2026 — aguardando aprovacao — do atendimento nº 756 — Automação de porta de vidro', $p, 'os orçamentos em aberto do cliente citado');
        $c = $this->conversa('5527998845397', self::RELATO);
        $msg = $this->concluir($c, [
            ['tipo' => 'criar_visita', 'atendimento' => '756', 'tecnico_id' => 9, 'data' => '2026-10-23', 'hora_inicio' => '08:00', 'hora_fim' => '09:30', 'observacao' => 'garagem', 'inventado' => 'x'],
            ['tipo' => 'abrir_atendimento', 'cliente' => null, 'tecnico_id' => 9, 'data' => '2026-10-23', 'hora_inicio' => '09:30', 'hora_fim' => '14:30', 'observacao' => 'manutenção dos ar-condicionados'],
            ['tipo' => 'abrir_atendimento', 'cliente' => 'novo_pre', 'tecnico_id' => 9, 'data' => '2026-10-23', 'hora_inicio' => '14:30', 'hora_fim' => '18:40', 'observacao' => 'instalação do ar'],
            ['tipo' => 'criar_visita', 'atendimento' => '999', 'tecnico_id' => 77, 'data' => '2026-10-23', 'hora_inicio' => '19:00'],
        ]);

        $this->assertStringContainsString('Parada 1: conflita com a visita de Vitor das 08:00 às 10:00 (nº 824) — qual vale?', $msg);
        $this->assertStringContainsString('Parada 2: o cliente não foi confirmado no Gestor', $msg);
        $this->assertStringNotContainsString('Parada 3', $msg, 'pré-cliente novo com início e fim fecha');
        $this->assertStringContainsString('Parada 4: o técnico não está na equipe do Gestor.', $msg);
        $this->assertStringContainsString('Parada 4: o atendimento nº 999 não foi achado no Gestor.', $msg);
        $this->assertStringContainsString('Parada 4: falta o horário de término.', $msg);

        $previa = $c->fresh()->previa_agenda;
        $this->assertSame(['Felipe', 4], [$previa['pedido_por'], count($previa['paradas'])]);
        $this->assertArrayNotHasKey('inventado', $previa['paradas'][0], 'campo desconhecido não passa');
        $this->assertSame(0, SuporteGestorAbertura::count(), 'nada vai ao Gestor nesta fase');
    }

    public function test_reagendar_a_visita_existente_fecha_e_avisa_a_finalizada(): void
    {
        $c = $this->conversa('5527998845397', 'a visita do Cleison de ontem no Corais foi das 09:00 às 11:00');
        $msg = $this->concluir($c, [['tipo' => 'reagendar_visita', 'visita_id' => 1181, 'tecnico_id' => 21, 'data' => '2026-10-22', 'hora_inicio' => '09:00', 'hora_fim' => '11:00']]);

        $this->assertStringNotContainsString('preciso acertar', $msg);
        $this->assertStringContainsString('a visita das 08:00 já está FINALIZADA no Gestor', $msg);
        $this->assertStringContainsString('tem técnicos adicionais (Cristiano)', $msg);
        $this->assertSame([], $c->fresh()->previa_agenda['problemas']);
    }

    public function test_suporte_nao_lanca_na_agenda(): void
    {
        $this->assertStringNotContainsString('RELATO DO DIA DE UM TÉCNICO', $this->prompt('552731093265', self::RELATO));
        $c = $this->conversa('552731093265', self::RELATO);
        $msg = $this->concluir($c, [['tipo' => 'criar_visita', 'atendimento' => '756', 'tecnico_id' => 9, 'data' => '2026-10-23', 'hora_inicio' => '08:00', 'hora_fim' => '09:30']]);

        $this->assertStringContainsString('Lançar ou corrigir a agenda é só para diretor e gestor.', $msg);
        $this->assertNull($c->fresh()->previa_agenda);
    }

    private function confirmar(SuporteConversa $c): string
    {
        $c->mensagens()->create(['wamid' => 'wamid.'.uniqid(), 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => 'Sim', 'enviada_em' => now()]);
        $p = app(Pedro::class)->enfileirar($c->id);
        $p->update(['situacao' => 'reservado']);
        app(Pedro::class)->concluir($this->felipe, $p->id, '<<<PEDRO '.json_encode(['resposta' => 'Lançando!', 'acao' => 'confirmar_agenda']).' PEDRO>>>', null);

        return (string) $c->mensagens()->reorder()->where('direcao', 'pedro')->orderByDesc('id')->value('texto');
    }

    /** FASE 4: o "sim" põe as visitas na fila; a máquina grava; quem pediu recebe um resumo só. */
    public function test_sim_grava_pela_fila_sem_duplicar_e_resume_o_lote(): void
    {
        $c = $this->conversa('5527998845397', 'a visita do Vitor na Revizza hoje foi das 10:00 às 11:00, e ele foi ao Corais das 13:00 às 14:00');
        $this->concluir($c, [
            ['tipo' => 'reagendar_visita', 'visita_id' => 1185, 'tecnico_id' => 9, 'data' => '2026-10-23', 'hora_inicio' => '10:00', 'hora_fim' => '11:00'],
            ['tipo' => 'criar_visita', 'atendimento' => '756', 'tecnico_id' => 9, 'data' => '2026-10-23', 'hora_inicio' => '13:00', 'hora_fim' => '14:00', 'observacao' => 'troca de estrutura metálica'],
            ['tipo' => 'abrir_atendimento', 'cliente' => 'novo_pre', 'tecnico_id' => 9, 'data' => '2026-10-23', 'hora_inicio' => '15:00', 'hora_fim' => '16:00'],
        ]);
        $this->assertSame([], $c->fresh()->previa_agenda['problemas']);

        $msg = $this->confirmar($c);
        $this->assertSame("Enviei 2 mudanças da agenda para o Gestor; o resultado chega em seguida.\nOs 1 atendimento(s) novo(s) seguem pelo registro de atendimento: vamos a eles.", $msg);
        $this->assertSame(2, SuporteGestorAgenda::count());
        $this->assertSame(['inicio' => '2026-10-23 08:00', 'fim' => '2026-10-23 10:00', 'situacao' => 'agendado'], SuporteGestorAgenda::where('tipo', 'reagendar_visita')->value('esperado'));
        // O "sim" repetido não põe nada de novo na fila.
        $this->assertStringContainsString('Não achei uma prévia da agenda para confirmar', $this->confirmar($c->fresh()));
        $this->assertSame(2, SuporteGestorAgenda::count());

        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $mudancas = $this->postJson(route('suporte.runner.agenda'), ['maquina' => 'wsl'])->assertOk()->json('mudancas');
        $this->assertSame(['reagendar_visita', 'criar_visita'], array_column($mudancas, 'tipo'));
        $this->assertSame(['2026-10-23 13:00', '2026-10-23 14:00', '756', 'Felipe'], [$mudancas[1]['inicio'], $mudancas[1]['fim'], $mudancas[1]['atendimento'], $mudancas[1]['pedido_por']]);
        $this->assertSame([], $this->postJson(route('suporte.runner.agenda'), ['maquina' => 'wsl'])->json('mudancas'), 'reservadas não saem de novo');

        $this->postJson(route('suporte.runner.agenda-feita', $mudancas[0]['id']), ['maquina' => 'wsl', 'ok' => true, 'visita_id' => 1185, 'numero' => '824'])->assertOk();
        Http::assertNotSent(fn ($r) => str_contains((string) ($r['text']['body'] ?? ''), 'Agenda no Gestor'));
        $this->postJson(route('suporte.runner.agenda-feita', $mudancas[1]['id']), ['maquina' => 'wsl', 'ok' => false, 'erro' => 'Técnico já tem agenda no dia 23/10/2026 entre 13:00 e 14:00.'])->assertOk();
        Http::assertSent(fn ($r) => ($r['text']['body'] ?? '') === "Agenda no Gestor:\n• ✅ horário corrigido no nº 824, 23/10 10:00 às 11:00\n• ❌ visita criada no nº 756: não gravou (Técnico já tem agenda no dia 23/10/2026 entre 13:00 e 14:00.)");
    }

    /** T-494: a máquina reservou e a ponte travou — pode ter gravado; o aviso é para conferir, uma vez por lote. */
    public function test_mudanca_reservada_sem_retorno_avisa_para_conferir_e_o_resultado_ainda_vale(): void
    {
        $c = SuporteConversa::create(['telefone' => '5527998845397', 'ultima_entrada_em' => now()]);
        foreach (['reagendar_visita', 'criar_visita'] as $i => $tipo) {
            SuporteGestorAgenda::create(['conversa_id' => $c->id, 'chave' => 'k'.$i, 'lote' => 'lote-1', 'tipo' => $tipo, 'visita_id' => $i ? null : 1185, 'atendimento' => '824',
                'tecnico_id' => 9, 'inicio' => '2026-10-23 10:00', 'fim' => '2026-10-23 11:00', 'pedido_por' => 'Felipe', 'situacao' => 'reservado', 'reservado_em' => now()]);
        }
        Carbon::setTestNow(now()->addMinutes(11));
        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->artisan('suporte:pedro')->assertSuccessful();

        $this->assertSame(['reservado'], SuporteGestorAgenda::pluck('situacao')->unique()->values()->all());
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => ($r['text']['body'] ?? '') === 'A agenda do Gestor ainda não confirmou 2 mudança(s) que comecei a gravar às 15:00. '
            .'Elas podem já estar lá: confira a agenda no Gestor antes de lançar à mão. Quando a confirmação chegar, eu aviso aqui.');

        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        foreach (SuporteGestorAgenda::orderBy('id')->pluck('id') as $id) {
            $this->postJson(route('suporte.runner.agenda-feita', $id), ['maquina' => 'wsl', 'ok' => true, 'visita_id' => 1185, 'numero' => '824'])->assertOk();
        }
        Http::assertSent(fn ($r) => str_starts_with((string) ($r['text']['body'] ?? ''), "Agenda no Gestor:\n• ✅ horário corrigido no nº 824"));
    }

    public function test_sim_com_previa_pendente_nao_grava_e_mudanca_sem_maquina_vence(): void
    {
        $c = $this->conversa('5527998845397', self::RELATO);
        $this->concluir($c, [['tipo' => 'criar_visita', 'atendimento' => '756', 'tecnico_id' => 9, 'data' => '2026-10-23', 'hora_inicio' => '08:00', 'hora_fim' => '09:30']]);
        $this->assertStringStartsWith('Ainda não lancei, porque conferi no Gestor e falta acertar:', $this->confirmar($c));
        $this->assertSame(0, SuporteGestorAgenda::count());

        $c2 = $this->conversa('5527998845397', 'corrige a visita da Revizza para 10:00 às 11:00');
        $this->concluir($c2, [['tipo' => 'reagendar_visita', 'visita_id' => 1185, 'tecnico_id' => 9, 'data' => '2026-10-23', 'hora_inicio' => '10:00', 'hora_fim' => '11:00']]);
        $this->confirmar($c2->fresh());
        Carbon::setTestNow(now()->addMinutes(5));
        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->assertSame(['falhou', 'nenhuma máquina gravou no Gestor'], [SuporteGestorAgenda::sole()->situacao, SuporteGestorAgenda::sole()->erro]);
        Http::assertSent(fn ($r) => str_contains((string) ($r['text']['body'] ?? ''), '❌ horário corrigido no nº 824: não gravou (nenhuma máquina gravou no Gestor)'));
    }

    /** 23/10/2026: o Pedro disse ao Wallas que o Corais "já foi enviado" — era só a mensagem dele dizendo "estou enviando". */
    public function test_o_pedido_traz_o_que_de_fato_foi_enviado_ao_gestor(): void
    {
        $this->assertStringContainsString("## O QUE FOI ENVIADO AO GESTOR NESTA CONVERSA (últimos 3 dias — a fonte da verdade)\n- Nada.", $this->prompt('5527998845397', 'lança o Corais'));

        $c = SuporteConversa::where('telefone', '5527998845397')->sole();
        SuporteGestorAbertura::create(['conversa_id' => $c->id, 'modo' => 'vincular', 'atendimento_numero' => '756', 'titulo' => 'Troca de estrutura metálica', 'agendar_data' => '2026-10-22',
            'hora_inicio' => '08:00', 'hora_fim' => '09:30', 'descricao' => 'x', 'telefone' => $c->telefone, 'assunto_id' => 5, 'empresa_id' => 1, 'solicitante' => 'Wallas', 'prioridade' => 'media', 'situacao' => 'falhou', 'erro' => 'Técnico já tem agenda no dia 22/10/2026 entre 08:00 e 09:30.']);
        SuporteGestorAgenda::create(['conversa_id' => $c->id, 'chave' => 'k1', 'lote' => 'l1', 'tipo' => 'cancelar_visita', 'visita_id' => 1180, 'atendimento' => '729', 'tecnico_id' => 9,
            'pedido_por' => 'Wallas', 'situacao' => 'feito', 'motivo' => 'não aconteceu']);
        $p = $this->prompt('5527998845397', 'e o Corais?');
        $this->assertStringContainsString('- Atendimento vincular ao nº 756 "Troca de estrutura metálica", agenda 22/10 08:00–09:30: NÃO gravou — Técnico já tem agenda', $p);
        $this->assertStringContainsString('- Agenda: cancelar visita no nº 729: GRAVADO', $p);
        $this->assertStringContainsString('Mensagem sua anterior dizendo "estou enviando" NÃO prova nada', $p);
    }
}
