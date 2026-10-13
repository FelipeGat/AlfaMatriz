<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaPrevia;
use App\Models\CentralIaRunner;
use App\Models\CentralIaTelegramVinculo;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\AgentesService;
use App\Services\CentralIa\TelegramCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Prévias visuais (FR-034 a FR-036). */
class PreviasTest extends TestCase
{
    use RefreshDatabase;

    public function test_maquina_envia_previa_que_aparece_na_resposta_abre_em_sandbox_e_vai_ao_telegram(): void
    {
        Storage::fake('central_ia');
        config(['central_ia.canal_exclusivo' => false, 'central_ia.telegram.token' => 'TESTE']);
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        CentralIaTelegramVinculo::create(['user_id' => $u->id, 'chat_id' => 99, 'vinculado_em' => now()]);
        $r = CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => ['alfahome'], 'visto_em' => now()]);
        $s = app(AgentesService::class);
        $s->pedir($u, 'me traga um modelo conceito da landing para meu ok', ['projeto' => 'alfahome', 'agente' => 'claude', 'modo' => 'auto', 'instrucao' => 'me traga um modelo conceito da landing para meu ok'], 'web', 'v1');
        $job = $s->proxima($u, $r);

        Sanctum::actingAs($u, ['central-ia-runner']);
        $enviar = fn (string $caminho, UploadedFile $f) => $this->post(route('central-ia.runner.previa', $job['id']), ['maquina' => 'wsl', 'caminho' => $caminho, 'arquivo' => $f], ['Accept' => 'application/json']);
        $enviar('conceito.png', UploadedFile::fake()->image('conceito.png'))->assertOk();
        $enviar('pagina/index.html', UploadedFile::fake()->createWithContent('index.html', '<h1>Landing</h1><img src="logo.png">'))->assertOk();
        $enviar('../../.env', UploadedFile::fake()->createWithContent('x.html', 'x'))->assertStatus(422);
        $enviar('script.sh', UploadedFile::fake()->createWithContent('script.sh', 'x'))->assertStatus(422);
        $s->concluir($u, $job['id'], ['status' => 'concluida', 'resposta' => 'Conceito pronto.']);

        $m = collect($this->actingAs($u)->getJson(route('central-ia.mensagens', ['projeto' => 'alfahome']))->json('mensagens'))->firstWhere('autor_tipo', 'agente');
        $this->assertSame(['imagem', 'pagina'], collect($m['previas'])->pluck('tipo')->sort()->values()->all());
        $pagina = collect($m['previas'])->firstWhere('tipo', 'pagina')['url'];

        app("auth")->forgetGuards(); // sem sessão: o link vale pelo código
        $this->get($pagina)->assertOk()->assertHeader('Content-Security-Policy', "sandbox allow-scripts allow-forms allow-popups; frame-ancestors 'none'; connect-src 'none'")->assertHeader('X-Frame-Options', 'DENY')->assertSee('Landing', false);
        $this->get(str_replace('/index.html', '/../../segredo', $pagina))->assertNotFound();
        $this->get(preg_replace('#previas/[A-Za-z0-9]{48}#', 'previas/'.str_repeat('a', 48), $pagina))->assertNotFound();

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        app(TelegramCanal::class)->entregarPrevias();
        Http::assertSent(fn ($req) => str_contains($req->url(), 'sendPhoto'));
        $this->assertSame('enviado', CentralIaPrevia::where('caminho', 'conceito.png')->value('telegram'));
        $this->assertNull(CentralIaPrevia::where('caminho', 'pagina/index.html')->value('telegram'), 'página não vai ao Telegram');
    }
}
