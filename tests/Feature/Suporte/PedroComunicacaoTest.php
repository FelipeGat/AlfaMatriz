<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\SecretarioGestorRetrato;
use App\Models\SuporteConversa;
use App\Models\User;
use App\Services\Secretario\MontadorDeBriefing;
use App\Services\Suporte\AcessoInterno;
use App\Services\Suporte\Conhecimento;
use App\Services\Suporte\Contexto;
use Carbon\CarbonImmutable;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A comunicação do Pedro (docs/specs/pedro-comunicacao). As respostas ao Felipe em 31/10/2026 traziam o
 * nome e "lido às" em todas, ofereciam "as outras 19" com 10 itens para mostrar e davam as contas a pagar
 * sem valor. Dados fictícios.
 */
class PedroComunicacaoTest extends TestCase
{
    use RefreshDatabase;

    private User $dono;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00', 'America/Sao_Paulo')); // segunda
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 'segredo', 'verify_token' => 'v', 'graph_version' => 'v20.0'],
            'services.suporte_pedro.conta' => 'dono@teste.local', 'services.suporte_pedro.editores' => ['dono@teste.local']]);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.out'.uniqid('', true)]]])]);
        (new PerfilPermissaoSeeder)->run();
        $this->dono = User::factory()->create(['email' => 'dono@teste.local']);
        CentralIaRunner::create(['user_id' => $this->dono->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        app(Conhecimento::class)->gravarAjuste(AcessoInterno::AJUSTE, "+55 27 91111-0001 | Carlos | diretor\n+55 27 91111-0003 | Diego | suporte");
        $this->retratoDoGestor(now()->subMinutes(8));
    }

    private function retratoDoGestor(Carbon $lido): void
    {
        Sanctum::actingAs($this->dono, ['central-ia-runner']);
        $this->postJson(route('suporte.runner.gestor'), ['maquina' => 'wsl', 'lido_em' => $lido->toIso8601String(),
            'clientes' => [['id' => 13, 'nome' => 'CONDOMINIO JARDIM EXEMPLO', 'fantasia' => 'JARDIM EXEMPLO', 'telefones' => [], 'empresa' => 'GW']],
            'atendimentos' => [['id' => 1, 'numero' => '1201', 'cliente_id' => 13, 'descricao' => 'Portão', 'status' => 'aberto', 'criado_em' => '2026-10-28 09:00:00', 'funcionarios' => [31]]],
            'equipe' => [['id' => 31, 'nome' => 'Lucas Prado', 'telefones' => []]],
            'visitas' => [
                ['id' => 1, 'atendimento_id' => 1, 'tecnico_id' => 31, 'inicio' => '2026-11-04 08:00', 'fim' => '2026-11-04 10:00', 'situacao' => 'agendado', 'observacoes' => '', 'adicionais' => []],
                ['id' => 2, 'atendimento_id' => 1, 'tecnico_id' => 31, 'inicio' => '2026-11-10 08:00', 'fim' => '2026-11-10 09:00', 'situacao' => 'agendado', 'observacoes' => '', 'adicionais' => []],
            ],
        ])->assertOk();
    }

    /** A leitura nova da máquina (plan.md, seção 3): os campos novos dentro dos blocos de sempre. */
    private function financeiroNovo(): array
    {
        return ['maquina' => 'wsl', 'lido_em' => now()->subMinutes(20)->toIso8601String(), 'blocos' => [
            'cobrancas_vencidas' => ['quantidade' => 24, 'valor' => 31480.50,
                'itens' => array_map(fn ($i) => ['texto' => "Cliente Exemplo {$i} — mensalidade", 'data' => '2026-09-'.str_pad((string) (5 + $i), 2, '0', STR_PAD_LEFT), 'valor' => 100.0 * ($i + 1)], range(0, 9)),
                'maiores' => [['texto' => 'Loja Exemplo — instalação', 'data' => '2026-10-01', 'valor' => 5200.00]]],
            'cobrancas_hoje' => ['quantidade' => 2, 'valor' => 2340.00, 'itens' => []],
            'pagar_vencendo' => ['quantidade' => 16, 'valor' => 23870.40, 'itens' => [['texto' => 'Distribuidora Exemplo — cabos', 'data' => '2026-10-15', 'valor' => 3200.00]],
                'vencidas' => ['quantidade' => 12, 'valor' => 18750.40], 'hoje' => ['quantidade' => 4, 'valor' => 5120.00],
                'maiores' => [['texto' => 'Fornecedor Exemplo de Câmeras', 'data' => '2026-10-20', 'valor' => 4800.00]]],
        ]];
    }

    private function prompt(string $telefone, array $mensagens): string
    {
        SuporteConversa::query()->delete();
        $c = SuporteConversa::create(['telefone' => $telefone, 'ultima_entrada_em' => now()]);
        foreach ($mensagens as $i => [$direcao, $texto]) {
            $c->mensagens()->create(['wamid' => 'wamid.'.uniqid().$i, 'direcao' => $direcao, 'tipo' => 'text', 'texto' => $texto, 'enviada_em' => now()]);
        }

        return app(Contexto::class)->prompt($c->fresh());
    }

    public function test_a_regra_de_comunicacao_vai_nos_tres_pedidos_e_as_frases_antigas_sairam(): void
    {
        $casa = $this->prompt('5527911110001', [['entrada', 'quantos atendimentos abertos?']]);
        $desconhecido = $this->prompt('5527933330002', [['entrada', 'olá']]);
        $cliente = SuporteConversa::create(['telefone' => '5527933330009', 'gestor_cliente_id' => 13, 'solicitante_nome' => 'Marta', 'ultima_entrada_em' => now()]);
        $cliente->mensagens()->create(['wamid' => 'wamid.c', 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => 'o portão parou', 'enviada_em' => now()]);
        $doCliente = app(Contexto::class)->prompt($cliente->fresh());

        foreach ([$casa, $desconhecido, $doCliente] as $p) {
            $this->assertStringContainsString(Contexto::COMUNICACAO, $p);
        }
        $this->assertStringNotContainsString('Diga a hora em que cada dado foi lido', $casa);
        $this->assertStringNotContainsString('Trate-o pelo primeiro nome', $casa);
        $this->assertStringNotContainsString('mande os 5 primeiros e pergunte', $casa);
        $this->assertStringNotContainsString('e diga a hora da leitura', $casa);
        $this->assertStringContainsString('LISTA COMPLETA', $casa);
        $this->assertStringContainsString(Contexto::JEITO_POR_PERFIL['diretor'], $casa);
        $this->assertStringNotContainsString('Trate-a pelo primeiro nome', $doCliente);

        $suporte = $this->prompt('5527911110003', [['entrada', 'e o 1201?']]);
        $this->assertStringContainsString(Contexto::JEITO_POR_PERFIL['suporte'], $suporte);
        $this->assertStringNotContainsString(Contexto::JEITO_POR_PERFIL['diretor'], $suporte);
    }

    /** FR-002: o retrato de 8 min não leva marca; o de 2 h vai DESATUALIZADO — só então o Pedro fala da hora. */
    public function test_retrato_do_gestor_velho_vai_marcado_desatualizado(): void
    {
        $recente = $this->prompt('5527911110001', [['entrada', 'quantos atendimentos abertos?']]);
        $this->assertStringContainsString('## ATENDIMENTOS DO GESTOR (lido às 09:52)', $recente);
        $this->assertStringNotContainsString('; DESATUALIZADO, avise', $recente);

        $this->retratoDoGestor(now()->subHours(2));
        $velho = $this->prompt('5527911110001', [['entrada', 'quantos atendimentos abertos?']]);
        $this->assertStringContainsString('## ATENDIMENTOS DO GESTOR (lido às 08:00; DESATUALIZADO, avise)', $velho);
        $this->assertStringContainsString('## AGENDA DOS TÉCNICOS (visitas no Gestor, lido às 08:00; DESATUALIZADO, avise', $velho);
    }

    /** FR-007 a FR-010: a leitura nova é aceita e guardada, e a de antes continua aceita. */
    public function test_leitura_financeira_nova_e_a_antiga_sao_aceitas(): void
    {
        Sanctum::actingAs($this->dono, ['central-ia-runner']);
        $this->postJson(route('secretario.runner.gestor'), $this->financeiroNovo())->assertOk();
        $b = SecretarioGestorRetrato::sole()->dados['blocos'];
        $this->assertSame(['quantidade' => 12, 'valor' => 18750.40], $b['pagar_vencendo']['vencidas']);
        $this->assertSame(4, $b['pagar_vencendo']['hoje']['quantidade']);
        $this->assertSame('Loja Exemplo — instalação', $b['cobrancas_vencidas']['maiores'][0]['texto']);
        $this->assertEquals(2340.00, $b['cobrancas_hoje']['valor']);

        // A de antes (a máquina ainda não atualizada): só a quantidade das contas a pagar.
        $this->postJson(route('secretario.runner.gestor'), ['maquina' => 'wsl', 'lido_em' => now()->toIso8601String(),
            'blocos' => ['pagar_vencendo' => ['quantidade' => 16, 'itens' => []]]])->assertOk();
        // Lixo dentro dos campos novos continua recusado.
        $novo = $this->financeiroNovo();
        $novo['blocos']['pagar_vencendo']['vencidas'] = ['quantidade' => 12, 'apagar' => true];
        $this->postJson(route('secretario.runner.gestor'), $novo)->assertUnprocessable();
        $novo = $this->financeiroNovo();
        $novo['blocos']['cobrancas_vencidas']['maiores'] = array_fill(0, 11, ['texto' => 'x', 'valor' => 1]);
        $this->postJson(route('secretario.runner.gestor'), $novo)->assertUnprocessable();
    }

    public function test_o_diretor_ve_as_contas_a_pagar_separadas_com_valor_e_so_a_lista_que_existe(): void
    {
        Sanctum::actingAs($this->dono, ['central-ia-runner']);
        $this->postJson(route('secretario.runner.gestor'), $this->financeiroNovo())->assertOk();
        $p = $this->prompt('5527911110001', [['entrada', 'como estão as contas a pagar?']]);

        $this->assertStringContainsString('- 16 conta(s) a pagar vencida(s) ou de hoje — total R$ 23.870,40', $p);
        $this->assertStringContainsString('  - vencidas: 12 — total R$ 18.750,40', $p);
        $this->assertStringContainsString('  - vencendo hoje: 4 — total R$ 5.120,00', $p);
        $this->assertStringContainsString('  • Distribuidora Exemplo — cabos — 15/10/2026 — R$ 3.200,00', $p);
        $this->assertStringContainsString('Maiores por valor (1 dos 16):', $p);
        $this->assertStringContainsString('- 2 cobrança(s) vencendo hoje — total R$ 2.340,00', $p);
        $this->assertStringContainsString('(SEM A LISTA: só a quantidade; os itens só no Gestor)', $p);
        // 31/10/2026: "Quer ver as outras 19?" com 10 itens. A nota diz exatamente o que existe.
        $this->assertStringContainsString('(ESTA LISTA TEM SÓ 10 DOS 24: ofereça só estes; os outros 14 só no Gestor)', $p);

        $this->assertStringNotContainsString('vencendo hoje', $this->prompt('5527911110003', [['entrada', 'contas a pagar?']]), 'o suporte não vê o financeiro');
        $this->assertStringNotContainsString('R$ 18.750,40', $this->prompt('5527933330002', [['entrada', 'contas a pagar?']]), 'nem quem é de fora');
    }

    /** FR-011: o resumo do Telegram passa a mostrar o valor das contas a pagar e não repete os maiores. */
    public function test_o_resumo_do_dia_mostra_o_valor_das_contas_a_pagar(): void
    {
        Sanctum::actingAs($this->dono, ['central-ia-runner']);
        $this->postJson(route('secretario.runner.gestor'), $this->financeiroNovo())->assertOk();
        $texto = app(MontadorDeBriefing::class)->montar($this->dono, CarbonImmutable::now())['texto'];

        $this->assertStringContainsString('• 16 conta(s) a pagar vencida(s) ou de hoje (R$ 23.870,40)', $texto);
        $this->assertStringContainsString('• 2 cobrança(s) vencendo hoje (R$ 2.340,00)', $texto);
        $this->assertStringNotContainsString('Fornecedor Exemplo de Câmeras', $texto);
    }

    /** FR-013, com hoje = segunda 02/11/2026. */
    public function test_dias_da_semana(): void
    {
        $dias = fn (string $t, string $hoje) => array_map(fn ($d) => $d->format('d/m'),
            AcessoInterno::diasDaSemana($t, CarbonImmutable::parse($hoje, 'America/Sao_Paulo')));

        $this->assertSame(['04/11'], $dias('agenda de quarta', '2026-11-02'), 'segunda pede quarta: a desta semana');
        $this->assertSame(['04/11'], $dias('agenda de quarta-feira', '2026-11-04'), 'quarta pede quarta: hoje');
        $this->assertSame(['11/11'], $dias('e a próxima quarta?', '2026-11-04'), 'próxima: a da semana seguinte');
        $this->assertSame(['04/11'], $dias('próxima quarta', '2026-11-02'), 'próxima, numa segunda: a desta semana');
        $this->assertSame(['04/11', '11/11'], $dias('como foi a quarta?', '2026-11-06'), 'já passou: a desta e a da próxima');
        $this->assertSame(['09/11', '10/11', '11/11', '12/11', '13/11'], $dias('agenda da semana que vem', '2026-11-02'));
        $this->assertSame(['02/11', '03/11', '04/11', '05/11', '06/11'], $dias('e esta semana?', '2026-11-04'));
        $this->assertSame(['07/11'], $dias('tem visita no sábado?', '2026-11-02'));
        $this->assertSame([], $dias('o quartão do bloco B', '2026-11-02'), 'palavra que só começa com o dia não conta');
    }

    public function test_a_agenda_leva_o_dia_da_semana_pedido_e_o_teto_de_dias(): void
    {
        $p = $this->prompt('5527911110001', [['entrada', 'como está a agenda de quarta?']]);
        $this->assertStringContainsString("### Quarta-feira, 04/11/2026\n- Lucas: 08:00 às 10:00 — nº 1201 — JARDIM EXEMPLO — agendado", $p);

        $p = $this->prompt('5527911110001', [['entrada', 'e a semana que vem?']]);
        $this->assertSame(AcessoInterno::TETO_DE_DIAS, substr_count(substr($p, strpos($p, '## AGENDA DOS TÉCNICOS'), 3000), "\n### "));
        $this->assertStringContainsString("### Terça-feira, 10/11/2026\n", $p);
        // Hoje entra junto, até o teto, para remarcar; mas marcado, para não entrar na resposta (FR-016).
        $this->assertStringContainsString('### Segunda-feira, 02/11/2026 (NÃO PEDIDO)', $p);

        $p = $this->prompt('5527911110001', [['entrada', 'como está a agenda?']]);
        $this->assertStringContainsString('nenhum dia citado: ontem, hoje e amanhã', $p);
        $this->assertStringNotContainsString('NÃO PEDIDO', $p);
    }

    /** FR-012: a resposta da casa em partes, cortada no parágrafo, com teto e aviso. */
    public function test_partes(): void
    {
        $this->assertSame(['curta'], Contexto::partes('curta'));

        $paragrafos = implode("\n\n", array_map(fn ($i) => "Parágrafo {$i}: ".str_repeat('palavra ', 70), range(1, 10)));
        $partes = Contexto::partes($paragrafos);
        $this->assertCount(2, $partes);
        foreach ($partes as $parte) {
            $this->assertLessThanOrEqual(3900, mb_strlen($parte));
            $this->assertStringEndsWith('palavra', $parte, 'corte entre parágrafos, não no meio');
        }
        $this->assertSame(preg_replace('/\s+/', ' ', trim($paragrafos)), preg_replace('/\s+/', ' ', implode(' ', $partes)), 'nada se perde');

        $semQuebra = str_repeat('palavra ', 600);
        $this->assertTrue(collect(Contexto::partes($semQuebra))->every(fn ($p) => mb_strlen($p) <= 3900 && ! str_ends_with($p, 'palavr')));

        $enorme = implode("\n", array_map(fn ($i) => "• item {$i} — ".str_repeat('x', 60), range(1, 300)));
        $partes = Contexto::partes($enorme);
        $this->assertCount(3, $partes);
        $this->assertStringEndsWith(Contexto::AVISO_DE_CORTE, $partes[2]);
        $this->assertTrue(collect($partes)->every(fn ($p) => mb_strlen($p) <= 3900));
    }

    /** FR-012 de ponta a ponta: a casa recebe a lista inteira em mensagens; o cliente continua com uma, curta. */
    public function test_resposta_longa_da_casa_sai_em_partes_e_a_do_cliente_numa_so(): void
    {
        $longa = implode("\n", array_map(fn ($i) => "• nº {$i} — Cliente Exemplo — ".str_repeat('detalhe ', 8), range(1, 120)));
        foreach (['5527911110001' => 3, '5527933330002' => 1] as $telefone => $esperadas) {
            Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.out'.uniqid('', true)]]])]);
            SuporteConversa::query()->delete();
            $c = SuporteConversa::create(['telefone' => $telefone, 'ultima_entrada_em' => now()]);
            $c->mensagens()->create(['wamid' => 'wamid.e'.$telefone, 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => 'me manda a lista completa', 'enviada_em' => now()]);
            $pedido = \App\Models\SuportePedido::create(['conversa_id' => $c->id, 'ate_mensagem_id' => $c->mensagens()->value('id')]);
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($this->dono, ['central-ia-runner']);
            $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->assertOk();
            $this->postJson(route('suporte.runner.resposta', $pedido->id), ['maquina' => 'wsl',
                'texto' => "<<<PEDRO\n".json_encode(['resposta' => $longa, 'acao' => 'responder'], JSON_UNESCAPED_UNICODE)."\nPEDRO>>>"])->assertOk();

            $enviadas = Http::recorded()->map(fn ($par) => (string) ($par[0]['text']['body'] ?? ''))->filter()->values();
            $this->assertCount($esperadas, $enviadas, $telefone);
            $this->assertTrue($enviadas->every(fn ($t) => mb_strlen($t) <= 3900));
            $this->assertSame('respondido', $pedido->fresh()->situacao);
        }
    }
}
