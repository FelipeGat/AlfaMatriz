<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaRunner;
use App\Models\CentralIaTarefa;
use App\Models\CentralIaTelegramVinculo;
use App\Models\Compromisso;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\AgentesService;
use App\Services\CentralIa\ConversaService;
use App\Services\CentralIa\Maquinas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * T-374: a Central é de um time. Qualquer pessoa conecta a própria máquina
 * pela tela, sem artisan no servidor, e nada do runner depende da máquina de
 * uma pessoa.
 */
class MaquinasTest extends TestCase
{
    use BotProprioNosTestes, RefreshDatabase;

    private function pessoa(bool $admin = false): User
    {
        $u = User::factory()->create();
        $u->perfis()->sync(array_filter([Perfil::where('slug', 'central_ia')->value('id'), $admin ? Perfil::where('slug', 'admin')->value('id') : null]));

        return $u->fresh();
    }

    private function codigo(User $u): string
    {
        $comando = $this->actingAs($u)->postJson(route('central-ia.maquinas.instalacao'))->assertOk()->json('comando');
        // O código vai no ambiente do bash, fora do `ps` dos outros usuários.
        $this->assertMatchesRegularExpression('#^curl -fsSL http\S+/central-ia/instalar\.sh \| CENTRAL_IA_CODIGO=[A-Za-z0-9]{40} bash$#', $comando);

        return substr($comando, -45, 40);
    }

    /** O terminal não tem sessão: troca o código como um programa qualquer. */
    private function trocar(string $codigo, string $maquina = 'notebook-ana', bool $nova = false)
    {
        Auth::forgetGuards();

        return $this->withSession([])->postJson(route('central-ia.instalacao.trocar'), ['codigo' => $codigo, 'maquina' => $maquina, 'nova' => $nova]);
    }

    /** Revisão da T-374: dois computadores com o mesmo nome não se derrubam. */
    public function test_maquina_nova_com_nome_repetido_ganha_sufixo_e_a_mesma_maquina_troca_o_proprio_token(): void
    {
        $ana = $this->pessoa();
        $this->trocar($this->codigo($ana), 'MacBook-Pro', nova: true)->assertOk()->assertJsonPath('maquina', 'MacBook-Pro');
        $this->trocar($this->codigo($ana), 'MacBook-Pro', nova: true)->assertOk()->assertJsonPath('maquina', 'MacBook-Pro-2');
        $this->assertEqualsCanonicalizing(['MacBook-Pro', 'MacBook-Pro-2'], $ana->tokens()->pluck('name')->all(), 'o primeiro continua conectado');

        // A mesma máquina rodando o comando de novo (já tem configuração): troca o token dela.
        $antigo = $ana->tokens()->where('name', 'MacBook-Pro-2')->value('id');
        $this->trocar($this->codigo($ana), 'MacBook-Pro-2')->assertOk()->assertJsonPath('maquina', 'MacBook-Pro-2');
        $this->assertSame(2, $ana->tokens()->count());
        $this->assertNotSame($antigo, $ana->tokens()->where('name', 'MacBook-Pro-2')->value('id'));
    }

    /** Revisão da T-374: com a trava do código ocupada por outra troca, esta não leva token. */
    public function test_troca_simultanea_do_mesmo_codigo_nao_gera_dois_tokens(): void
    {
        $ana = $this->pessoa();
        $codigo = $this->codigo($ana);
        $trava = \Illuminate\Support\Facades\Cache::lock('central-ia:instalacao:'.hash('sha256', $codigo).':trava', 10);
        $this->assertTrue($trava->get());
        $this->travar(fn () => $this->trocar($codigo)->assertUnprocessable());
        $trava->release();
        $this->trocar($codigo)->assertOk();
        $this->assertSame(1, $ana->tokens()->count());
    }

