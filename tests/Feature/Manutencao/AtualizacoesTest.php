<?php

namespace Tests\Feature\Manutencao;

use App\Models\Atualizacao;
use App\Models\Perfil;
use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A aba Atualizações (#225): o changelog que o `publicar-changelog.sh` manda
 * ao Telegram, registrado pela rota com o token de changelog, e a tela que o
 * mostra por sistema e data, com a versão e as tarefas.
 */
class AtualizacoesTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $matriz;

    private const CHANGELOG = "<b>📋 AlfaMatriz — Changelog 03/10/2026</b>\n"
        ."<i>O AlfaMatriz passa a vigiar os erros dos sistemas</i>\n\n"
        ."<b>🔎 Vigia de logs</b>\n\n"
        ."• <b>Erros viram tarefa.</b> O vigia abre Bug sozinho.\n"
        ."---\n"
        ."<b>📋 AlfaMatriz — Changelog 03/10/2026 (2/2)</b>\n\n"
        ."• Parte dois, com <a href=\"https://exemplo.com\">link</a> e <script>alert(1)</script>.\n\n"
        .'<i>AlfaMatriz • Alfa Solucoes Tecnologicas</i>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->matriz = Sistema::factory()->create(['nome' => 'AlfaMatriz', 'slug' => 'alfamatriz']);
    }

    private function tokenDe(User $usuario, array $capacidades = ['changelog']): string
    {
        return $usuario->createToken('teste', $capacidades)->plainTextToken;
    }

    private function registrar(string $token, array $dados): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/atualizacoes', $dados);
    }

    public function test_o_script_registra_com_sistema_data_titulo_versao_e_tarefas(): void
    {
        $autor = User::factory()->create();
        $t1 = Tarefa::factory()->create(['sistema_id' => $this->matriz->id]);
        $t2 = Tarefa::factory()->create(['sistema_id' => $this->matriz->id]);

        $this->registrar($this->tokenDe($autor), [
            'texto' => self::CHANGELOG,
            'versao' => 'v2026.10.03.1',
            'tarefas' => "{$t1->id} {$t2->id} 999999",
            'arquivo' => '2026-10-03-vigia-de-logs.txt',
        ])->assertCreated()
            ->assertJson(['message' => 'registrada', 'sistema' => 'AlfaMatriz', 'data' => '03/10/2026', 'versao' => 'v2026.10.03.1']);

        $atualizacao = Atualizacao::sole();
        $this->assertSame($this->matriz->id, $atualizacao->sistema_id);
        $this->assertSame('O AlfaMatriz passa a vigiar os erros dos sistemas', $atualizacao->titulo);
        $this->assertSame($autor->id, $atualizacao->registrado_por_id);
        $this->assertEqualsCanonicalizing([$t1->id, $t2->id], $atualizacao->tarefas->pluck('id')->all());
        $this->assertCount(2, $atualizacao->partes());
    }

    /**
     * O changelog sai antes da tag: a primeira vez vai sem versão, a segunda
     * traz a versão e as tarefas. Não duplica, e não apaga o que já estava.
     */
    public function test_registrar_de_novo_nao_duplica_e_acrescenta_versao_e_tarefas(): void
    {
        $token = $this->tokenDe(User::factory()->create());
        $tarefa = Tarefa::factory()->create();

        $this->registrar($token, ['texto' => self::CHANGELOG])->assertCreated();
        $this->registrar($token, ['texto' => self::CHANGELOG, 'versao' => 'v1', 'tarefas' => (string) $tarefa->id])
            ->assertOk()->assertJson(['message' => 'já estava registrada', 'versao' => 'v1']);

        $this->assertSame(1, Atualizacao::count());
        $this->assertSame('v1', Atualizacao::sole()->versao);
        $this->assertSame([$tarefa->id], Atualizacao::sole()->tarefas->pluck('id')->all());
    }

    public function test_sem_cabecalho_ou_com_sistema_desconhecido_e_recusado_com_a_frase(): void
    {
        $token = $this->tokenDe(User::factory()->create());

        $this->registrar($token, ['texto' => 'Só um texto solto'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'A primeira linha precisa ser o cabeçalho "📋 <Sistema> — Changelog DD/MM/AAAA".']);

        $this->registrar($token, ['texto' => '<b>📋 AlfaInexistente — Changelog 03/10/2026</b>'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Não há sistema "AlfaInexistente" cadastrado no AlfaMatriz.']);

        $this->assertSame(0, Atualizacao::count());
    }

    /**
     * O token de changelog só abre esta porta, e esta porta só aceita ele: o
     * token do MCP não registra changelog, e sem token nada entra.
     */
    public function test_so_o_token_de_changelog_entra(): void
    {
        $autor = User::factory()->create();

        $this->postJson('/api/atualizacoes', ['texto' => self::CHANGELOG])->assertUnauthorized();
        $this->registrar($this->tokenDe($autor, ['mcp']), ['texto' => self::CHANGELOG])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$this->tokenDe($autor))
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertForbidden();

        $this->assertSame(0, Atualizacao::count());
    }

    /** O token sobrevive a quem o emitiu perder o acesso; a permissão, não. */
    public function test_conta_sem_a_permissao_da_tela_nao_registra(): void
    {
        (new PerfilPermissaoSeeder)->run();
        $exibicao = User::factory()->semPerfil()->create();
        $exibicao->perfis()->attach(Perfil::where('slug', 'exibicao')->value('id'));

        $this->registrar($this->tokenDe($exibicao), ['texto' => self::CHANGELOG])->assertForbidden();

        $desativado = User::factory()->create(['ativo' => false]);
        $this->app['auth']->forgetGuards();
        $this->registrar($this->tokenDe($desativado), ['texto' => self::CHANGELOG])->assertForbidden();

        $this->assertSame(0, Atualizacao::count());
    }

    public function test_a_aba_mostra_o_changelog_com_versao_tarefas_e_html_seguro(): void
    {
        $tarefa = Tarefa::factory()->create(['sistema_id' => $this->matriz->id, 'versao_producao' => 'v2026.10.03.1']);
        $this->registrar($this->tokenDe(User::factory()->create()), ['texto' => self::CHANGELOG, 'versao' => 'v2026.10.03.1'])
            ->assertCreated();

        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('manutencao.index', ['aba' => 'atualizacoes']));

        $resposta->assertOk()
            ->assertSee('AlfaMatriz')
            ->assertSee('03/10/2026')
            ->assertSee('v2026.10.03.1')
            ->assertSee('O AlfaMatriz passa a vigiar os erros dos sistemas')
            // A tarefa entra pela versão do quadro, mesmo sem vir do script.
            ->assertSee($tarefa->codigo())
            ->assertSee('<b>Erros viram tarefa.</b>', false)
            ->assertSee('link (https://exemplo.com)', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_o_comando_emite_o_token_so_de_changelog_e_revoga_sem_tocar_no_do_mcp(): void
    {
        $usuario = User::factory()->create(['email' => 'rossini@exemplo.com']);
        $usuario->createToken('agente', ['mcp']);

        $this->artisan('alfa:changelog-token', ['email' => 'rossini@exemplo.com'])->assertSuccessful();
        $this->assertSame([['mcp'], ['changelog']], $usuario->tokens()->orderBy('id')->pluck('abilities')->all());

        $this->artisan('alfa:changelog-token', ['email' => 'rossini@exemplo.com', '--revogar' => true])->assertSuccessful();
        $this->assertSame([['mcp']], $usuario->tokens()->pluck('abilities')->all());
    }
}
