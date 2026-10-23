<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\SuporteConversa;
use App\Models\SuporteGestorAbertura;
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
        $this->assertStringContainsString('A GRAVAÇÃO no Gestor ainda NÃO está disponível', $p);
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
}