    /** Sem esperar os 5 s da trava de verdade: o relógio anda junto com o sono falso. */
    private function travar(callable $f): void
    {
        \Illuminate\Support\Carbon::setTestNow(now());
        \Illuminate\Support\Sleep::fake(syncWithCarbon: true);
        try {
            $f();
        } finally {
            \Illuminate\Support\Sleep::fake(false);
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_a_pessoa_conecta_a_maquina_pela_tela_com_codigo_de_uso_unico(): void
    {
        $ana = $this->pessoa();
        $bruno = $this->pessoa();
        CentralIaTelegramVinculo::create(['user_id' => $ana->id, 'chat_id' => 987654]);
        CentralIaTelegramVinculo::create(['user_id' => $bruno->id, 'chat_id' => 987655]);
        $codigo = $this->codigo($ana);
        $this->assertSame(0, $ana->tokens()->count(), 'o token só nasce quando a máquina troca o código');

        // T-374: só o chat vai à máquina. O código sai por um segundo bot da
        // pessoa, cujo token ela cola no terminal; o do bot da conversa, que o
        // servidor guarda, nunca vai — senão quem invadisse o servidor
        // encaminharia o código a si mesmo.
        $r = $this->trocar($codigo)->assertOk()
            ->assertJsonPath('telegram.chat_id', '987654')
            ->assertJsonMissingPath('telegram.token')
            ->assertJsonPath('telegram.bot', $this->botNoTeste($ana)->usuario);
        $this->assertStringNotContainsString($this->botNoTeste($ana)->token, $r->getContent(), 'o token do bot da conversa fica só no servidor');
        $token = $ana->tokens()->sole();
        $this->assertSame(['central-ia-runner'], $token->abilities, 'o token não abre o /mcp nem outra porta');
        $this->assertSame('notebook-ana', $token->name);
        $this->assertStringNotContainsString($this->botNoTeste($bruno)->token, $r->getContent(), 'o bot de outra pessoa nunca vai a esta máquina');

        $this->trocar($codigo)->assertUnprocessable();
        $this->assertSame(1, $ana->tokens()->count(), 'o código não vale duas vezes');
    }

    public function test_codigo_nao_vale_para_quem_perdeu_o_acesso_nem_para_quem_nao_aciona_agentes(): void
    {
        $ana = $this->pessoa();
        $codigo = $this->codigo($ana);
        $ana->update(['ativo' => false]);
        $this->trocar($codigo)->assertUnprocessable();
        $this->assertSame(0, $ana->tokens()->count());

        $semCentral = User::factory()->semPerfil()->create();
        $this->actingAs($semCentral)->postJson(route('central-ia.maquinas.instalacao'))->assertForbidden();
        $this->trocar(str_repeat('a', 40))->assertUnprocessable();
    }

    public function test_reconectar_a_mesma_maquina_troca_o_token_em_vez_de_somar(): void
    {
        $ana = $this->pessoa();
        $this->trocar($this->codigo($ana), 'mac-ana')->assertOk();
        $this->trocar($this->codigo($ana), 'mac-ana')->assertOk();
        $this->trocar($this->codigo($ana), 'wsl-ana')->assertOk();
        $this->assertEqualsCanonicalizing(['mac-ana', 'wsl-ana'], $ana->tokens()->pluck('name')->all());
    }

    public function test_batida_liga_a_maquina_ao_token_e_desconectar_corta_as_duas(): void
    {
        $ana = $this->pessoa();
        $token = $this->trocar($this->codigo($ana), 'mac-ana')->json('token');
        Auth::forgetGuards();
        $this->withToken($token)->postJson(route('central-ia.runner.batida'), ['maquina' => 'mac-ana', 'agentes' => ['claude'], 'projetos' => ['alfahome'],
            'politica' => ['acesso_maximo' => 'restrito', 'analise' => 'leitura', 'confirmar_minimo' => 'inicio', 'telegram' => true, 'sistema' => 'mac']])->assertOk();

        Auth::forgetGuards();
        $lista = $this->actingAs($ana)->getJson(route('central-ia.maquinas'))->assertOk()->json('maquinas');
        $this->assertCount(1, $lista);
        $this->assertSame(['mac-ana', 'mac', true, 'ok', true], [$lista[0]['maquina'], $lista[0]['sistema'], $lista[0]['online'], $lista[0]['situacao'], $lista[0]['telegram']]);

        // Outra pessoa não desconecta a máquina da Ana.
        $this->actingAs($this->pessoa())->deleteJson(route('central-ia.maquinas.desconectar', $lista[0]['id']))->assertNotFound();
        $this->actingAs($ana)->deleteJson(route('central-ia.maquinas.desconectar', $lista[0]['id']))->assertOk()->assertJsonCount(0, 'maquinas');
        $this->assertSame(0, CentralIaRunner::where('user_id', $ana->id)->count());

        Auth::forgetGuards();
        $this->app['auth']->forgetUser();
        $this->withToken($token)->postJson(route('central-ia.runner.batida'), ['maquina' => 'mac-ana', 'agentes' => [], 'projetos' => []])->assertUnauthorized();
    }

    /** 31/10/2026: pasta de outra empresa na máquina não entra na Central — nem no estado, nem nas sessões, nem no consumo. */
    public function test_batida_descarta_os_projetos_de_fora_do_matriz(): void
    {
        config(['central_ia.projetos_fora' => ['outra*', 'ACME-*']]);
        $ana = $this->pessoa();
        $token = $this->trocar($this->codigo($ana), 'pc-ana')->json('token');
        Auth::forgetGuards();
        $this->withToken($token)->postJson(route('central-ia.runner.batida'), ['maquina' => 'pc-ana', 'agentes' => ['claude'],
            'projetos' => ['alfagym', 'outra-empresa', 'acme-painel', 'Outra_Coisa'],
            'estado' => ['alfagym' => ['git' => true], 'outra-empresa' => ['git' => true]],
            'sessoes' => [['agente' => 'claude', 'id' => 'sessao-0001', 'projeto' => 'outra-empresa', 'cwd' => '/x'], ['agente' => 'claude', 'id' => 'sessao-0002', 'projeto' => 'alfagym', 'cwd' => '/y']],
            'consumo' => ['linhas' => [['dia' => '2026-10-31', 'agente' => 'claude', 'projeto' => 'acme-painel', 'entrada' => 5], ['dia' => '2026-10-31', 'agente' => 'claude', 'projeto' => 'alfagym', 'entrada' => 7]]],
        ])->assertOk();

        $r = CentralIaRunner::where('user_id', $ana->id)->firstOrFail();
        $this->assertSame(['alfagym'], $r->projetos);
        $this->assertSame(['alfagym'], array_keys($r->estado));
        $this->assertSame(['alfagym'], array_column($r->sessoes, 'projeto'));
        $this->assertSame(['alfagym'], array_column($r->consumo['linhas'], 'projeto'));
        $this->assertSame(['alfagym'], app(AgentesService::class)->disponiveis($ana)['projetos']);
    }

    /** Revisão da T-374: o pedido que a máquina tinha na mão não fica "executando" até o teto. */
    public function test_desconectar_interrompe_o_pedido_que_a_maquina_tinha_na_mao(): void
    {
        $ana = $this->pessoa();
        $this->trocar($this->codigo($ana), 'mac-ana')->assertOk();
        Auth::forgetGuards();
        $runner = CentralIaRunner::create(['user_id' => $ana->id, 'maquina' => 'mac-ana', 'agentes' => ['claude'], 'projetos' => [],
            'token_id' => $ana->tokens()->sole()->id, 'visto_em' => now()]);
        $outra = CentralIaRunner::create(['user_id' => $ana->id, 'maquina' => 'wsl-ana', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        $t = CentralIaTarefa::create(['user_id' => $ana->id, 'projeto' => '', 'titulo' => 'Geral', 'objetivo' => 'Geral']);
        $pedido = fn (CentralIaRunner $r) => CentralIaExecucao::create(['user_id' => $ana->id, 'tarefa_id' => $t->id, 'projeto' => '', 'agente' => 'claude',
            'modo' => 'analisar', 'instrucao' => 'x', 'status' => CentralIaExecucao::EXECUTANDO, 'runner_id' => $r->id, 'iniciada_em' => now(),
            'pedido_id' => app(ConversaService::class)->registrarDoAgente($ana, 'Central', 'pedido', projeto: '', tarefaId: $t->id)->id]);
        $daMaquina = $pedido($runner);
        $daOutra = $pedido($outra);

        $this->actingAs($ana)->deleteJson(route('central-ia.maquinas.desconectar', $ana->tokens()->sole()->id))->assertOk();

        $daMaquina->refresh();
        $this->assertSame(CentralIaExecucao::FALHOU, $daMaquina->status);
        $this->assertStringContainsString('desconectada', $daMaquina->erro);
        $this->assertStringContainsString('mac-ana foi desconectada', \App\Models\CentralIaMensagem::find($daMaquina->resposta_id)->conteudo);
        $this->assertSame(CentralIaExecucao::EXECUTANDO, $daOutra->fresh()->status, 'o pedido de outra máquina segue');
    }

    /**
     * Revisão da T-374: o `proxima` que esperava a trava da conta enquanto a
     * máquina era desconectada não entrega o pedido a ela depois.
     */
    public function test_maquina_desconectada_nao_recebe_pedido_do_proxima_que_ja_estava_a_caminho(): void
    {
        config(['central_ia.canal_exclusivo' => false]);
        $ana = $this->pessoa(admin: true); // não-admin nasce com a execução bloqueada
        $this->trocar($this->codigo($ana), 'mac-ana')->assertOk();
        Auth::forgetGuards();
        $runner = CentralIaRunner::create(['user_id' => $ana->id, 'maquina' => 'mac-ana', 'agentes' => ['claude'], 'projetos' => ['gestor'],
            'token_id' => $ana->tokens()->sole()->id, 'visto_em' => now()]);
        $agentes = app(\App\Services\CentralIa\AgentesService::class);
        $pendente = $agentes->pedir($ana, 'revise', ['projeto' => 'gestor', 'agente' => 'claude', 'modo' => 'analisar', 'instrucao' => 'revise'], 'web', 'dx1');

        app(Maquinas::class)->desconectar($ana, $ana->tokens()->sole()->id);

        // O `proxima` já tinha a máquina em mãos (autenticada antes do desconectar).
        $this->assertNull($agentes->proxima($ana, $runner));
        $this->assertSame(CentralIaExecucao::PENDENTE, $pendente->fresh()->status);

        // E o pedido segue para a máquina que está de pé.
        $viva = CentralIaRunner::create(['user_id' => $ana->id, 'maquina' => 'wsl-ana', 'agentes' => ['claude'], 'projetos' => ['gestor'], 'visto_em' => now()]);
        $this->assertSame($pendente->id, $agentes->proxima($ana, $viva)['id']);
        $this->assertSame($viva->id, $pendente->fresh()->runner_id);
    }

    /**
     * Revisão da T-374: conta travada antes de ler as máquinas, na ordem do
     * `proxima`. Limite assumido: o SQLite ignora o `FOR UPDATE`, então o
     * teste prova a ORDEM das leituras (as duas com `lockForUpdate` no
     * código), e não a espera entre duas conexões.
     */
    public function test_desconectar_trava_a_conta_antes_de_ler_as_maquinas(): void
    {
        $ana = $this->pessoa();
        $this->trocar($this->codigo($ana), 'mac-ana')->assertOk();
        Auth::forgetGuards();
        $tokenId = $ana->tokens()->sole()->id;
        $consultas = [];
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$consultas) { $consultas[] = $q->sql; });

        app(Maquinas::class)->desconectar($ana, $tokenId);

        $conta = collect($consultas)->search(fn ($sql) => preg_match('/from "users" where "users"\."id" = \?/', $sql));
        $maquinas = collect($consultas)->search(fn ($sql) => str_contains($sql, 'from "central_ia_runners"'));
        $this->assertNotFalse($conta, 'a conta é lida (e travada) no desconectar');
        $this->assertLessThan($maquinas, $conta);
    }

    /**
     * 3ª revisão da T-374: a batida autenticada antes do Desconectar e gravada
     * depois não recria a máquina com o token já apagado.
     */
    public function test_batida_que_chega_depois_do_desconectar_nao_recria_a_maquina(): void
    {
        $ana = $this->pessoa();
        $token = $this->trocar($this->codigo($ana), 'mac-ana')->json('token');
        $tokenId = $ana->tokens()->sole()->id;
        Auth::forgetGuards();

        // O Desconectar acontece entre a autenticação da batida e a gravação:
        // a primeira transação da batida é o ponto em que ela grava.
        $feito = false;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function () use (&$feito, $tokenId) {
            if ($feito) return;
            $feito = true;
            \Laravel\Sanctum\PersonalAccessToken::whereKey($tokenId)->delete();
        });

        $this->withToken($token)->postJson(route('central-ia.runner.batida'), ['maquina' => 'mac-ana', 'agentes' => ['claude'], 'projetos' => ['gestor']])
            ->assertUnauthorized();
        $this->assertTrue($feito);
        $this->assertSame(0, CentralIaRunner::where('user_id', $ana->id)->count(), 'nenhuma máquina órfã');
        $this->assertSame([], app(AgentesService::class)->disponiveis($ana)['agentes'], 'nada conta como disponível');
    }

    /** 3ª revisão da T-374: máquina cujo token caiu não recebe pedido, mesmo que a linha tenha ficado. */
    public function test_proxima_nao_entrega_a_maquina_cujo_token_caiu(): void
    {
        config(['central_ia.canal_exclusivo' => false]);
        $ana = $this->pessoa(admin: true); // não-admin nasce com a execução bloqueada
        $this->trocar($this->codigo($ana), 'mac-ana')->assertOk();
        Auth::forgetGuards();
        $tokenId = $ana->tokens()->sole()->id;
        $orfa = CentralIaRunner::create(['user_id' => $ana->id, 'maquina' => 'mac-ana', 'agentes' => ['claude'], 'projetos' => ['gestor'],
            'token_id' => $tokenId, 'visto_em' => now()]);
        \Laravel\Sanctum\PersonalAccessToken::whereKey($tokenId)->delete();
        $agentes = app(AgentesService::class);
        $pendente = $agentes->pedir($ana, 'revise', ['projeto' => 'gestor', 'agente' => 'claude', 'modo' => 'analisar', 'instrucao' => 'revise'], 'web', 'tk1');

        $this->assertNull($agentes->proxima($ana, $orfa));
        $this->assertSame(CentralIaExecucao::PENDENTE, $pendente->fresh()->status);
    }

    /**
     * 3ª revisão da T-374: todo caminho que trava uma execução e avisa na
     * conversa (que trava a conta) trava a conta PRIMEIRO, na ordem do
     * `desconectar` e do `proxima` — a ordem invertida dava deadlock.
     *
     * Limite assumido: o SQLite dos testes ignora o `FOR UPDATE`, então o que
     * se prova é a ORDEM das leituras travadas dentro de cada transação, e
     * não a espera entre duas conexões. As duas leituras levam o
     * `lockForUpdate` no código; a ordem delas é a ordem das travas no MySQL.
     */
    public function test_caminhos_que_avisam_na_conversa_travam_a_conta_antes_da_execucao(): void
    {
        config(['central_ia.canal_exclusivo' => false]);
        $ana = $this->pessoa(admin: true);
        $runner = CentralIaRunner::create(['user_id' => $ana->id, 'maquina' => 'mac-ana', 'agentes' => ['claude'], 'projetos' => ['gestor'], 'visto_em' => now()]);
        $t = CentralIaTarefa::create(['user_id' => $ana->id, 'projeto' => '', 'titulo' => 'Geral', 'objetivo' => 'Geral']);
        $execucao = fn (string $status, array $mais = []) => CentralIaExecucao::create(['user_id' => $ana->id, 'tarefa_id' => $t->id, 'projeto' => 'gestor',
            'agente' => 'claude', 'modo' => 'analisar', 'instrucao' => 'x', 'status' => $status,
            'pedido_id' => app(ConversaService::class)->registrarDoAgente($ana, 'Central', 'pedido', projeto: '', tarefaId: $t->id)->id] + $mais);
        $agentes = app(AgentesService::class);

        $caminhos = [
            'publicar' => function () use ($execucao, $ana) {
                $e = $execucao(CentralIaExecucao::PENDENTE);
                $e->forceFill(['created_at' => now()->subMinutes(3)])->save();
                app(\App\Services\CentralIa\ProgressoService::class)->publicar($ana);
            },
            'confirmacao' => function () use ($execucao, $ana, $runner, $agentes) {
                $e = $execucao(CentralIaExecucao::EXECUTANDO, ['runner_id' => $runner->id, 'iniciada_em' => now()]);
                $agentes->confirmacao($ana, $runner, $e->id, ['estado' => 'aguardando']);
            },
            'interromperOrfas' => function () use ($execucao, $ana, $runner, $agentes) {
                $execucao(CentralIaExecucao::EXECUTANDO, ['runner_id' => $runner->id, 'iniciada_em' => now()->subMinutes(5)]);
                $agentes->interromperOrfas($ana, $runner, now(), []);
            },
            'interromperDaMaquinaDesconectada' => function () use ($execucao, $ana, $runner, $agentes) {
                $execucao(CentralIaExecucao::EXECUTANDO, ['runner_id' => $runner->id, 'iniciada_em' => now()]);
                $agentes->interromperDaMaquinaDesconectada($ana, $runner);
            },
            'expirarPresas (fila)' => function () use ($execucao, $ana, $runner, $agentes) {
                $runner->update(['visto_em' => now()->subHour()]);
                $e = $execucao(CentralIaExecucao::PENDENTE);
                $e->forceFill(['created_at' => now()->subMinutes(31)])->save();
                $agentes->expirarPresas($ana);
            },
            'expirarPresas (teto)' => function () use ($execucao, $ana, $runner, $agentes) {
                $execucao(CentralIaExecucao::EXECUTANDO, ['runner_id' => $runner->id, 'iniciada_em' => now()->subHours(10)]);
                $agentes->expirarPresas($ana);
            },
        ];

        $invertidos = [];
        foreach ($caminhos as $nome => $caminho) {
            CentralIaExecucao::query()->update(['status' => CentralIaExecucao::CONCLUIDA]);
            $ordem = [];
            $ouvindo = true;
            \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function () use (&$ordem, &$ouvindo) {
                if ($ouvindo) $ordem[] = 'BEGIN';
            });
            \Illuminate\Support\Facades\DB::listen(function ($q) use (&$ordem, &$ouvindo) {
                if (! $ouvindo) return;
                if (preg_match('/^select \* from "users" where "users"\."id" = \?/', $q->sql)) $ordem[] = 'conta';
                elseif (preg_match('/^select .* from "central_ia_execucoes" where .*"id" = \?/', $q->sql)) $ordem[] = 'execucao';
            });

            $caminho();
            $ouvindo = false;

            $transacoes = 0;
            foreach (array_keys($ordem, 'BEGIN', true) as $i) {
                $primeira = collect(array_slice($ordem, $i + 1))->first(fn ($x) => $x !== 'BEGIN');
                if ($primeira === null) continue;
                $transacoes++;
                if ($primeira !== 'conta') $invertidos[$nome] = true;
            }
            $this->assertGreaterThan(0, $transacoes, "{$nome}: nenhuma transação exercitada");
            $this->assertContains('execucao', $ordem, "{$nome}: o caminho não chegou a travar a execução");
        }
        $this->assertSame([], array_keys($invertidos), 'travaram a execução antes da conta');
    }

