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
        $this->responde(['resposta' => 'Certo! E com quem eu falo?', 'acao' => 'responder', 'cliente' => 'gestor:13']);
        $this->assertSame(13, SuporteConversa::sole()->gestor_cliente_id);
        $this->assertFalse(SuporteConversa::sole()->identificada(), 'falta o nome da pessoa (T-478)');

        // Com o nome, a resposta do pedido de identificação não vai: o Pedro volta na hora com o contexto inteiro.
        $this->chega('sou o João, porteiro', 'wamid.3');
        $this->responde(['resposta' => 'Obrigado, João!', 'acao' => 'responder', 'solicitante' => ['nome' => 'João', 'funcao' => 'porteiro']]);
        $c = SuporteConversa::sole();
        $this->assertSame(['João', 'porteiro'], [$c->solicitante_nome, $c->solicitante_funcao]);
        $this->assertNotNull($c->identificada_em);
        Http::assertNotSent(fn ($r) => ($r['text']['body'] ?? '') === 'Obrigado, João!');
        $this->assertSame(1, SuportePedido::where('situacao', 'pendente')->count());

        $this->runner();
        $prompt = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->json('prompt');
        $this->assertStringContainsString('QUEM ESCREVE: João (porteiro)', $prompt);
        $this->assertStringContainsString('CONFIRMADO (Gestor', $prompt);
        // Confirmado só pelo nome, de um número fora do cadastro: nada do histórico (21/10/2026).
        $this->assertStringContainsString('POR SEGURANÇA, o número que escreve NÃO é um telefone do cadastro', $prompt);
        $this->assertStringNotContainsString('Gestor nº 782', $prompt);
        $this->assertStringContainsString("- 3: PORTÃO / ACESSO", $prompt);
        $this->assertStringNotContainsString('MANUTENÇÃO CORRETIVA', $prompt, 'assunto de outra empresa não entra');
    }

    /** T-488: com a gravação desligada, nada vai ao quadro; um atendente lança no Gestor. */
    public function test_gravacao_desligada_vai_a_um_atendente_com_o_que_lancar_no_gestor(): void
    {
        config(['services.suporte_pedro.gestor_gravar' => false]);
        SuporteConversa::create(['telefone' => '5527988887777', 'gestor_cliente_id' => 13, 'solicitante_nome' => 'Maria', 'ultima_entrada_em' => now()]);
        $this->chega('o portão G1 não fecha', 'wamid.1');
        $this->responde(['resposta' => 'Vou encaminhar para a equipe técnica.', 'acao' => 'abrir_atendimento', 'resumo' => 'Portão G1 não fecha', 'destino' => 'gestor', 'assunto_id' => 3]);

        $this->assertSame(0, SuporteGestorAbertura::count());
        $this->assertSame(0, Tarefa::count(), 'nada no quadro do Matriz');
        $c = SuporteConversa::sole();
        $this->assertTrue($c->comHumano());
        $this->assertSame('LANÇAR NO GESTOR: cliente nº 13 (CONDOMINIO CASTANHEIRAS), assunto 3 PORTÃO / ACESSO. Solicitante: Maria. Portão G1 não fecha (gravação no Gestor desligada)', $c->fila_motivo);
        Http::assertNotSent(fn ($r) => str_contains((string) ($r['text']['body'] ?? ''), 'T-'));
    }

    /** T-488: o cliente confirmado pelo cadastro do Matriz usa o cliente dele no Gestor, e o chamado vai para lá. */
    public function test_cliente_do_matriz_ligado_ao_gestor_abre_no_gestor(): void
    {
        config(['services.suporte_pedro.gestor_gravar' => true]);
        $matriz = Cliente::sole();
        $matriz->forceFill(['gestor_cliente_id' => 13])->save();
        SuporteConversa::create(['telefone' => '5527988887777', 'solicitante_nome' => 'Maria', 'ultima_entrada_em' => now()]);
        $this->chega('sou do parque das castanheiras, o portão G1 não fecha', 'wamid.1');
        $this->responde(['resposta' => 'Certo, Maria.', 'acao' => 'abrir_atendimento', 'resumo' => 'Portão G1 não fecha', 'cliente' => 'matriz:'.$matriz->id, 'assunto_id' => 3]);

        $c = SuporteConversa::sole();
        $this->assertSame([$matriz->id, 13], [$c->cliente_id, $c->gestor_cliente_id]);
        $this->assertSame([13, 3, 'pendente'], [SuporteGestorAbertura::sole()->gestor_cliente_id, SuporteGestorAbertura::sole()->assunto_id, SuporteGestorAbertura::sole()->situacao]);
        $this->assertSame(0, Tarefa::count());
        $this->assertFalse($c->comHumano(), 'o número do Gestor chega em seguida, pelo Pedro');
    }

    /** T-488: o Pedro atende clientes da Invest, da GW e da DELTA; os de outra empresa do Gestor não são reconhecidos. */
    public function test_cliente_de_outra_empresa_do_gestor_nao_e_reconhecido(): void
    {
        $this->runner();
        $this->postJson(route('suporte.runner.gestor'), ['maquina' => 'wsl', 'lido_em' => now()->toIso8601String(),
            'clientes' => [
                ['id' => 13, 'nome' => 'CONDOMINIO CASTANHEIRAS', 'fantasia' => '', 'razao' => '', 'cidade' => 'VILA VELHA', 'empresa_id' => 3, 'empresa' => 'INVEST', 'telefones' => ['(27) 98888-7777']],
                ['id' => 22, 'nome' => 'LEROY MERLIN', 'fantasia' => '', 'razao' => '', 'cidade' => 'SERRA', 'empresa_id' => 1, 'empresa' => 'DELTA', 'telefones' => ['(27) 98888-7777']],
                ['id' => -65, 'nome' => 'ELABORE HUB', 'fantasia' => '', 'razao' => '', 'cidade' => 'VITÓRIA', 'empresa_id' => 4, 'empresa' => 'ALFA', 'telefones' => ['(27) 98888-7777']],
                ['id' => 37, 'nome' => 'JEEP GUARAPARI', 'fantasia' => '', 'razao' => '', 'cidade' => 'GUARAPARI', 'telefones' => ['(27) 98888-7777']],
            ], 'atendimentos' => []])->assertOk();
        $d = app(\App\Services\Suporte\DiretorioGestor::class);
        $this->assertSame([13, 22, 37], array_column($d->porTelefone('5527988887777'), 'id'), 'sem empresa no retrato continua');
        $this->assertSame([], $d->porNome('aqui é da elabore hub'));
        $this->assertSame([], $d->buscarClientes('abrir chamado para a Elabore Hub'));
    }

    /** 23/10/2026: com os pré-clientes, a validação curinga levava ~100 s e o runner desistia; agora é item a item. */
    public function test_retrato_grande_valida_item_a_item_com_o_caminho_do_erro(): void
    {
        $this->runner();
        $clientes = array_map(fn ($i) => ['id' => $i % 2 ? $i : -$i, 'nome' => "Cliente {$i}", 'cidade' => 'Vitória', 'empresa' => 'GW', 'telefones' => [], 'pre' => $i % 2 === 0], range(1, 4000));
        $t = microtime(true);
        $this->postJson(route('suporte.runner.gestor'), ['maquina' => 'wsl', 'lido_em' => now()->toIso8601String(), 'clientes' => $clientes, 'atendimentos' => []])
            ->assertOk()->assertJsonPath('clientes', 4000);
        $this->assertLessThan(15, microtime(true) - $t, 'o runner espera 20 s');

        $clientes[7]['nome'] = ['não é texto'];
        $this->postJson(route('suporte.runner.gestor'), ['maquina' => 'wsl', 'lido_em' => now()->toIso8601String(), 'clientes' => $clientes, 'atendimentos' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('clientes.7.nome');
    }

    /** pedro-dia-do-tecnico, tarefa 1.1: as visitas dos técnicos chegam no retrato e ficam para o Pedro. */
    public function test_retrato_leva_as_visitas_dos_tecnicos(): void
    {
        $this->runner();
        $visita = ['id' => 1185, 'atendimento_id' => 925, 'tecnico_id' => 9, 'inicio' => '2026-10-23 08:00', 'fim' => '2026-10-23 10:00',
            'situacao' => 'agendado', 'observacoes' => 'levar escada', 'adicionais' => [21]];
        $corpo = ['maquina' => 'wsl', 'lido_em' => now()->toIso8601String(), 'clientes' => [], 'atendimentos' => [], 'visitas' => [$visita]];
        $this->postJson(route('suporte.runner.gestor'), $corpo)->assertOk();
        $this->assertEquals([$visita], app(\App\Services\Suporte\DiretorioGestor::class)->retrato()['visitas']);

        $corpo['visitas'][0]['inicio'] = '23/10/2026 08:00';
        $this->postJson(route('suporte.runner.gestor'), $corpo)->assertUnprocessable()->assertJsonValidationErrors('visitas.0.inicio');
        // Retrato de máquina antiga, sem a lista: continua valendo, sem visitas.
        unset($corpo['visitas']);
        $this->postJson(route('suporte.runner.gestor'), $corpo)->assertOk();
        $this->assertSame([], app(\App\Services\Suporte\DiretorioGestor::class)->retrato()['visitas']);
    }

    public function test_gravacao_ligada_a_maquina_grava_e_o_cliente_recebe_o_numero(): void
    {
        config(['services.suporte_pedro.gestor_gravar' => true]);
        SuporteConversa::create(['telefone' => '5527988887777', 'gestor_cliente_id' => 13, 'solicitante_nome' => 'Maria', 'ultima_entrada_em' => now()]);
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
        Http::assertSent(fn ($r) => ($r['text']['body'] ?? '') === 'Esse problema já tinha um chamado, o nº 782, e eu o reabri para que a equipe analise novamente. A equipe técnica retornará o contato por aqui.');
        $this->assertSame(1, Notificacao::where('destinatario_id', $this->rossini->id)->where('titulo', 'like', 'Gestor nº 782%')->count());
    }

    /** T-488: a máquina não gravou no Gestor; antes virava tarefa e o cliente ouvia "seu chamado é T-N". */
    public function test_abertura_sem_maquina_vai_a_um_atendente(): void
    {
        config(['services.suporte_pedro.gestor_gravar' => true]);
        SuporteConversa::create(['telefone' => '5527988887777', 'gestor_cliente_id' => 13, 'solicitante_nome' => 'Maria', 'ultima_entrada_em' => now()]);
        $this->chega('o portão G1 não fecha', 'wamid.1');
        $this->responde(['resposta' => 'Vou encaminhar.', 'acao' => 'abrir_atendimento', 'resumo' => 'Portão G1 não fecha', 'destino' => 'gestor', 'assunto_id' => 3]);
        Carbon::setTestNow(now()->addMinutes(3));
        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->assertSame('falhou', SuporteGestorAbertura::sole()->situacao);
        $this->assertSame(0, Tarefa::count(), 'nada no quadro do Matriz');
        $c = SuporteConversa::sole();
        $this->assertTrue($c->comHumano());
        $this->assertSame('LANÇAR NO GESTOR: cliente nº 13 (CONDOMINIO CASTANHEIRAS), assunto 3 PORTÃO / ACESSO. Solicitante: Maria. Portão G1 não fecha (o Gestor não gravou: nenhuma máquina gravou no Gestor)', $c->fila_motivo);
        Http::assertSent(fn ($r) => ($r['text']['body'] ?? '') === 'Já chamei um colega da equipe, que continua com você por aqui.');
        Http::assertNotSent(fn ($r) => str_contains((string) ($r['text']['body'] ?? ''), 'T-'));
    }

    /** Abertura de quem é da casa, como o registrarPorDentro grava (T-494: o caso do Wallas). */
    private function aberturaDoWallas(string $situacao = 'pendente', array $mais = []): SuporteGestorAbertura
    {
        $c = SuporteConversa::create(['telefone' => '5527998845397', 'ultima_entrada_em' => now()]);

        return SuporteGestorAbertura::create(['conversa_id' => $c->id, 'gestor_cliente_id' => 13, 'assunto_id' => 3, 'empresa_id' => 3, 'modo' => 'novo',
            'pedido_por' => 'Wallas', 'descricao' => "Portão G1 não fecha\n\n(Registrado pelo Pedro a pedido de Wallas.)", 'solicitante' => 'Wallas',
            'telefone' => '5527998845397', 'situacao' => $situacao] + $mais);
    }

    /** T-494: a máquina reservou entre a leitura e a gravação do vencimento; o aviso de falha não pode sair. */
    public function test_abertura_reservada_no_meio_do_vencimento_nao_vira_falha(): void
    {
        $a = $this->aberturaDoWallas();
        Carbon::setTestNow(now()->addMinutes(3));
        // A máquina ganha a corrida: reserva logo depois de o vencimento ler a abertura como pendente.
        SuporteGestorAbertura::retrieved(function (SuporteGestorAbertura $lida) {
            SuporteGestorAbertura::query()->whereKey($lida->id)->where('situacao', 'pendente')->update(['situacao' => 'reservado', 'reservado_em' => now()]);
        });
        $this->artisan('suporte:pedro')->assertSuccessful();

        $this->assertSame(['reservado', null], [$a->fresh()->situacao, $a->fresh()->erro]);
        Http::assertNotSent(fn ($r) => str_contains((string) ($r['text']['body'] ?? ''), 'Não consegui gravar'));

        // E o resultado da máquina ainda é aceito.
        $this->runner();
        $this->postJson(route('suporte.runner.abertura-feita', $a->id), ['maquina' => 'wsl', 'numero' => '824', 'modo' => 'novo'])->assertOk();
        $this->assertSame(['feito', '824'], [$a->fresh()->situacao, $a->fresh()->numero]);
    }

    /** T-494: sem máquina nenhuma, a falha é definitiva — a abertura não volta a ser oferecida. */
    public function test_abertura_que_falhou_por_falta_de_maquina_nao_sai_mais_para_a_maquina(): void
    {
        $a = $this->aberturaDoWallas();
        Carbon::setTestNow(now()->addMinutes(3));
        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->assertSame(['falhou', 'nenhuma máquina gravou no Gestor'], [$a->fresh()->situacao, $a->fresh()->erro]);
        Http::assertSent(fn ($r) => str_starts_with((string) ($r['text']['body'] ?? ''), 'Não consegui gravar no Gestor (nenhuma máquina gravou no Gestor). Lance à mão'));

        $this->runner();
        $this->postJson(route('suporte.runner.aberturas'), ['maquina' => 'wsl'])->assertJsonCount(0, 'aberturas');
        $this->postJson(route('suporte.runner.abertura-feita', $a->id), ['maquina' => 'wsl', 'numero' => '824'])->assertNotFound();
    }

    /** T-494: reservada e sem resposta, PODE ter sido gravada: nada de "lance à mão", e sim conferir antes. */
    public function test_abertura_reservada_sem_retorno_avisa_para_conferir_e_aceita_o_numero_atrasado(): void
    {
        $a = $this->aberturaDoWallas('reservado', ['reservado_em' => now()]);
        Carbon::setTestNow(now()->addMinutes(9));
        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->assertNull($a->fresh()->erro, 'antes de 10 min, espera');

        Carbon::setTestNow(now()->addMinutes(2));
        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->assertSame(['reservado', 'sem confirmação do Gestor desde 10:00'], [$a->fresh()->situacao, $a->fresh()->erro]);
        $aviso = 'O Gestor ainda não confirmou o atendimento que comecei a gravar às 10:00. Ele pode já estar lá: procure no Gestor antes de lançar à mão '
            .'(cliente nº 13 (CONDOMINIO CASTANHEIRAS), assunto 3 PORTÃO / ACESSO. Portão G1 não fecha). Quando o número chegar, eu aviso aqui.';
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => ($r['text']['body'] ?? '') === $aviso);
        Http::assertNotSent(fn ($r) => str_contains((string) ($r['text']['body'] ?? ''), 'Lance à mão'));
        $this->assertFalse(SuporteConversa::sole()->comHumano(), 'quem é da casa não vai à fila');

        $this->runner();
        $this->postJson(route('suporte.runner.abertura-feita', $a->id), ['maquina' => 'wsl', 'numero' => '824', 'modo' => 'novo'])->assertOk();
        $this->assertSame(['feito', '824', null], [$a->fresh()->situacao, $a->fresh()->numero, $a->fresh()->erro]);
        Http::assertSent(fn ($r) => str_ends_with((string) ($r['text']['body'] ?? ''), "\nA confirmação chegou depois do aviso de demora: se você já lançou à mão, cancele um dos dois no Gestor."));
        $this->assertSame(1, Notificacao::where('titulo', 'like', 'Gestor nº 824 (gravado depois do aviso de demora%')->count());
    }

    /** T-494, do cliente: vai a um atendente, com a ressalva no começo do motivo da fila (que corta em 250). */
    public function test_abertura_de_cliente_sem_retorno_vai_ao_atendente_para_conferir_antes(): void
    {
        config(['services.suporte_pedro.gestor_gravar' => true]);
        SuporteConversa::create(['telefone' => '5527988887777', 'gestor_cliente_id' => 13, 'solicitante_nome' => 'Maria', 'ultima_entrada_em' => now()]);
        $this->chega('o portão G1 não fecha', 'wamid.1');
        $this->responde(['resposta' => 'Vou encaminhar.', 'acao' => 'abrir_atendimento', 'resumo' => 'Portão G1 não fecha', 'destino' => 'gestor', 'assunto_id' => 3]);
        $this->runner();
        $this->postJson(route('suporte.runner.aberturas'), ['maquina' => 'wsl'])->assertJsonCount(1, 'aberturas');

        Carbon::setTestNow(now()->addMinutes(11));
        $this->artisan('suporte:pedro')->assertSuccessful();
        $c = SuporteConversa::sole();
        $this->assertTrue($c->comHumano());
        $this->assertStringStartsWith('CONFERIR NO GESTOR ANTES DE LANÇAR (gravação sem confirmação desde 10:00; pode já existir): cliente nº 13 (CONDOMINIO CASTANHEIRAS), assunto 3 PORTÃO / ACESSO.', $c->fila_motivo);
        Http::assertSent(fn ($r) => ($r['text']['body'] ?? '') === 'Já chamei um colega da equipe, que continua com você por aqui.');
        $this->assertSame('reservado', SuporteGestorAbertura::sole()->situacao);
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

    /** Do telefone do cadastro do cliente, o histórico de atendimentos aparece. */
    public function test_numero_do_cadastro_ve_os_atendimentos_do_cliente(): void
    {
        SuporteConversa::create(['telefone' => '552733894253', 'gestor_cliente_id' => 13, 'solicitante_nome' => 'Maria', 'ultima_entrada_em' => now()]);
        $this->chega('e o portão?', 'wamid.1', '552733894253');
        $this->runner();
        $prompt = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->json('prompt');
        $this->assertStringContainsString('Gestor nº 782: Portão de saída G1 não fecha — etapa: concluido', $prompt);
        $this->assertStringNotContainsString('POR SEGURANÇA, o número', $prompt);
    }
}
