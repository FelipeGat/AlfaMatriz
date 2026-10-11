<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaAnexo;
use App\Models\CentralIaExecucao;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaRunner;
use App\Models\CentralIaTelegramVinculo;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\AgentesService;
use App\Services\CentralIa\AnexosService;
use App\Services\CentralIa\TelegramCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnexosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.canal_exclusivo' => false]); // Compatibilidade antes da ativação.
        Storage::fake('local');
    }

    private function pessoa(): User
    {
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['codex'], 'projetos' => ['alfamatriz'], 'visto_em' => now()]);
        return $u;
    }

    private function upload(User $u): string
    {
        return $this->actingAs($u)->post(route('central-ia.anexos.criar'), [
            'arquivo' => UploadedFile::fake()->image('tela.png'), 'client_id' => (string) Str::uuid(),
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');
    }

    public function test_upload_download_e_historico_sao_privados_por_conta(): void
    {
        $u = $this->pessoa(); $outro = $this->pessoa(); $id = $this->upload($u);
        $this->actingAs($u)->get(route('central-ia.anexos.baixar', $id))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($outro)->get(route('central-ia.anexos.baixar', $id))->assertNotFound();
        $this->actingAs($u)->postJson(route('central-ia.enviar'), ['client_id' => (string) Str::uuid(), 'anexos' => [$id]])->assertCreated();
        $this->getJson(route('central-ia.mensagens'))->assertJsonPath('mensagens.0.anexos.0.id', $id);
        $this->actingAs($outro)->getJson(route('central-ia.mensagens'))->assertJsonCount(0, 'mensagens');
    }

    public function test_tipo_real_e_tamanho_sao_validados(): void
    {
        $u = $this->pessoa();
        foreach ([UploadedFile::fake()->createWithContent('foto.png', '<?php echo 1;'), UploadedFile::fake()->image('tela.png')->size(20481)] as $file) {
            $this->actingAs($u)->post(route('central-ia.anexos.criar'), ['arquivo' => $file, 'client_id' => (string) Str::uuid()], ['Accept' => 'application/json'])
                ->assertUnprocessable();
        }
        $this->assertSame(0, CentralIaAnexo::count());
    }

    public function test_upload_idempotente_nao_sobrescreve_conteudo(): void
    {
        $u = $this->pessoa(); $file = UploadedFile::fake()->image('tela.png'); $id = (string) Str::uuid();
        app(AnexosService::class)->guardar($u, $file, $id);
        app(AnexosService::class)->guardar($u, $file, $id);
        $this->assertSame(1, CentralIaAnexo::count());
        $this->actingAs($u)->post(route('central-ia.anexos.criar'), ['arquivo' => UploadedFile::fake()->image('outra.png', 20, 20), 'client_id' => $id], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_anexo_de_outra_conta_recusa_e_reverte_pedido(): void
    {
        $u = $this->pessoa(); $id = $this->upload($this->pessoa());
        $this->actingAs($u)->postJson(route('central-ia.enviar'), ['conteudo' => 'Analise a tela', 'client_id' => (string) Str::uuid(),
            'anexos' => [$id], 'agente' => 'codex', 'projeto' => 'alfamatriz'])->assertUnprocessable();
        $this->assertSame(0, CentralIaExecucao::count());
        $this->assertSame(0, CentralIaMensagem::count());
    }

    public function test_reenvio_preserva_anexos_e_nao_duplica_execucao(): void
    {
        $u = $this->pessoa(); $id = $this->upload($u);
        $dados = ['conteudo' => 'Analise a tela', 'client_id' => (string) Str::uuid(), 'anexos' => [$id], 'agente' => 'codex', 'projeto' => 'alfamatriz'];
        $this->actingAs($u)->postJson(route('central-ia.enviar'), $dados)->assertCreated();
        $this->postJson(route('central-ia.enviar'), $dados)->assertOk();
        $this->postJson(route('central-ia.enviar'), array_replace($dados, ['anexos' => []]))->assertUnprocessable();
        $this->assertSame(1, CentralIaExecucao::count());
        $job = app(AgentesService::class)->proxima($u, CentralIaRunner::where('user_id', $u->id)->first());
        $this->assertSame($id, $job['anexos'][0]['id']);
        Sanctum::actingAs($u, ['central-ia-runner']);
        $this->get(route('central-ia.runner.anexo', $id))->assertOk();
        Sanctum::actingAs($this->pessoa(), ['central-ia-runner']);
        $this->get(route('central-ia.runner.anexo', $id))->assertNotFound();
    }

    public function test_runner_nao_baixa_arquivo_que_ainda_nao_foi_enviado(): void
    {
        $u = $this->pessoa(); $id = $this->upload($u);
        Sanctum::actingAs($u, ['central-ia-runner']);
        $this->get(route('central-ia.runner.anexo', $id))->assertNotFound();
    }

    public function test_telegram_recebe_foto_e_sinaliza_ate_a_conclusao(): void
    {
        config(['central_ia.telegram.token' => 'token-teste']);
        $u = $this->pessoa();
        CentralIaTelegramVinculo::create(['user_id' => $u->id, 'chat_id' => 100, 'projeto_atual' => 'alfamatriz', 'agente_atual' => 'codex']);
        $file = UploadedFile::fake()->image('tela.jpg');
        Http::fake(['api.telegram.org/*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/test.jpg']]),
            'api.telegram.org/file/*' => Http::response(file_get_contents($file->getRealPath())),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $update = ['update_id' => 123, 'message' => ['chat' => ['type' => 'private', 'id' => 100], 'from' => ['id' => 100, 'is_bot' => false],
            'photo' => [['file_id' => 'foto', 'file_size' => 100]], 'caption' => 'Analise esta tela']];
        $canal = app(TelegramCanal::class);
        $canal->receber($update); $canal->receber($update);
        $this->assertSame(1, CentralIaAnexo::count());
        $this->assertSame(1, CentralIaExecucao::count());
        $this->assertNotNull(CentralIaAnexo::first()->mensagem_id);
        $canal->sinalizarAtividade();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendChatAction') && $r['action'] === 'typing');
        $antes = count(Http::recorded());
        CentralIaExecucao::query()->update(['status' => 'concluida']);
        $canal->sinalizarAtividade();
        $this->assertCount($antes, Http::recorded());
    }
}
