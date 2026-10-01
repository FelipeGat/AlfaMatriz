<?php

namespace Tests\Feature\Mcp;

use App\Models\Revenda;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A porta HTTP do servidor MCP — a que o agente de fora usa.
 *
 * O que se prova aqui é a PORTA, não as ferramentas (que têm a própria suíte):
 * sem token não entra, token sem a capacidade `mcp` não entra, conta de revenda
 * não entra mesmo com token, e com token certo o agente fala em nome de quem o
 * emitiu.
 */
class RotaHttpTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function cabecalhos(): array
    {
        return ['Accept' => 'application/json, text/event-stream'];
    }

    /** @return array<string, mixed> */
    private function chamada(string $metodo, array $params = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $metodo, 'params' => $params];
    }

    public function test_sem_token_a_rota_recusa(): void
    {
        $this->postJson('/mcp', $this->chamada('tools/list'), $this->cabecalhos())
            ->assertUnauthorized();
    }

    public function test_token_sem_a_capacidade_mcp_nao_entra(): void
    {
        $usuario = User::factory()->create();
        $token = $usuario->createToken('outro', ['qualquer-coisa'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/mcp', $this->chamada('tools/list'), $this->cabecalhos())
            ->assertForbidden();
    }

    public function test_conta_de_revenda_nao_entra_mesmo_com_token(): void
    {
        $revenda = Revenda::create(['nome' => 'Alpha Rev', 'ativo' => true]);
        $usuario = User::factory()->create(['revenda_id' => $revenda->id]);
        $token = $usuario->createToken('lxc', ['mcp'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/mcp', $this->chamada('tools/list'), $this->cabecalhos())
            ->assertForbidden();
    }

    public function test_com_token_o_agente_lista_e_cria_em_nome_de_quem_o_emitiu(): void
    {
        $usuario = User::factory()->create(['name' => 'Rossini']);
        $token = $usuario->createToken('lxc-dev', ['mcp'])->plainTextToken;

        $lista = $this->withToken($token)
            ->postJson('/mcp', $this->chamada('tools/list'), $this->cabecalhos())
            ->assertOk()
            ->json('result.tools.*.name');

        $this->assertContains('criar_tarefa', $lista);
        $this->assertCount(21, $lista);

        $this->withToken($token)
            ->postJson('/mcp', $this->chamada('tools/call', [
                'name' => 'criar_tarefa',
                'arguments' => ['titulo' => 'Aberta pela porta HTTP'],
            ]), $this->cabecalhos())
            ->assertOk()
            ->assertJsonPath('result.isError', false);

        $tarefa = Tarefa::firstOrFail();

        $this->assertSame('Aberta pela porta HTTP', $tarefa->titulo);
        $this->assertSame($usuario->id, $tarefa->criado_por_id);
    }

    public function test_o_comando_emite_e_revoga_o_token(): void
    {
        $usuario = User::factory()->create(['email' => 'rossini@alfa.test']);

        $this->artisan('alfa:mcp-token', ['email' => 'rossini@alfa.test', '--nome' => 'lxc-dev'])
            ->assertSuccessful();

        $token = $usuario->tokens()->sole();
        $this->assertSame('lxc-dev', $token->name);
        $this->assertSame(['mcp'], $token->abilities);

        $this->artisan('alfa:mcp-token', ['email' => 'rossini@alfa.test', '--revogar' => true])
            ->assertSuccessful();

        $this->assertSame(0, $usuario->tokens()->count());
    }

    public function test_o_comando_recusa_conta_desativada(): void
    {
        User::factory()->desativado()->create(['email' => 'inativo@alfa.test']);

        $this->artisan('alfa:mcp-token', ['email' => 'inativo@alfa.test'])
            ->assertFailed();
    }
}
