<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\SuporteConversa;
use App\Models\SuporteGestorContato;
use App\Models\User;
use App\Services\Suporte\ContatosDoGestor;
use App\Services\Suporte\DiretorioGestor;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A pessoa identificada ao Pedro vira contato do cliente no Gestor, e o contato a identifica depois (T-478 com a T-479 do Gestor). */
class PedroContatosDoGestorTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:00', 'America/Sao_Paulo'));
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 'segredo', 'verify_token' => 'v', 'graph_version' => 'v20.0']]);
        config(['services.suporte_pedro.conta' => 'felipe@teste.local', 'services.suporte_pedro.responsaveis' => ['rossini@teste.local'], 'services.suporte_pedro.gestor_gravar' => true]);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.out.'.uniqid('', true)]]])]);
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['email' => 'felipe@teste.local']);
        User::factory()->create(['email' => 'rossini@teste.local']);
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
    }

    /** O retrato do Gestor; sem $contatos, como hoje na produção (o Gestor ainda sem a tabela). */
    private function retrato(?array $contatos13 = null, ?array $contatos20 = null): void
    {
        $cliente = fn (int $id, string $nome, ?array $contatos) => ['id' => $id, 'nome' => $nome, 'fantasia' => $nome, 'razao' => $nome, 'cidade' => 'VILA VELHA',
            'empresa_id' => 3, 'empresa' => 'INVEST', 'telefones' => ['(27) 3389-4253']] + ($contatos === null ? [] : ['contatos' => $contatos]);
        $this->runner();
        $this->postJson(route('suporte.runner.gestor'), ['maquina' => 'wsl', 'lido_em' => now()->toIso8601String(),
            'clientes' => [$cliente(13, 'CONDOMINIO CASTANHEIRAS', $contatos13), $cliente(20, 'CONDOMINIO SOLAR', $contatos20)],
            'atendimentos' => [], 'assuntos' => [['id' => 3, 'nome' => 'PORTÃO / ACESSO', 'tipo' => 'SERVICO', 'categoria' => 'CONTOLE DE ACESSO', 'empresa_id' => 3]],
        ])->assertOk();
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

    private function responde(array $bloco): void
    {
        $this->runner();
        $job = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->json();
        $this->postJson(route('suporte.runner.resposta', $job['id']), ['maquina' => 'wsl', 'texto' => "<<<PEDRO\n".json_encode($bloco, JSON_UNESCAPED_UNICODE)."\nPEDRO>>>"])->assertOk();
    }

    /** Os casos combinados com a central do Gestor em 26/10/2026: a chave tem de sair igual à de lá, sempre com 10 caracteres. */
    public function test_chave_do_telefone_igual_a_do_gestor(): void
    {
        $this->assertSame('2799998888', ContatosDoGestor::chave('5527999998888'));
        $this->assertSame('2799998888', ContatosDoGestor::chave('552799998888'));
        $this->assertSame('2733334444', ContatosDoGestor::chave('552733334444'));
        $this->assertSame('2799998888', ContatosDoGestor::chave('+55 (27) 99999-8888'));
        foreach (['14155552671', '27999998888', '5527999', '55279999988881', ''] as $n) {
            $this->assertNull(ContatosDoGestor::chave($n), "{$n} não grava");
        }
    }

    public function test_quem_se_identifica_entra_na_fila_uma_vez_so(): void
    {
        $this->retrato();
        SuporteConversa::create(['telefone' => '5527988887777', 'gestor_cliente_id' => 13, 'ultima_entrada_em' => now()]);
        $this->chega('o portão não fecha', 'wamid.1');
        $this->responde(['resposta' => 'Obrigado, João!', 'acao' => 'responder', 'solicitante' => ['nome' => 'João', 'funcao' => 'porteiro']]);

        $x = SuporteGestorContato::sole();
        $this->assertSame([13, 'João', 'porteiro', '5527988887777', '2788887777', 'pendente'],
            [$x->gestor_cliente_id, $x->nome, $x->papel, $x->telefone, $x->telefone_chave, $x->situacao]);

        // O Pedro volta com o problema; a mesma pessoa não entra de novo.
        $this->responde(['resposta' => 'João, o portão está parado aberto ou fechado?', 'acao' => 'responder', 'solicitante' => ['nome' => 'João', 'funcao' => 'porteiro']]);
        $this->assertSame(1, SuporteGestorContato::count());

        // Outra pessoa no mesmo telefone entra (no Gestor, a linha que já existe não muda de nome).
        $this->chega('aqui é a Maria, síndica', 'wamid.2');
        $this->responde(['resposta' => 'Olá, Maria.', 'acao' => 'responder', 'solicitante' => ['nome' => 'Maria', 'funcao' => 'síndica']]);
        $this->assertSame(['João', 'Maria'], SuporteGestorContato::orderBy('id')->pluck('nome')->all());
    }

    public function test_pre_cliente_nao_vira_contato(): void
    {
        $this->retrato();
        SuporteConversa::create(['telefone' => '5527988887777', 'gestor_cliente_id' => -40, 'ultima_entrada_em' => now()]);
        $this->chega('preciso de um orçamento', 'wamid.1');
        $this->responde(['resposta' => 'Obrigado, Ana!', 'acao' => 'responder', 'solicitante' => ['nome' => 'Ana', 'funcao' => null]]);
        $this->assertSame(0, SuporteGestorContato::count(), 'pré-cliente não tem contato no Gestor');
    }

    public function test_fila_espera_o_gestor_ter_a_tabela_e_depois_sai_para_a_maquina(): void
    {
        $this->retrato();
        $c = SuporteConversa::create(['telefone' => '5527988887777', 'gestor_cliente_id' => 13, 'solicitante_nome' => 'João', 'solicitante_funcao' => 'porteiro', 'ultima_entrada_em' => now()]);
        app(ContatosDoGestor::class)->registrar($c);

        $this->runner();
        $this->postJson(route('suporte.runner.contatos'), ['maquina' => 'wsl'])->assertOk()->assertExactJson(['contatos' => []]);
        $this->assertSame('pendente', SuporteGestorContato::sole()->situacao, 'sem a tabela no Gestor, espera');

        $this->retrato([]);
        $this->runner();
        $lista = $this->postJson(route('suporte.runner.contatos'), ['maquina' => 'wsl'])->assertOk()->json('contatos');
        $this->assertSame([['id' => SuporteGestorContato::sole()->id, 'cliente_id' => 13, 'nome' => 'João', 'papel' => 'porteiro', 'telefone' => '5527988887777', 'telefone_chave' => '2788887777']], $lista);
        $this->assertSame('reservado', SuporteGestorContato::sole()->situacao);

        $this->postJson(route('suporte.runner.contato-feito', $lista[0]['id']), ['maquina' => 'wsl', 'ok' => true])->assertOk();
        $this->assertSame('feito', SuporteGestorContato::sole()->situacao);
        $this->postJson(route('suporte.runner.contato-feito', $lista[0]['id']), ['maquina' => 'wsl', 'ok' => true])->assertNotFound();
    }

    public function test_falha_no_gestor_fica_registrada(): void
    {
        $this->retrato([]);
        $c = SuporteConversa::create(['telefone' => '5527988887777', 'gestor_cliente_id' => 13, 'solicitante_nome' => 'João', 'ultima_entrada_em' => now()]);
        $x = app(ContatosDoGestor::class)->registrar($c);
        $this->runner();
        $this->postJson(route('suporte.runner.contatos'), ['maquina' => 'wsl'])->assertOk();
        $this->postJson(route('suporte.runner.contato-feito', $x->id), ['maquina' => 'wsl', 'ok' => false, 'erro' => 'cliente inexistente ou excluído no Gestor'])->assertOk();
        $this->assertSame(['falhou', 'cliente inexistente ou excluído no Gestor'], [$x->fresh()->situacao, $x->fresh()->erro]);
    }

    public function test_so_a_conta_do_pedro_pega_a_fila(): void
    {
        $outro = User::factory()->create();
        CentralIaRunner::create(['user_id' => $outro->id, 'maquina' => 'outra', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($outro, ['central-ia-runner']);
        $this->postJson(route('suporte.runner.contatos'), ['maquina' => 'outra'])->assertForbidden();
    }

    public function test_contato_do_gestor_identifica_a_conversa_pelo_numero(): void
    {
        // A função corrigida à mão no Gestor é a que vale.
        $this->retrato([['nome' => 'João Carlos', 'papel' => 'porteiro-chefe', 'telefone' => '+5527988887777', 'chave' => '2788887777']]);
        $this->chega('bom dia', 'wamid.1');
        $this->runner();
        $prompt = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->json('prompt');

        $c = SuporteConversa::sole();
        $this->assertTrue($c->identificada());
        $this->assertSame([13, 'João Carlos', 'porteiro-chefe'], [$c->gestor_cliente_id, $c->solicitante_nome, $c->solicitante_funcao]);
        $this->assertStringContainsString('QUEM ESCREVE: João Carlos (porteiro-chefe)', $prompt);
    }

    public function test_contato_de_dois_clientes_continua_perguntando(): void
    {
        $joao = [['nome' => 'João', 'papel' => 'porteiro', 'telefone' => '+5527988887777', 'chave' => '2788887777']];
        $this->retrato($joao, $joao);
        $this->chega('bom dia', 'wamid.1');
        $this->runner();
        $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->assertOk();
        $this->assertFalse(SuporteConversa::sole()->identificada(), 'o porteiro de dois condomínios diz de qual fala');
    }

    public function test_contato_nao_libera_pedido_sensivel(): void
    {
        $this->retrato([['nome' => 'João', 'papel' => 'porteiro', 'telefone' => '+5527988887777', 'chave' => '2788887777']]);
        $this->assertSame([], app(DiretorioGestor::class)->porTelefone('5527988887777'), 'contato não é telefone do cadastro');
        $this->assertCount(1, app(DiretorioGestor::class)->contatoPorChave('2788887777'));
    }
}
