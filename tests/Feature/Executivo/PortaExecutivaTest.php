<?php

namespace Tests\Feature\Executivo;

use App\Models\Auditoria;
use App\Models\Revenda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * token (MCP, vigia, changelog, monitor, runner), nem as da tela.
     */
    public function test_token_executivo_nao_autentica_em_nenhuma_outra_rota_protegida(): void
    {
        $token = $this->token($this->felipe());
        $testadas = 0;

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $rota) {
            $mid = $rota->gatherMiddleware();
            $protegida = collect($mid)->contains(fn ($m) => is_string($m)
                && (str_starts_with($m, 'auth') || str_contains($m, 'sanctum') || str_starts_with($m, 'abilities') || str_starts_with($m, 'permissao')));

            if (! $protegida || str_starts_with($rota->uri(), 'api/executivo') || str_contains($rota->uri(), '{')) {
                continue;
            }

            $metodo = collect($rota->methods())->first(fn ($m) => ! in_array($m, ['HEAD', 'OPTIONS'], true));
            $r = $this->withToken($token)->call($metodo, '/'.ltrim($rota->uri(), '/'), [], [], [], ['HTTP_ACCEPT' => 'application/json']);

            $this->assertContains(
                $r->getStatusCode(),
                [301, 302, 401, 403, 404, 405, 419],
                $metodo.' /'.$rota->uri().' respondeu '.$r->getStatusCode().' ao token executivo',
            );
            $testadas++;
        }

        $this->assertGreaterThan(20, $testadas);
    }
}