    public function test_maquina_sem_noticia_pede_para_conferir_a_tailnet(): void
    {
        $ana = $this->pessoa();
        $this->trocar($this->codigo($ana), 'wsl-ana')->assertOk();
        $tokenId = $ana->tokens()->sole()->id;
        $this->assertSame('nunca', app(Maquinas::class)->lista($ana)[0]['situacao']);
        CentralIaRunner::create(['user_id' => $ana->id, 'maquina' => 'wsl-ana', 'agentes' => [], 'projetos' => [], 'token_id' => $tokenId, 'visto_em' => now()->subMinutes(30)]);
        $this->assertSame('sumiu', app(Maquinas::class)->lista($ana)[0]['situacao']);
    }

    /** Sem o bot vinculado, a máquina conecta sem Telegram — e recusa modificar arquivos. */
    public function test_sem_bot_a_maquina_conecta_sem_telegram(): void
    {
        $ana = $this->pessoa();

        $this->trocar($this->codigo($ana))->assertOk()
            ->assertJsonPath('telegram.chat_id', null);
        $this->actingAs($ana)->getJson(route('central-ia.maquinas'))->assertJsonPath('telegram_pronto', false);
    }

    public function test_instalador_e_arquivos_saem_sem_login_e_sem_segredo(): void
    {
        config(['app.url' => 'https://alfamatriz.exemplo.ts.net']);
        $script = $this->get(route('central-ia.instalador'))->assertOk()->getContent();
        $this->assertStringContainsString('URL="https://alfamatriz.exemplo.ts.net"', $script);
        $this->assertStringContainsString('runner.mjs politica.mjs', $script);
        $this->assertStringNotContainsString('__ALFAMATRIZ_URL__', $script);

        $this->get(route('central-ia.runner.arquivo', 'politica.mjs'))->assertOk()->assertSee('PUBLICACAO_NO_SHELL', false);
        $this->get('/central-ia/runner/arquivos/instalar.sh')->assertNotFound();
        // Todo arquivo da lista sai pelo endereço que o instalador usa (o .php com .txt no fim, por causa do nginx).
        foreach (\App\Services\CentralIa\Maquinas::ARQUIVOS as $f) {
            $this->get('/central-ia/runner/arquivos/'.(str_ends_with($f, '.php') ? $f.'.txt' : $f))->assertOk();
        }
        $this->get('/central-ia/runner/arquivos/secretario_gestor.txt')->assertNotFound();
        $this->get('/central-ia/runner/arquivos/..%2F..%2F.env')->assertNotFound();
        foreach (Maquinas::ARQUIVOS as $f) {
            $this->assertFileExists(base_path('deploy/central-ia/'.$f));
        }
    }

