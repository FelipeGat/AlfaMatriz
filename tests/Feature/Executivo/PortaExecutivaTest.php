<?php

namespace Tests\Feature\Executivo;

use App\Models\Auditoria;
use App\Models\Revenda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A PORTA da API Executiva: quem entra, quem não entra, e que nada escreve.
 */
class PortaExecutivaTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/executivo';

    private function felipe(): User
    {
        config(['services.executivo.conta' => 'felipe@alfa.test']);

        return User::factory()->create(['email' => 'felipe@alfa.test']);
    }

    private function token(User $u, array $capacidades = ['executivo:ler']): string
    {
        return $u->createToken('teste', $capacidades)->plainTextToken;
    }

    public function test_sem_token_recusa_com_401_mesmo_sem_accept_json(): void
    {
        $this->felipe();
        $this->get(self::URL)->assertUnauthorized();
    }

    public function test_conta_certa_com_a_capacidade_certa_le_o_envelope(): void
    {
        $token = $this->token($this->felipe());

        $this->withToken($token)->getJson(self::URL)
            ->assertOk()
            ->assertJsonStructure(['fonte', 'periodo', 'consultado_em', 'atualizado_em', 'situacao', 'motivo', 'dados'])
            ->assertJsonPath('situacao', 'ok');
    }

    public function test_token_do_mcp_nao_abre_a_api_executiva(): void
    {
        $token = $this->token($this->felipe(), ['mcp']);

        $this->withToken($token)->getJson(self::URL)->assertForbidden();
    }

    public function test_token_executivo_nao_abre_o_mcp(): void
    {
        $token = $this->token($this->felipe());

        $this->withToken($token)
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Accept' => 'application/json, text/event-stream'])
            ->assertForbidden();
    }

    public function test_outra_conta_nao_entra_mesmo_com_a_capacidade(): void
    {
        $this->felipe();
        $outra = User::factory()->create(['email' => 'rossini@alfa.test']);

        $this->withToken($this->token($outra))->getJson(self::URL)->assertForbidden();
    }

    public function test_conta_desativada_perde_o_acesso_na_hora(): void
    {
        $u = $this->felipe();
        $token = $this->token($u);
        $u->forceFill(['ativo' => false])->save();

        $this->withToken($token)->getJson(self::URL)->assertForbidden();
    }

    public function test_conta_de_revenda_nao_entra(): void
    {
        config(['services.executivo.conta' => 'rev@alfa.test']);
        $revenda = Revenda::create(['nome' => 'Alpha Rev', 'ativo' => true]);
        $u = User::factory()->create(['email' => 'rev@alfa.test', 'revenda_id' => $revenda->id]);

        $this->withToken($this->token($u))->getJson(self::URL)->assertForbidden();
    }

    public function test_token_expirado_nao_entra(): void
    {
        $u = $this->felipe();
        $token = $u->createToken('velho', ['executivo:ler'], now()->subMinute())->plainTextToken;

        $this->withToken($token)->getJson(self::URL)->assertUnauthorized();
    }

    public function test_escrita_devolve_405(): void
    {
        $token = $this->token($this->felipe());

        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $verbo) {
            $this->withToken($token)->{$verbo}(self::URL)->assertStatus(405);
        }
    }

    public function test_recusa_escolher_a_conta_por_parametro(): void
    {
        $token = $this->token($this->felipe());
        $outra = User::factory()->create();

        $this->withToken($token)->getJson(self::URL.'?user_id='.$outra->id)->assertStatus(422);
    }

    public function test_cada_chamada_deixa_rastro_sem_conteudo(): void
    {
        $u = $this->felipe();
        $this->withToken($this->token($u))->getJson(self::URL)->assertOk();

        $linha = Auditoria::query()->where('recurso', 'executivo')->latest('id')->first();

        $this->assertNotNull($linha);
        $this->assertSame($u->id, $linha->user_id);
        $this->assertSame('consultou', $linha->acao);
        $this->assertStringContainsString('api/executivo → 200', $linha->descricao);
        $this->assertNull($linha->alteracoes);
    }

    public function test_comando_emite_so_para_a_conta_configurada_com_validade_e_revoga(): void
    {
        $u = $this->felipe();
        User::factory()->create(['email' => 'outro@alfa.test']);

        $this->artisan('alfa:executivo-token', ['email' => 'outro@alfa.test'])->assertFailed();
        $this->assertSame(0, $u->tokens()->count());

        $this->artisan('alfa:executivo-token', ['email' => 'felipe@alfa.test'])->assertSuccessful();
        $t = $u->tokens()->first();
        $this->assertSame(['executivo:ler'], $t->abilities);
        $this->assertTrue($t->expires_at->between(now()->addDays(29), now()->addDays(31)));

        $this->artisan('alfa:executivo-token', ['email' => 'felipe@alfa.test', '--revogar' => true])->assertSuccessful();
        $this->assertSame(0, $u->tokens()->count());
    }

    public function test_recusas_tambem_deixam_rastro_sem_o_valor_do_token(): void
    {
        $u = $this->felipe();

        $this->getJson(self::URL)->assertUnauthorized();
        $this->withToken('999|tokeninventado')->getJson(self::URL)->assertUnauthorized();
        $this->withToken($this->token($u, ['mcp']))->getJson(self::URL)->assertForbidden();
        // O guard guarda o usuário da chamada anterior dentro do mesmo teste.
        app('auth')->forgetGuards();
        $this->withToken($this->token($u))->postJson(self::URL)->assertStatus(405);

        $linhas = Auditoria::query()->where('recurso', 'executivo')->orderBy('id')->get();

        $this->assertCount(4, $linhas, $linhas->pluck('descricao')->implode(' ; '));
        $this->assertSame(['recusou'], $linhas->pluck('acao')->unique()->all());
        $this->assertStringContainsString('→ 401 (sem identificação)', $linhas[0]->descricao);
        $this->assertStringContainsString('→ 401 (sem identificação)', $linhas[1]->descricao);
        $this->assertStringContainsString('→ 403 (token #', $linhas[2]->descricao);
        $this->assertStringContainsString('→ 405', $linhas[3]->descricao);
        $this->assertStringNotContainsString('tokeninventado', $linhas->pluck('descricao')->implode(' '));
    }

    public function test_estourar_o_limite_tambem_e_registrado(): void
    {
        $token = $this->token($this->felipe());

        for ($i = 0; $i < 30; $i++) {
            $this->withToken($token)->getJson(self::URL)->assertOk();
        }
        $this->withToken($token)->getJson(self::URL)->assertStatus(429);

        $this->assertStringContainsString('→ 429', Auditoria::query()->where('recurso', 'executivo')->latest('id')->value('descricao'));
    }

    /**
     * O token executivo não serve a NENHUMA outra rota protegida: nem as de
     * token Sanctum (MCP, changelog, runner), nem as da tela. Vigia/monitor
     * autenticam no controller e têm um teste separado.
     */
    public function test_token_executivo_nao_autentica_em_nenhuma_outra_rota_protegida(): void
    {
        $token = $this->token($this->felipe());
        $testadas = 0;
        $parametrizadas = 0;
        $this->withoutMiddleware(SubstituteBindings::class);

        foreach (Route::getRoutes() as $rota) {
            $mid = $rota->gatherMiddleware();
            $protegida = collect($mid)->contains(fn ($m) => is_string($m)
                && (str_starts_with($m, 'auth') || str_contains($m, 'sanctum') || str_starts_with($m, 'abilities') || str_starts_with($m, 'permissao')));

            if (! $protegida || str_starts_with($rota->uri(), 'api/executivo')) {
                continue;
            }

            $metodo = collect($rota->methods())->first(fn ($m) => ! in_array($m, ['HEAD', 'OPTIONS'], true));
            // IDs fictícios sintaticamente válidos. Sem binding: a recusa deve
            // vir da autenticação/capacidade, nunca de um recurso inexistente.
            $uri = preg_replace_callback('/\{([^}?]+)\??\}/', fn ($m) => $m[1] === 'anexo'
                ? '00000000-0000-4000-8000-000000000001' : ($m[1] === 'data' ? '2026-11-14' : '1'), $rota->uri());
            app('auth')->forgetGuards();
            app('auth')->shouldUse('web');
            $r = $this->withToken($token)->call($metodo, '/'.ltrim($uri, '/'), [], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token]);

            $this->assertContains(
                $r->getStatusCode(),
                [401, 403, 419],
                $metodo.' /'.$rota->uri().' respondeu '.$r->getStatusCode().' ao token executivo',
            );
            $testadas++;
            $parametrizadas += str_contains($rota->uri(), '{') ? 1 : 0;
        }

        $this->assertGreaterThan(20, $testadas);
        $this->assertGreaterThan(0, $parametrizadas);
    }

    public function test_capacidades_sanctum_existentes_nao_abrem_blocos_executivos(): void
    {
        $u = $this->felipe();
        $capacidades = collect(Route::getRoutes())->flatMap(fn ($r) => $r->gatherMiddleware())
            ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'abilities:'))
            ->flatMap(fn ($m) => explode(',', substr($m, strlen('abilities:'))))
            ->reject(fn ($c) => $c === 'executivo:ler')->unique();
        $this->assertNotEmpty($capacidades->all());
        foreach ($capacidades as $capacidade) {
            foreach (['', '/agenda', '/emails', '/pendencias', '/alertas', '/pedro', '/fontes', '/financeiro'] as $bloco) {
                app('auth')->forgetGuards();
                $this->withToken($this->token($u, [$capacidade]))->getJson(self::URL.$bloco)->assertForbidden();
            }
        }
    }

    public function test_rotas_sanctum_parametrizadas_requerem_autenticacao_e_capacidade(): void
    {
        $this->withoutMiddleware(SubstituteBindings::class);
        $u = $this->felipe();
        $total = 0;
        foreach (Route::getRoutes() as $rota) {
            if (! in_array('auth:sanctum', $rota->gatherMiddleware(), true) || str_starts_with($rota->uri(), 'api/executivo')) {
                continue;
            }
            $this->assertTrue(collect($rota->gatherMiddleware())->contains(fn ($m) => str_starts_with($m, 'abilities:')), $rota->uri());
            $uri = '/'.preg_replace_callback('/\{([^}?]+)\??\}/', fn ($m) => $m[1] === 'anexo'
                ? '00000000-0000-4000-8000-000000000001' : ($m[1] === 'data' ? '2026-11-14' : '1'), $rota->uri());
            $metodo = $rota->methods()[0];
            foreach (['', '999|invalido', $this->token($u)] as $token) {
                app('auth')->forgetGuards();
                $this->withToken($token)->call($metodo, $uri, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token])
                    ->assertStatus($token === '' || $token === '999|invalido' ? 401 : 403);
            }
            $total++;
        }
        $this->assertGreaterThan(0, $total);
    }

    public function test_token_executivo_nao_abre_apis_com_autenticacao_propria(): void
    {
        $u = $this->felipe();
        foreach (['/api/vigia/erros', '/api/monitor/eventos'] as $uri) {
            foreach (['', '999|invalido', $this->token($u)] as $token) {
                app('auth')->forgetGuards();
                $this->withToken($token)->withHeader('X-AlfaMonitor-Token', $token)->postJson($uri, [])->assertUnauthorized();
            }
        }
    }
}
