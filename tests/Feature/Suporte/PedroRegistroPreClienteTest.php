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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * O pedido do Wallas de 22/10/2026: "crie um chamado para visita técnica na empresa revissa em
 * Vila Velha [...] amanhã às 08:00". O cliente é a Revizza (Vitória), a unidade é outra, e a agenda
 * só sai com técnico e horário de início e fim.
 */
class PedroRegistroPreClienteTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;

    private const PEDIDO = 'Pedro, crie um chamado para visita técnica na empresa revissa em vila velha. Serviço a ser orçado será manutenção preventiva e corretiva em elevadores da oficina. Amanhã dia 23/10/2026 às 08:00.';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-22 15:00', 'America/Sao_Paulo'));
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 'segredo', 'verify_token' => 'v', 'graph_version' => 'v20.0'],
            'services.suporte_pedro.conta' => 'felipe@teste.local']);
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid('', true)]]]));
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['email' => 'felipe@teste.local']);
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        app(Conhecimento::class)->gravarAjuste(AcessoInterno::AJUSTE, '+55 27 99745-8265 | Wallas | gestor');
        app(DiretorioGestor::class)->gravar('wsl', now()->toDateTimeString(), [
            ['id' => 40, 'nome' => 'REVIZZA', 'fantasia' => 'REVIZZA', 'razao' => 'REVIZZA COMERCIO LTDA', 'cidade' => 'Vitória', 'empresa_id' => 2, 'empresa' => 'GW', 'telefones' => []],
            ['id' => -7, 'nome' => 'Oficina Elevar', 'fantasia' => 'Oficina Elevar', 'razao' => '', 'cidade' => 'Serra', 'empresa_id' => 2, 'empresa' => 'GW', 'telefones' => [], 'pre' => true],
        ], [
            ['id' => 9, 'numero' => '640', 'cliente_id' => 40, 'descricao' => 'Elevador da oficina parado', 'status' => 'concluido', 'criado_em' => '2026-09-10 09:00:00', 'funcionarios' => [21], 'assunto_id' => 5],
        ], [['id' => 5, 'nome' => 'Manutenção de elevadores', 'tipo' => 'SERVICO', 'categoria' => 'Elevadores', 'empresa_id' => 2]],
            [['id' => 21, 'nome' => 'Vitor Silva', 'telefones' => []]]);
    }

    private function conversa(string $texto): SuporteConversa
    {
        $c = SuporteConversa::create(['telefone' => '5527997458265', 'ultima_entrada_em' => now()]);
        $c->mensagens()->create(['wamid' => 'wamid.'.uniqid(), 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => $texto, 'enviada_em' => now()]);

        return $c->fresh();
    }

    private function concluir(SuporteConversa $c, array $bloco): string
    {
        $p = app(Pedro::class)->enfileirar($c->id);
        $p->update(['situacao' => 'reservado']);
        app(Pedro::class)->concluir($this->felipe, $p->id, '<<<PEDRO '.json_encode(['resposta' => 'Certo, Wallas.', 'acao' => 'abrir_atendimento', 'resumo' => 'Orçar manutenção preventiva e corretiva nos elevadores da oficina.'] + $bloco, JSON_UNESCAPED_UNICODE).' PEDRO>>>', null);

        return (string) $c->mensagens()->reorder()->where('direcao', 'pedro')->orderByDesc('id')->value('texto');
    }

    public function test_revissa_acha_a_revizza_com_a_cidade_e_as_regras_do_fluxo(): void
    {
        $p = app(Contexto::class)->prompt($this->conversa(self::PEDIDO));
        $this->assertStringContainsString('gestor:40: [CLIENTE] REVIZZA — REVIZZA COMERCIO LTDA — cidade: Vitória', $p);
        $this->assertStringContainsString('nº 640 — concluido', $p, 'o atendimento anterior aparece para dar andamento');
        $this->assertStringContainsString('criar um PRÉ-CLIENTE NOVO para esta unidade', $p);
        $this->assertStringContainsString('HORÁRIO DE INÍCIO E DE FIM', $p);
        $this->assertStringContainsString('Agendamento: dd/mm/aaaa, das HH:MM às HH:MM', $p);
        $this->assertStringContainsString('- 21: Vitor Silva', $p);
    }

    /** 23/10/2026: "vila velha" em sete nomes tomava as vagas; o pré-cliente Revizza de Vila Velha tem de vir primeiro. */
    public function test_palavra_rara_decide_e_a_cidade_citada_desempata(): void
    {
        $clientes = [['id' => 40, 'nome' => 'REVIZZA — Vitória', 'fantasia' => '', 'razao' => '', 'cidade' => 'Vitória', 'empresa_id' => 2, 'empresa' => 'GW', 'telefones' => []],
            ['id' => -6176, 'nome' => 'Revizza', 'fantasia' => 'Revizza', 'razao' => 'TOP CAR VEICULOS E SERVICOS AUTOMOTIVOS LTDA REVIZZA', 'cidade' => 'Vila Velha', 'telefones' => [], 'pre' => true]];
        foreach (['JEEP', 'PROSED', 'BYD', 'FIAT', 'HONDA', 'TOYOTA', 'VOLVO'] as $i => $marca) {
            $clientes[] = ['id' => 100 + $i, 'nome' => "{$marca} (VILA VELHA)", 'fantasia' => '', 'razao' => '', 'cidade' => 'Vila Velha', 'empresa_id' => 2, 'empresa' => 'GW', 'telefones' => []];
        }
        app(DiretorioGestor::class)->gravar('wsl', now()->toDateTimeString(), $clientes, []);

        $achados = array_column(app(DiretorioGestor::class)->buscarClientes(self::PEDIDO), 'id');
        $this->assertSame([-6176, 40], array_slice($achados, 0, 2));
    }

    public function test_agenda_sem_tecnico_ou_sem_fim_nao_registra_e_pergunta(): void
    {
        $c = $this->conversa(self::PEDIDO);
        $msg = $this->concluir($c, ['cliente' => 'gestor:40', 'modo' => 'novo', 'empresa_id' => 2, 'assunto_id' => 5, 'titulo' => 'Visita técnica',
            'agendar' => true, 'data' => '2026-10-23', 'hora_inicio' => '08:00']);
        $this->assertSame(0, SuporteGestorAbertura::count());
        $this->assertStringContainsString('Ainda não registrei: para agendar preciso de o técnico, o horário de término', $msg);
    }

    public function test_pre_cliente_novo_com_titulo_e_horario_vai_ao_gestor(): void
    {
        $c = $this->conversa(self::PEDIDO);
        $msg = $this->concluir($c, ['cliente' => 'novo_pre', 'novo_pre' => ['nome' => 'Revizza Vila Velha', 'cidade' => 'Vila Velha'], 'modo' => 'novo', 'empresa_id' => 2,
            'assunto_id' => 5, 'titulo' => 'Visita técnica', 'tecnico_id' => 21, 'agendar' => true, 'data' => '2026-10-23', 'hora_inicio' => '08:00', 'hora_fim' => '10:00']);
        $this->assertStringContainsString('Enviei para o Gestor', $msg);

        $a = SuporteGestorAbertura::sole();
        $this->assertNull($a->gestor_cliente_id);
        $this->assertSame(['nome' => 'Revizza Vila Velha', 'cidade' => 'Vila Velha'], $a->novo_pre);
        $this->assertSame(['08:00', '10:00', '2026-10-23', 21], [$a->hora_inicio, $a->hora_fim, $a->agendar_data->toDateString(), $a->funcionario_id]);
        $this->assertStringStartsWith("Visita técnica\n\nOrçar manutenção", $a->descricao);

        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $this->postJson(route('suporte.runner.aberturas'), ['maquina' => 'wsl'])->assertOk()
            ->assertJsonPath('aberturas.0.novo_pre.nome', 'Revizza Vila Velha')->assertJsonPath('aberturas.0.cliente_id', null)
            ->assertJsonPath('aberturas.0.hora_inicio', '08:00')->assertJsonPath('aberturas.0.hora_fim', '10:00');
        $this->postJson(route('suporte.runner.abertura-feita', $a->id), ['maquina' => 'wsl', 'numero' => '901', 'modo' => 'novo', 'agendado' => true])->assertOk();
        Http::assertSent(fn ($r) => ($r['text']['body'] ?? '') === "O atendimento é o nº 901. Cliente: Revizza Vila Velha (pré-cliente criado agora), no Gestor.\nAgendado: Vitor Silva, 23/10/2026, das 08:00 às 10:00.");
    }

    public function test_pre_cliente_do_funil_vai_pelo_numero_dele(): void
    {
        $c = $this->conversa('abre uma visita técnica na oficina elevar amanhã das 14:00 às 16:00 com o Vitor');
        $this->concluir($c, ['cliente' => 'gestor:-7', 'modo' => 'novo', 'empresa_id' => 2, 'assunto_id' => 5, 'titulo' => 'Visita técnica',
            'tecnico_id' => 21, 'agendar' => true, 'data' => '2026-10-23', 'hora_inicio' => '14:00', 'hora_fim' => '16:00']);
        $this->assertSame(-7, SuporteGestorAbertura::sole()->gestor_cliente_id);

        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $this->postJson(route('suporte.runner.aberturas'), ['maquina' => 'wsl'])->assertOk()
            ->assertJsonPath('aberturas.0.pre_cliente_id', 7)->assertJsonPath('aberturas.0.cliente_id', null);
    }
}
