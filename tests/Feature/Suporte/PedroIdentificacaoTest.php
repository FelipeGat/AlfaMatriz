<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\Cliente;
use App\Models\ClienteTelefone;
use App\Models\SuporteConversa;
use App\Models\SuporteGestorAbertura;
use App\Models\SuporteNota;
use App\Models\SuportePedido;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\Suporte\Atendimento;
use App\Services\Suporte\DiretorioGestor;
use App\Services\Suporte\Pedro;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T-478 (decisão do dono em 21/10/2026): o Pedro pede o nome da pessoa e o da empresa
 * ANTES de qualquer ajuda. A trava mora no código — o pedido à IA sai sem roteiro nem
 * histórico, e o Pedro::concluir recusa abrir chamado — e não só no texto do prompt.
 */
class PedroIdentificacaoTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;

    private Cliente $academia;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00', 'America/Sao_Paulo')); // terça
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 'segredo', 'verify_token' => 'v', 'graph_version' => 'v20.0']]);
        config(['services.suporte_pedro.conta' => 'felipe@teste.local', 'services.suporte_pedro.responsaveis' => ['rossini@teste.local']]);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.out'.uniqid('', true)]]])]);
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['email' => 'felipe@teste.local']);
        User::factory()->create(['name' => 'Rossini', 'email' => 'rossini@teste.local']);
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        $this->academia = Cliente::create(['nome' => 'Academia Boa Forma', 'ativo' => true]);
        ClienteTelefone::create(['cliente_id' => $this->academia->id, 'telefone' => '(27) 99999-8888', 'principal' => true]);
    }

    private function chega(string $texto, string $id, string $de = '5527911112222'): void
    {
        $valor = ['metadata' => ['phone_number_id' => '111222'], 'contacts' => [['wa_id' => $de, 'profile' => ['name' => 'Recepção']]],
            'messages' => [['from' => $de, 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $texto]]]];
        $corpo = json_encode(['entry' => [['changes' => [['field' => 'messages', 'value' => $valor]]]]]);
        $this->call('POST', route('suporte.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $corpo, 'segredo')], $corpo)->assertOk();
    }

    /** @return string o prompt que a IA recebeu */
    private function responde(array $bloco): string
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $job = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->assertOk()->json();
        $this->postJson(route('suporte.runner.resposta', $job['id']), ['maquina' => 'wsl',
            'texto' => "<<<PEDRO\n".json_encode($bloco, JSON_UNESCAPED_UNICODE)."\nPEDRO>>>"])->assertOk();

        return (string) $job['prompt'];
    }

    public function test_sem_identificacao_o_pedido_so_pede_a_identificacao(): void
    {
        $this->chega('o portão não abre, me ajuda', 'wamid.1');
        $prompt = $this->responde(['resposta' => 'Entendi! Para eu abrir o seu chamado, me diga seu nome e o do condomínio.', 'acao' => 'responder']);

        foreach (['IDENTIFICAÇÃO PRIMEIRO', 'JEITO DE ESCREVER', 'SEGURANÇA (vale antes da identificação)', 'DADOS INTERNOS', 'QUEM ESCREVE É SEMPRE UM CLIENTE', '"solicitante"'] as $tem) {
            $this->assertStringContainsString($tem, $prompt);
        }
        foreach (['## ROTEIROS', 'DIAGNÓSTICO GUIADO', '## ATENDIMENTOS', '## ASSUNTOS', 'abrir_atendimento', 'BASE DO SUPORTE', 'REGISTRO:'] as $nao) {
            $this->assertStringNotContainsString($nao, $prompt);
        }
        Http::assertSent(fn ($r) => str_starts_with((string) ($r['text']['body'] ?? ''), 'Entendi!'));
    }

    public function test_sem_identificacao_nao_abre_chamado_nem_registro_e_chama_um_colega(): void
    {
        $this->chega('o portão não abre', 'wamid.1');
        // A IA desobedece e tenta abrir, com registro para a base: o código não deixa.
        $this->responde(['resposta' => 'Vou encaminhar para a equipe técnica.', 'acao' => 'abrir_atendimento', 'resumo' => 'Portão não abre',
            'registro' => ['titulo' => 'Portão não abre', 'relato' => 'não abre', 'situacao' => 'em_curso']]);

        $this->assertSame(0, Tarefa::count());
        $this->assertSame(0, SuporteGestorAbertura::count());
        $this->assertSame(0, SuporteNota::count());
        $c = SuporteConversa::sole();
        $this->assertSame('humano', $c->situacao);
        $this->assertNotNull($c->fila_desde);
        $this->assertStringStartsWith('sem identificação:', (string) $c->fila_motivo);
        Http::assertSent(fn ($r) => str_contains((string) ($r['text']['body'] ?? ''), 'Já chamei um colega da equipe'));
    }

    public function test_financeiro_sem_identificacao_tambem_vai_a_um_colega(): void
    {
        $this->chega('preciso do boleto', 'wamid.1');
        $this->responde(['resposta' => 'Vou passar para o financeiro.', 'acao' => 'encaminhar_setor', 'setor' => 'financeiro', 'resumo' => 'boleto']);

        $this->assertSame(0, Tarefa::count());
        $this->assertSame('humano', SuporteConversa::sole()->situacao);
    }

    public function test_numero_do_cadastro_so_diz_o_nome_e_a_conversa_continua_sozinha(): void
    {
        $this->chega('A catraca parou de liberar', 'wamid.1', '5527999998888');
        $this->assertSame($this->academia->id, SuporteConversa::sole()->cliente_id, 'o número do cadastro já diz o cliente');
        $prompt = $this->responde(['resposta' => 'Entendi, a catraca parou. Com quem eu falo?', 'acao' => 'responder']);
        $this->assertStringContainsString('pergunte só o nome da pessoa', $prompt);

        $this->chega('Maria, sou a síndica', 'wamid.2', '5527999998888');
        $this->responde(['resposta' => 'Obrigado, Maria!', 'acao' => 'responder', 'solicitante' => ['nome' => 'Maria', 'funcao' => 'síndica']]);
        // A resposta do pedido de identificação não vai; o Pedro volta na hora, com tudo.
        Http::assertNotSent(fn ($r) => ($r['text']['body'] ?? '') === 'Obrigado, Maria!');
        $this->assertTrue(SuporteConversa::sole()->identificada());
        $this->assertSame('identificou', SuportePedido::orderBy('id')->get()[1]->acao);

        $prompt = $this->responde(['resposta' => 'Obrigado, Maria! Vou chamar a equipe técnica para a catraca.', 'acao' => 'abrir_atendimento', 'resumo' => 'Catraca não libera']);
        $this->assertStringContainsString('QUEM ESCREVE: Maria (síndica)', $prompt);
        $this->assertStringContainsString('## ROTEIROS', $prompt);
        $this->assertStringContainsString('## ATENDIMENTOS', $prompt);
        // Sem cliente no Gestor, um atendente lança lá (T-488): nada no quadro, nenhum T-N ao cliente.
        $this->assertSame(0, Tarefa::count());
        $this->assertStringContainsString('Solicitante: Maria (síndica).', SuporteConversa::sole()->fila_motivo);
        Http::assertNotSent(fn ($r) => str_contains((string) ($r['text']['body'] ?? ''), 'T-'));
    }

    /** Revisão da T-478: identificada às 17:59 e a IA responde às 18:01 — a resposta espera, e o Pedro retoma na abertura. */
    public function test_identificou_fora_do_horario_o_pedro_retoma_na_abertura(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 17:59', 'America/Sao_Paulo'));
        $this->chega('Sou a Maria, síndica, a catraca parou', 'wamid.1', '5527999998888');
        Carbon::setTestNow(Carbon::parse('2026-10-20 18:01', 'America/Sao_Paulo'));
        $this->responde(['resposta' => 'Obrigado, Maria!', 'acao' => 'responder', 'solicitante' => ['nome' => 'Maria', 'funcao' => 'síndica']]);

        Http::assertNotSent(fn ($r) => ($r['text']['body'] ?? '') === 'Obrigado, Maria!');
        $this->assertTrue(SuporteConversa::sole()->identificada());
        $this->assertSame(1, SuportePedido::count(), 'fora do horário a volta espera');

        Carbon::setTestNow(Carbon::parse('2026-10-21 07:00', 'America/Sao_Paulo'));
        $this->assertSame(1, app(Pedro::class)->aguardando(), 'na abertura a conversa volta ao Pedro');
        $prompt = $this->responde(['resposta' => 'Bom dia, Maria! Vou ver a catraca com você.', 'acao' => 'responder']);
        $this->assertStringContainsString('QUEM ESCREVE: Maria (síndica)', $prompt);
        Http::assertSent(fn ($r) => ($r['text']['body'] ?? '') === 'Bom dia, Maria! Vou ver a catraca com você.');
    }

    /** Revisão da T-478: telefone de portaria passa de mão em mão; quem diz outro nome passa a ser o solicitante. */
    public function test_outra_pessoa_no_mesmo_telefone_troca_o_solicitante(): void
    {
        $c = SuporteConversa::create(['telefone' => '5527999998888', 'cliente_id' => $this->academia->id, 'situacao' => 'pedro',
            'solicitante_nome' => 'Maria', 'solicitante_funcao' => 'síndica', 'identificada_em' => now()->subWeek()]);
        $this->chega('Aqui é o João, da portaria. O portão travou', 'wamid.1', '5527999998888');
        $prompt = $this->responde(['resposta' => 'Oi, João! Vou abrir o chamado do portão.', 'acao' => 'abrir_atendimento', 'resumo' => 'Portão travado',
            'solicitante' => ['nome' => 'João', 'funcao' => null]]);

        $this->assertStringContainsString('Se a pessoa disser que é OUTRA pessoa', $prompt);
        $c->refresh();
        $this->assertSame('João', $c->solicitante_nome);
        $this->assertNull($c->solicitante_funcao, 'o João não herda a função da Maria');
        $this->assertStringContainsString('Solicitante: João.', $c->fila_motivo);
    }

    public function test_sem_resposta_da_ia_sem_identificacao_nao_abre_chamado(): void
    {
        $this->chega('o portão não abre', 'wamid.1');
        // A máquina pegou o pedido; a IA é que não respondeu (sem máquina, o aviso é outro).
        SuportePedido::query()->update(['situacao' => 'reservado', 'reservado_em' => now()]);
        Carbon::setTestNow(now()->addMinutes(3));
        $this->artisan('suporte:pedro')->assertSuccessful();

        $this->assertSame(0, Tarefa::count());
        $c = SuporteConversa::sole();
        $this->assertTrue($c->comHumano());
        $this->assertNotNull($c->fila_desde, 'a equipe é avisada pela fila');
        Http::assertSent(fn ($r) => ($r['text']['body'] ?? '') === Pedro::FALLBACK);
    }

    public function test_o_atendimento_no_gestor_leva_a_pessoa_identificada(): void
    {
        app(DiretorioGestor::class)->gravar('wsl', now()->toDateTimeString(),
            [['id' => 13, 'nome' => 'CONDOMINIO CASTANHEIRAS', 'fantasia' => 'CONDOMINIO CASTANHEIRAS', 'empresa_id' => 3, 'telefones' => []]], [],
            [['id' => 3, 'nome' => 'PORTÃO / ACESSO', 'tipo' => 'SERVICO', 'categoria' => '', 'empresa_id' => 3]]);
        $c = SuporteConversa::create(['telefone' => '5527911112222', 'nome_perfil' => 'Perfil do Zap', 'gestor_cliente_id' => 13,
            'solicitante_nome' => 'João', 'solicitante_funcao' => 'porteiro']);

        $a = app(Atendimento::class)->pedirNoGestor($c, 'Portão não abre', 3);
        $this->assertSame('João (porteiro)', $a->solicitante);
        $this->assertStringContainsString('João (porteiro), +55 (27) 91111-2222', $a->descricao);
    }
}
