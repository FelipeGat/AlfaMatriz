<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Notificacao;
use App\Models\Tarefa;
use App\Models\TarefaReferenciaGit;
use App\Models\User;
use App\Services\FluxoTarefaService;
use App\Services\ReferenciasDoGitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * O GitHub alimentando o quadro (#211): commits e PRs que citam `T-N` viram
 * referências na tarefa, o modal as mostra e a entrega nasce pré-preenchida.
 */
class CodigoDoGitHubTest extends TestCase
{
    use RefreshDatabase;

    private const SEGREDO = 'segredo-de-teste';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.github.webhook_secret' => self::SEGREDO]);
    }

    private function emAndamento(array $atributos = []): array
    {
        $dono = User::factory()->create(['name' => 'Alexandre Blank']);

        $tarefa = Tarefa::factory()->create(array_merge([
            'criado_por_id' => $dono->id,
            'responsavel_id' => $dono->id,
            'status' => 'em_desenvolvimento',
            'titulo' => 'Boleto duplicado',
        ], $atributos));

        return [$tarefa, $dono];
    }

    /** Manda o evento como o GitHub manda: corpo cru, assinado. */
    private function webhook(string $evento, array $carga, ?string $assinatura = null): TestResponse
    {
        $corpo = json_encode($carga);
        $assinatura ??= 'sha256='.hash_hmac('sha256', $corpo, self::SEGREDO);

        return $this->call('POST', '/github/webhook', [], [], [], array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $evento,
            'HTTP_X_GITHUB_DELIVERY' => 'abc-123',
            'HTTP_X_HUB_SIGNATURE_256' => $assinatura,
        ]), $corpo);
    }

    private function push(array $commits, string $branch = 'alexandre', string $repo = 'FelipeGat/AlfaGym'): array
    {
        return [
            'ref' => 'refs/heads/'.$branch,
            'repository' => ['full_name' => $repo],
            'commits' => array_map(fn (array $c) => $c + [
                'url' => 'https://github.com/'.$repo.'/commit/'.$c['id'],
                'author' => ['name' => 'Alexandre', 'username' => 'alexandre-dev'],
            ], $commits),
        ];
    }

    private function pr(string $acao, int $numero, string $titulo, array $extra = []): array
    {
        return [
            'action' => $acao,
            'number' => $numero,
            'repository' => ['full_name' => 'FelipeGat/AlfaGym'],
            'pull_request' => array_merge([
                'number' => $numero,
                'title' => $titulo,
                'body' => '',
                'state' => 'open',
                'merged' => false,
                'html_url' => 'https://github.com/FelipeGat/AlfaGym/pull/'.$numero,
                'user' => ['login' => 'alexandre-dev'],
                'head' => ['ref' => 'alexandre-fixes'],
            ], $extra),
        ];
    }

    // --- A porta -----------------------------------------------------------

    public function test_a_rota_recusa_assinatura_invalida_ausente_e_segredo_vazio(): void
    {
        [$tarefa] = $this->emAndamento();
        $carga = $this->push([['id' => str_repeat('a', 40), 'message' => "Corrige T-{$tarefa->id}"]]);

        $this->webhook('push', $carga, 'sha256='.hash_hmac('sha256', json_encode($carga), 'outro'))->assertForbidden();
        $this->webhook('push', $carga, '')->assertForbidden();

        // Sem segredo configurado, nem a assinatura "certa" para segredo vazio passa.
        config(['services.github.webhook_secret' => '']);
        $this->webhook('push', $carga, 'sha256='.hash_hmac('sha256', json_encode($carga), ''))->assertForbidden();

        $this->assertSame(0, TarefaReferenciaGit::count());
    }

    public function test_a_rota_fica_fora_do_csrf_e_da_sessao_e_responde_ao_ping(): void
    {
        $this->webhook('ping', ['zen' => 'Keep it logically awesome.'])
            ->assertOk()
            ->assertJson(['message' => 'pong']);

        // Evento que o quadro não trata: 2xx, sem fazer nada.
        $this->webhook('issues', ['action' => 'opened'])->assertOk()->assertJson(['referencias' => 0]);

        $this->assertNotContains('web', app('router')->getRoutes()->getByName('github.webhook')->gatherMiddleware());
    }

    // --- A marca -----------------------------------------------------------

    public function test_a_marca_e_t_n_com_fronteira_de_palavra(): void
    {
        $this->assertSame([173], ReferenciasDoGitHub::marcas('Corrige o boleto (T-173)'));
        $this->assertSame([173], ReferenciasDoGitHub::marcas('t-173: minúscula também'));
        $this->assertSame([12, 40], ReferenciasDoGitHub::marcas('T-12 e T-40, e de novo T-12'));
        $this->assertSame([1234], ReferenciasDoGitHub::marcas('T-1234'), 'T-1234 é a 1234, nunca a 123.');
        $this->assertSame([], ReferenciasDoGitHub::marcas('Merge do PR #173 (#74)'), '#N é PR do GitHub.');
        $this->assertSame([], ReferenciasDoGitHub::marcas('AT-12 e XT-5 são outra coisa'));
        $this->assertSame([], ReferenciasDoGitHub::marcas('T-12a T-'));
    }

    // --- push --------------------------------------------------------------

    public function test_o_push_liga_o_commit_a_tarefa_citada_e_e_idempotente(): void
    {
        [$tarefa] = $this->emAndamento();
        $sha = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
        $carga = $this->push([
            ['id' => $sha, 'message' => "Corrige o boleto duplicado T-{$tarefa->id}\n\nDetalhe longo do commit."],
            ['id' => str_repeat('f', 40), 'message' => 'Commit sem marca, do PR #{$tarefa->id}'],
        ]);

        $this->webhook('push', $carga)->assertOk()->assertJson(['referencias' => 1]);

        $ref = TarefaReferenciaGit::sole();
        $this->assertSame($tarefa->id, $ref->tarefa_id);
        $this->assertSame('commit', $ref->tipo);
        $this->assertSame('a1b2c3d', $ref->shaCurto());
        $this->assertSame('FelipeGat/AlfaGym', $ref->repositorio);
        $this->assertSame('alexandre-dev', $ref->autor_github);
        $this->assertSame('alexandre', $ref->branch);
        $this->assertSame('Corrige o boleto duplicado T-'.$tarefa->id, $ref->titulo, 'Só a primeira linha.');
        $this->assertSame('https://github.com/FelipeGat/AlfaGym/commit/'.$sha, $ref->url);

        // O mesmo commit de novo — reentrega, merge na main, outro repositório.
        $this->webhook('push', $carga)->assertOk();
        $this->webhook('push', $this->push([['id' => $sha, 'message' => "Corrige T-{$tarefa->id}"]], 'main'))->assertOk();

        $this->assertSame(1, TarefaReferenciaGit::count());
    }

    public function test_um_commit_com_varias_marcas_liga_cada_tarefa(): void
    {
        [$uma] = $this->emAndamento();
        [$outra] = $this->emAndamento();

        $this->webhook('push', $this->push([
            ['id' => str_repeat('b', 40), 'message' => "Ajusta T-{$uma->id} e T-{$outra->id}"],
        ]))->assertOk();

        $this->assertSame(1, $uma->referenciasGit()->count());
        $this->assertSame(1, $outra->referenciasGit()->count());
    }

    public function test_tarefa_inexistente_ou_encerrada_e_ignorada_sem_erro(): void
    {
        [$concluida] = $this->emAndamento(['status' => 'concluida']);
        [$cancelada] = $this->emAndamento(['status' => 'cancelada']);

        $this->webhook('push', $this->push([
            ['id' => str_repeat('c', 40), 'message' => "T-{$concluida->id} T-{$cancelada->id} T-999999"],
        ]))->assertOk()->assertJson(['referencias' => 0]);

        $this->webhook('pull_request', $this->pr('opened', 9, "T-{$concluida->id} T-999999"))->assertOk();

        $this->assertSame(0, TarefaReferenciaGit::count());
        $this->assertSame(0, Notificacao::count());
    }

    public function test_push_de_tag_nao_liga_nada(): void
    {
        [$tarefa] = $this->emAndamento();
        $carga = $this->push([['id' => str_repeat('d', 40), 'message' => "T-{$tarefa->id}"]]);
        $carga['ref'] = 'refs/tags/v2026.10.02.1';

        $this->webhook('push', $carga)->assertOk();

        $this->assertSame(0, TarefaReferenciaGit::count());
    }

    // --- pull_request ------------------------------------------------------

    public function test_pr_aberto_liga_e_avisa_o_responsavel_sem_mover_a_tarefa(): void
    {
        [$tarefa, $dono] = $this->emAndamento();

        $this->webhook('pull_request', $this->pr('opened', 76, 'Boleto duplicado', ['body' => "Fecha T-{$tarefa->id}"]))
            ->assertOk();

        $pr = TarefaReferenciaGit::sole();
        $this->assertTrue($pr->ehPr());
        $this->assertSame(76, $pr->numero);
        $this->assertSame('aberto', $pr->estado);
        $this->assertSame('FelipeGat/AlfaGym#76', $pr->chave);
        $this->assertSame('https://github.com/FelipeGat/AlfaGym/pull/76', $pr->url);

        $this->assertSame('em_desenvolvimento', $tarefa->fresh()->status, 'Abrir PR não move a tarefa.');

        $aviso = Notificacao::sole();
        $this->assertSame($dono->id, $aviso->destinatario_id);
        $this->assertSame($tarefa->id, $aviso->tarefa_id);
        $this->assertSame('PR aberto em «Boleto duplicado»', $aviso->titulo);
        $this->assertStringContainsString('mande para revisão com a entrega', $aviso->meta);

        // Reentregar o mesmo evento não duplica a referência.
        $this->webhook('pull_request', $this->pr('edited', 76, "Boleto duplicado T-{$tarefa->id}"))->assertOk();
        $this->assertSame(1, TarefaReferenciaGit::count());
    }

    public function test_pr_aberto_fora_da_bancada_nao_avisa(): void
    {
        [$tarefa] = $this->emAndamento(['status' => 'em_revisao']);

        $this->webhook('pull_request', $this->pr('opened', 5, "T-{$tarefa->id}"))->assertOk();

        $this->assertSame(1, TarefaReferenciaGit::count());
        $this->assertSame(0, Notificacao::count());
    }

    public function test_pr_mesclado_atualiza_o_estado_mesmo_sem_a_marca_no_titulo(): void
    {
        [$tarefa] = $this->emAndamento();

        $this->webhook('pull_request', $this->pr('opened', 80, "Ajuste T-{$tarefa->id}"))->assertOk();
        // A marca saiu do título na edição, e depois o PR foi mesclado.
        $this->webhook('pull_request', $this->pr('closed', 80, 'Ajuste', ['state' => 'closed', 'merged' => true]))->assertOk();

        $this->assertSame('mesclado', TarefaReferenciaGit::sole()->estado);

        $this->webhook('pull_request', $this->pr('opened', 81, "Outro T-{$tarefa->id}"))->assertOk();
        $this->webhook('pull_request', $this->pr('closed', 81, "Outro T-{$tarefa->id}", ['state' => 'closed']))->assertOk();

        $this->assertSame('fechado', TarefaReferenciaGit::where('numero', 81)->sole()->estado);
    }

    // --- O quadro ----------------------------------------------------------

    public function test_o_modal_mostra_a_secao_codigo_com_links_escapados(): void
    {
        [$tarefa, $dono] = $this->emAndamento();

        $this->webhook('pull_request', $this->pr('opened', 76, "<b>Boleto</b> T-{$tarefa->id}"))->assertOk();
        $this->webhook('push', $this->push([
            ['id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678', 'message' => "Corrige T-{$tarefa->id}"],
        ]))->assertOk();
        // Um endereço que não é http não vira link.
        TarefaReferenciaGit::create([
            'tarefa_id' => $tarefa->id, 'tipo' => 'commit', 'repositorio' => 'x/y', 'chave' => str_repeat('e', 40),
            'titulo' => 'Malicioso', 'url' => 'javascript:alert(1)',
        ]);

        $modal = $this->actingAs($dono)->get(route('tarefas.modal', $tarefa))->assertOk()->getContent();

        $this->assertStringContainsString('>Código</h4>', $modal);
        $this->assertStringContainsString('href="https://github.com/FelipeGat/AlfaGym/pull/76"', $modal);
        $this->assertStringContainsString('PR #76', $modal);
        $this->assertStringContainsString('Aberto', $modal);
        $this->assertStringContainsString('a1b2c3d', $modal);
        $this->assertStringContainsString('&lt;b&gt;Boleto&lt;/b&gt;', $modal);
        $this->assertStringNotContainsString('<b>Boleto</b>', $modal);
        $this->assertStringNotContainsString('javascript:alert', $modal);
    }

    public function test_sem_codigo_o_modal_nao_mostra_a_secao(): void
    {
        [$tarefa, $dono] = $this->emAndamento();

        $modal = $this->actingAs($dono)->get(route('tarefas.modal', $tarefa))->assertOk()->getContent();

        $this->assertStringNotContainsString('>Código</h4>', $modal);
    }

    /**
     * O painel do envio para a revisão é um só para o arrasto, o menu e o
     * modal (`abrirPendente`), e pede a sugestão à rota quando abre.
     */
    public function test_o_painel_da_entrega_vem_pre_preenchido_desde_a_ultima_entrega(): void
    {
        [$tarefa, $dono] = $this->emAndamento();

        $this->actingAs($dono)->getJson(route('tarefas.pr-commits', $tarefa))
            ->assertOk()->assertExactJson(['texto' => '']);

        Carbon::setTestNow('2026-10-02 10:00:00');
        $this->webhook('pull_request', $this->pr('opened', 76, "T-{$tarefa->id}"))->assertOk();
        $this->webhook('push', $this->push([
            ['id' => 'a1b2c3d'.str_repeat('0', 33), 'message' => "Primeira T-{$tarefa->id}"],
            ['id' => 'b2c3d4e'.str_repeat('0', 33), 'message' => "Segunda T-{$tarefa->id}"],
        ]))->assertOk();
        $this->webhook('pull_request', $this->pr('opened', 77, "Abandonado T-{$tarefa->id}", ['state' => 'closed']))->assertOk();

        $this->actingAs($dono)->getJson(route('tarefas.pr-commits', $tarefa))
            ->assertOk()
            ->assertExactJson(['texto' => "https://github.com/FelipeGat/AlfaGym/pull/76\nCommits: a1b2c3d, b2c3d4e"]);

        // A 1ª entrega leva tudo; a correção traz um commit novo no MESMO PR.
        Carbon::setTestNow('2026-10-02 11:00:00');
        $this->actingAs($dono);
        (new FluxoTarefaService)->mover($tarefa->fresh(), 'em_revisao', [
            'o_que_foi_feito' => 'Algo.', 'como_testar' => 'Assim.', 'pr_commits' => 'o PR 76',
        ]);
        (new FluxoTarefaService)->mover($tarefa->fresh(), 'em_desenvolvimento', ['motivo' => 'Faltou o caso X.'], livre: true);

        Carbon::setTestNow('2026-10-02 12:00:00');
        $this->webhook('push', $this->push([
            ['id' => 'c3d4e5f'.str_repeat('0', 33), 'message' => "Caso X T-{$tarefa->id}"],
        ], 'alexandre-fixes'))->assertOk();
        $this->webhook('pull_request', $this->pr('synchronize', 76, "T-{$tarefa->id}"))->assertOk();

        $this->actingAs($dono)->getJson(route('tarefas.pr-commits', $tarefa))
            ->assertOk()
            ->assertExactJson(['texto' => "https://github.com/FelipeGat/AlfaGym/pull/76\nCommits: c3d4e5f"]);

        Carbon::setTestNow();
    }

    public function test_o_quadro_pede_a_sugestao_ao_abrir_o_painel_da_entrega(): void
    {
        [, $dono] = $this->emAndamento();

        $html = $this->actingAs($dono)->get(route('tarefas.index'))->assertOk()->getContent();

        $this->assertStringContainsString('rotaPrCommits:', $html);
        $this->assertStringContainsString('this.preencherPrCommits(this.pendente)', $html);
        $this->assertStringContainsString('x-model="entregaPendente.pr"', $html);
    }
}