    /** Uma execução pendente da pessoa e o que o runner recebe por ela. */
    private function job(User $u, array $roteamento = []): array
    {
        $t = CentralIaTarefa::create(['user_id' => $u->id, 'projeto' => '', 'titulo' => 'Geral', 'objetivo' => 'Geral']);
        $m = app(ConversaService::class)->registrarDoAgente($u, 'Central', 'pedido', projeto: '', tarefaId: $t->id);
        CentralIaExecucao::create(['user_id' => $u->id, 'pedido_id' => $m->id, 'tarefa_id' => $t->id, 'projeto' => '', 'agente' => 'claude',
            'modo' => 'analisar', 'instrucao' => 'minha agenda de hoje', 'roteamento' => $roteamento]);
        $r = CentralIaRunner::updateOrCreate(['user_id' => $u->id, 'maquina' => 'teste'], ['agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);

        return app(AgentesService::class)->proxima($u, $r);
    }

    public function test_cstk_e_memoria_sao_opcoes_da_pessoa_desligadas_por_padrao(): void
    {
        $u = $this->pessoa(true);
        $job = $this->job($u);
        $this->assertFalse($job['cstk']);
        $this->assertFalse($job['memoria']);

        $regras = $this->actingAs($u)->getJson(route('central-ia.painel'))->json('preferencias.regras');
        $this->assertFalse($regras['cstk']);
        $this->putJson(route('central-ia.preferencias'), ['cstk' => true, 'memoria' => true] + $regras)->assertOk();
        CentralIaExecucao::query()->update(['status' => CentralIaExecucao::CONCLUIDA]);
        $job = $this->job($u);
        $this->assertTrue($job['cstk']);
        $this->assertTrue($job['memoria']);
    }

    public function test_agenda_vem_do_proprio_alfamatriz_e_so_a_da_pessoa(): void
    {
        $hoje = now()->toDateString();
        $u = $this->pessoa(true);
        $outro = User::factory()->create();
        Compromisso::factory()->em($hoje, '09:30')->create(['titulo' => 'Reunião com a EVOFIT', 'criado_por_id' => $u->id])->sincronizarParticipantes([$u->id]);
        Compromisso::factory()->em($hoje, '11:00')->create(['titulo' => 'Compromisso de outra pessoa', 'criado_por_id' => $outro->id])->sincronizarParticipantes([$outro->id]);

        $job = $this->job($u, ['fontes' => [['tipo' => 'agenda', 'inicio' => $hoje, 'dias' => 1, 'rotulo' => 'hoje']]]);
        $agenda = $job['fontes'][0]['alfamatriz'];
        $this->assertStringContainsString("{$hoje} 09:30 [Compromisso] Reunião com a EVOFIT", $agenda);
        $this->assertStringNotContainsString('outra pessoa', $agenda);
    }

    public function test_quem_nao_ve_a_agenda_nao_recebe_a_agenda_do_alfamatriz(): void
    {
        $u = $this->pessoa(true);
        $semAgenda = $this->pessoa();
        // Libera a execução do membro para o runner receber o pedido.
        $this->actingAs($u)->putJson(route('central-ia.usuarios.politica', $semAgenda->id), ['revisao' => 0, 'execucao_bloqueada' => false,
            'habilitados' => ['claude' => true, 'codex' => false, 'copilot' => false], 'pedidos_dia' => ['claude' => null, 'codex' => 0, 'copilot' => 0],
            'limite_total' => null, 'paralelo' => 1])->assertOk();
        $this->assertFalse($semAgenda->canPermissao('agenda', 'ler'));
        $job = $this->job($semAgenda, ['fontes' => [['tipo' => 'agenda', 'inicio' => now()->toDateString(), 'dias' => 1, 'rotulo' => 'hoje']]]);
        $this->assertArrayNotHasKey('alfamatriz', $job['fontes'][0]);
    }

    /** Varredura (T-374): nada da máquina de uma pessoa no código da Central. */
    public function test_nenhum_caminho_ou_regra_de_uma_maquina_no_codigo_da_central(): void
    {
        $arquivos = [...glob(base_path('deploy/central-ia/*.{mjs,sh}'), GLOB_BRACE), base_path('config/central_ia.php'),
            ...glob(app_path('Services/CentralIa/*.php')), ...glob(app_path('Http/Controllers/CentralIa*.php'))];
        foreach ($arquivos as $arquivo) {
            $texto = file_get_contents($arquivo);
            foreach (['duque-de-caxias', '/mnt/c/', 'C:\\tmp', 'gcal.py', '8089', 'painel-avanco', 'CENTRAL_IA_DONO', 'GrupoSolucoesAgentBot'] as $proibido) {
                $this->assertStringNotContainsString($proibido, $texto, basename($arquivo)." ainda cita {$proibido}");
            }
        }
    }
}
