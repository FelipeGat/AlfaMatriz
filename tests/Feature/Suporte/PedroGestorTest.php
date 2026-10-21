<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\Cliente;
use App\Models\Notificacao;
use App\Models\SuporteConversa;
use App\Models\SuporteGestorAbertura;
use App\Models\SuportePedido;
use App\Models\Tarefa;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** O Pedro e o Gestor (docs/specs/suporte-pedro-whatsapp; Felipe, 20/10/2026), com a IA falsa. */
class PedroGestorTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;
    private User $rossini;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00', 'America/Sao_Paulo'));
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 'segredo', 'verify_token' => 'v', 'graph_version' => 'v20.0']]);
        config(['services.suporte_pedro.conta' => 'felipe@teste.local', 'services.suporte_pedro.responsaveis' => ['rossini@teste.local']]);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.out.'.uniqid('', true)]]])]);
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['email' => 'felipe@teste.local']);
        $this->rossini = User::factory()->create(['name' => 'Rossini', 'email' => 'rossini@teste.local']);
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        Cliente::create(['nome' => 'Condominio Parque das Castanheiras', 'ativo' => true]);

        $this->runner();
        $this->postJson(route('suporte.runner.gestor'), ['maquina' => 'wsl', 'lido_em' => now()->toIso8601String(),
            'clientes' => [
                ['id' => 13, 'nome' => 'CONDOMINIO CASTANHEIRAS', 'fantasia' => 'CONDOMINIO CASTANHEIRAS', 'razao' => 'CONDOMINIO RESIDENCIAL PARQUE DAS CASTANHEIRAS', 'cidade' => 'VILA VELHA', 'empresa_id' => 3, 'empresa' => 'INVEST', 'telefones' => ['(27) 3389-4253']],
                ['id' => 20, 'nome' => 'CONDOMINIO SOLAR', 'fantasia' => '', 'razao' => 'CONDOMINIO SOLAR DOS IPES', 'cidade' => 'VILA VELHA', 'empresa_id' => 3, 'empresa' => 'INVEST', 'telefones' => []],
            ],
            'atendimentos' => [['id' => 889, 'numero' => '782', 'cliente_id' => 13, 'descricao' => 'Portão de saída G1 não fecha', 'status' => 'concluido', 'empresa_id' => 3, 'assunto_id' => 3, 'criado_em' => '2026-10-06 07:56:06']],
            'assuntos' => [['id' => 3, 'nome' => 'PORTÃO / ACESSO', 'tipo' => 'SERVICO', 'categoria' => 'CONTOLE DE ACESSO', 'empresa_id' => 3],
                ['id' => 27, 'nome' => 'CFTV', 'tipo' => 'SERVICO', 'categoria' => 'SEGURANÇA ELETRONICA', 'empresa_id' => 3],
                ['id' => 9, 'nome' => 'MANUTENÇÃO CORRETIVA', 'tipo' => 'SERVICO', 'categoria' => 'REFRIGERAÇÃO', 'empresa_id' => 2]],
        ])->assertOk()->assertJsonPath('clientes', 2);
    }

    private function runner(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
    }

    private function chega(string $texto, string $id, string $de = '5527988887777'): void
    {
        $valor = ['metadata' => ['phone_number_id' => '111222'], 'contacts' => [['wa_id' => $de, 'profile' => ['name' => 'Joaquim']]],
            'messages' => [['from' => $de, 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $texto]]]];
        $corpo = json_encode(['entry' => [['changes' => [['field' => 'messages', 'value' => $valor]]]]]);
        $this->call('POST', route('suporte.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $corpo, 'segredo')], $corpo)->assertOk();
    }

    private function responde(array $bloco): int
    {
        $this->runner();
        $job = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->json();
        $this->postJson(route('suporte.runner.resposta', $job['id']), ['maquina' => 'wsl', 'texto' => "<<<PEDRO\n".json_encode($bloco, JSON_UNESCAPED_UNICODE)."\nPEDRO>>>"])->assertOk();

        return $job['id'];
    }

    public function test_acha_o_condominio_pelo_nome_e_confirma_so_codigo_oferecido(): void
    {
        $this->chega('eu sou o porteiro do condominio parque das castanheiras e estou com problema no portao', 'wamid.1');
        $this->runner();
        $prompt = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->json('prompt');
        $this->assertStringContainsString('gestor:13: CONDOMINIO RESIDENCIAL PARQUE DAS CASTANHEIRAS (CONDOMINIO CASTANHEIRAS), VILA VELHA', $prompt);
        $this->assertStringContainsString('matriz:', $prompt, 'o do Matriz (AlfaControl) também aparece como candidato');
        $this->assertStringNotContainsString('gestor:20', $prompt, '"solar" sozinho não identifica');
        SuportePedido::query()->update(['situacao' => 'pendente']);

        // Código inventado não vale; o oferecido, sim — e só depois a conversa ganha o cliente do Gestor.
        $this->responde(['resposta' => 'É do Castanheiras?', 'acao' => 'responder', 'cliente' => 'gestor:999']);
        $this->assertNull(SuporteConversa::sole()->gestor_cliente_id);
        $this->chega('isso, o G1 de novo não fecha', 'wamid.2');
        $this->responde(['resposta' => 'Vou encaminhar.', 'acao' => 'responder', 'cliente' => 'gestor:13']);
        $this->assertSame(13, SuporteConversa::sole()->gestor_cliente_id);

        $this->chega('e aí?', 'wamid.3');
        $this->runner();
        $prompt = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->json('prompt');
        $this->assertStringContainsString('CONFIRMADO (Gestor', $prompt);
        $this->assertStringContainsString('Gestor nº 782: Portão de saída G1 não fecha — etapa: concluido', $prompt);
        $this->assertStringContainsString("- 3: PORTÃO / ACESSO", $prompt);
        $this->assertStringNotContainsString('MANUTENÇÃO CORRETIVA', $prompt, 'assunto de outra empresa não entra');
    }

    public function test_gravacao_desligada_vira_tarefa_para_lancar_no_gestor(): void
    {
        config(['services.suporte_pedro.gestor_gravar' => false]);
        SuporteConversa::create(['telefone' => '5527988887777', 'gestor_cliente_id' => 13, 'ultima_entrada_em' => now()]);
        $this->chega('o portão G1 não fecha', 'wamid.1');
        $this->responde(['resposta' => 'Vou encaminhar para a equipe técnica.', 'acao' => 'abrir_atendimento', 'resumo' => 'Portão G1 não fecha', 'destino' => 'gestor', 'assunto_id' => 3]);

        $this->assertSame(0, SuporteGestorAbertura::count());
        $t = Tarefa::sole();
        $this->assertStringContainsString('LANÇAR NO GESTOR: cliente nº 13 (CONDOMINIO CASTANHEIRAS), assunto 3 PORTÃO / ACESSO', $t->resumo);
        Http::assertSent(fn ($r) => str_contains((string) ($r['text']['body'] ?? ''), "T-{$t->id}"));
    }

    public function test_gravacao_ligada_a_maquina_grava_e_o_cliente_recebe_o_numero(): void
    {
        config(['services.suporte_pedro.gestor_gravar' => true]);
        SuporteConversa::create(['telefone' => '5527988887777', 'gestor_cliente_id' => 13, 'ultima_entrada_em' => now()]);
        $this->chega('o portão G1 não fecha', 'wamid.1');
        $this->responde(['resposta' => 'Vou encaminhar.', 'acao' => 'abrir_atendimento', 'resumo' => 'Portão G1 não fecha', 'destino' => 'gestor', 'assunto_id' => 999]);

        $a = SuporteGestorAbertura::sole();
        $this->assertSame([13, 3, 3, 'pendente'], [$a->gestor_cliente_id, $a->assunto_id, $a->empresa_id, $a->situacao], 'assunto fora da lista dá lugar ao mais usado');
        $this->assertSame(0, Tarefa::count());

        // O mesmo portão G1 foi concluído em 06/10 (nº 782): dentro da garantia, reabre em vez de abrir outro (21/10/2026).
        $this->assertSame(['reabrir', '782'], [$a->modo, $a->atendimento_numero]);

        $this->runner();
        $lista = $this->postJson(route('suporte.runner.aberturas'), ['maquina' => 'wsl'])->assertOk()->json('aberturas');
        $this->assertSame([$a->id, 13, 3, 'reabrir', '782'], [$lista[0]['id'], $lista[0]['cliente_id'], $lista[0]['assunto_id'], $lista[0]['modo'], $lista[0]['atendimento']]);
        $this->postJson(route('suporte.runner.aberturas'), ['maquina' => 'wsl'])->assertJsonCount(0, 'aberturas');
        $this->postJson(route('suporte.runner.abertura-feita', $a->id), ['maquina' => 'wsl', 'numero' => '782', 'modo' => 'reabrir'])->assertOk();

        $this->assertSame('782', SuporteConversa::sole()->gestor_atendimento);
        Http::assertSent(fn ($r) => ($r['text']['body'] ?? '') === 'Reabri o atendimento nº 782, do mesmo assunto. A equipe técnica retorna por aqui.');
        $this->assertSame(1, Notificacao::where('destinatario_id', $this->rossini->id)->where('titulo', 'like', 'Gestor nº 782%')->count());
    }

    public function test_abertura_sem_maquina_cai_para_o_quadro(): void
    {
        config(['services.suporte_pedro.gestor_gravar' => true]);
        SuporteConversa::create(['telefone' => '5527988887777', 'gestor_cliente_id' => 13, 'ultima_entrada_em' => now()]);
        $this->chega('o portão G1 não fecha', 'wamid.1');
        $this->responde(['resposta' => 'Vou encaminhar.', 'acao' => 'abrir_atendimento', 'resumo' => 'Portão G1 não fecha', 'destino' => 'gestor', 'assunto_id' => 3]);
        Carbon::setTestNow(now()->addMinutes(3));
        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->assertSame('falhou', SuporteGestorAbertura::sole()->situacao);
        $t = Tarefa::sole();
        $this->assertStringContainsString('não foi possível abrir no Gestor', $t->resumo);
        Http::assertSent(fn ($r) => ($r['text']['body'] ?? '') === "Seu atendimento é o nº T-{$t->id}. A equipe técnica retorna por aqui.");
    }

    public function test_so_a_conta_do_pedro_manda_o_retrato(): void
    {
        $outro = User::factory()->create();
        CentralIaRunner::create(['user_id' => $outro->id, 'maquina' => 'mac', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($outro, ['central-ia-runner']);
        $this->postJson(route('suporte.runner.gestor'), ['maquina' => 'mac', 'lido_em' => now()->toIso8601String(), 'clientes' => [], 'atendimentos' => []])->assertForbidden();
        $this->postJson(route('suporte.runner.aberturas'), ['maquina' => 'mac'])->assertForbidden();
    }

    /** Antes de abrir, conferir (Felipe, 21/10/2026): aberto do mesmo assunto vincula; concluído na garantia reabre; fora dela, novo. */
    public function test_modo_no_gestor_confere_aberto_garantia_e_numero_citado(): void
    {
        $at = app(\App\Services\Suporte\Atendimento::class);
        $this->assertSame(['modo' => 'reabrir', 'numero' => '782'], $at->modoNoGestor(13, 3), 'concluído há 15 dias, mesmo assunto');
        $this->assertSame(['modo' => 'novo', 'numero' => null], $at->modoNoGestor(13, 27), 'outro assunto');
        config(['services.suporte_pedro.garantia_dias' => 10]);
        $this->assertSame(['modo' => 'novo', 'numero' => null], $at->modoNoGestor(13, 3), 'fora da garantia');
        $this->assertSame(['modo' => 'reabrir', 'numero' => '782'], $at->modoNoGestor(13, 27, '782'), 'o nº citado vale, se é do cliente');
        $this->assertSame(['modo' => 'novo', 'numero' => null], $at->modoNoGestor(20, 27, '782'), 'nº de outro cliente não vale');
    }
}
