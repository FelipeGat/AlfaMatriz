<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\SuporteConversa;
use App\Models\SuportePedido;
use App\Models\Tarefa;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as Pedido;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** O Pedro no 4042-4157 pelo Gestor (Gestor: docs/specs/whatsapp-pedro), com a IA e o Gestor falsos. */
class PedroNoGestorTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;

    /** @var list<array{acao: string, dados: array}> */
    private array $pedidosAoGestor = [];

    private int $statusEntregar = 200;

    private int $recusarEnvios = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00', 'America/Sao_Paulo'));
        config(['services.whatsapp_suporte' => ['phone_number_id' => '1179318548601637', 'token' => null, 'app_secret' => 'segredo-app', 'verify_token' => 'v', 'graph_version' => 'v20.0'],
            'services.suporte_gestor' => ['url' => 'https://gestor.teste', 'token' => 'segredo-integracao'],
            'services.suporte_pedro.conta' => 'felipe@teste.local', 'services.suporte_pedro.responsaveis' => ['rossini@teste.local']]);
        // Um fake só, com o estado na própria classe: o primeiro stub do Http::fake vale para o teste inteiro.
        Http::fake(function (Pedido $r) {
            if (preg_match('#/whatsapp/midia/([^/?]+)$#', $r->url(), $m)) {
                $this->pedidosAoGestor[] = ['acao' => 'midia', 'dados' => [$m[1]], 'token' => $r->header('Authorization')[0] ?? null];

                return match (true) {
                    str_contains($m[1], 'AUDIO') => Http::response('OGGBYTES', 200, ['Content-Type' => 'audio/ogg']),
                    str_contains($m[1], 'VIDEO') => Http::response('MP4BYTES', 200, ['Content-Type' => 'video/mp4']),
                    default => Http::response('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg']),
                };
            }
            if (preg_match('#/conversas/\d+/(\w+)$#', $r->url(), $m)) {
                $this->pedidosAoGestor[] = ['acao' => $m[1], 'dados' => $r->data(), 'token' => $r->header('Authorization')[0] ?? null];
                if ($m[1] === 'enviar' && $this->recusarEnvios > 0) {
                    $this->recusarEnvios--;

                    return Http::response(['motivo' => 'resposta_superada'], 409);
                }
                if ($m[1] === 'entregar' && $this->statusEntregar !== 200) {
                    return Http::response(['motivo' => 'setor_desligado'], $this->statusEntregar);
                }

                return Http::response($m[1] === 'enviar' ? ['wamid' => 'wamid.G'.count($this->pedidosAoGestor), 'mensagem_id' => 1] : ['ok' => true]);
            }

            return Http::response([], 404);
        });
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['email' => 'felipe@teste.local']);
        User::factory()->create(['name' => 'Rossini', 'email' => 'rossini@teste.local']);
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
    }

    /** Quem escreve já se identificou (T-478); a identificação tem os testes dela. */
    private function identificada(): void
    {
        SuporteConversa::create(['telefone' => '5527988887777', 'cliente_id' => \App\Models\Cliente::create(['nome' => 'Condomínio Joaquim', 'ativo' => true])->id, 'solicitante_nome' => 'Joaquim']);
    }

    private function repasse(string $texto, string $id, array $estado, string $token = 'segredo-integracao', ?string $assinatura = null)
    {
        $valor = ['metadata' => ['phone_number_id' => '1179318548601637'], 'contacts' => [['wa_id' => '5527988887777', 'profile' => ['name' => 'Joaquim']]],
            'messages' => [['from' => '5527988887777', 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $texto]]]];
        $corpo = json_encode(['entry' => [['changes' => [['field' => 'messages', 'value' => $valor]]]]]);

        return $this->call('POST', route('suporte.webhook.gestor'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_X_HUB_SIGNATURE_256' => $assinatura ?? 'sha256='.hash_hmac('sha256', $corpo, 'segredo-app'), 'HTTP_X_GESTOR_ESTADO' => json_encode($estado)], $corpo);
    }

    private function estado(string $setor = 'aguardando_menu', bool $comPedro = true): array
    {
        return ['5527988887777' => ['conversa_id' => 55, 'setor' => $setor, 'com_pedro' => $comPedro, 'suporte_no_matriz' => $setor === 'suporte']];
    }

    private function responde(array $bloco): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $job = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->json();
        $this->postJson(route('suporte.runner.resposta', $job['id']), ['maquina' => 'wsl', 'texto' => "<<<PEDRO\n".json_encode($bloco, JSON_UNESCAPED_UNICODE)."\nPEDRO>>>"])->assertOk();
    }

    public function test_repasse_sem_segredo_ou_assinatura_e_recusado(): void
    {
        $this->repasse('oi', 'wamid.1', $this->estado(), 'errado')->assertStatus(401);
        $this->repasse('oi', 'wamid.1', $this->estado(), 'segredo-integracao', 'sha256=falsa')->assertStatus(403);
        $this->assertSame(0, SuporteConversa::count());
    }

    public function test_repasse_com_pedro_vira_pedido_e_a_resposta_sai_pelo_gestor(): void
    {
        $this->repasse('o portão não abre', 'wamid.1', $this->estado())->assertOk();
        $c = SuporteConversa::sole();
        $this->assertSame([55, 'aguardando_menu'], [$c->gestor_conversa_id, $c->gestor_setor]);
        $this->assertSame(1, SuportePedido::where('situacao', 'pendente')->count());

        $this->responde(['resposta' => 'Olá! Qual portão?', 'acao' => 'responder']);
        $this->assertSame('enviar', $this->pedidosAoGestor[0]['acao']);
        $this->assertSame(['texto' => 'Olá! Qual portão?', 'autor' => 'Pedro (assistente)', 'em_resposta_a' => 'wamid.1'],
            array_intersect_key($this->pedidosAoGestor[0]['dados'], ['texto' => 1, 'autor' => 1, 'em_resposta_a' => 1]));
        $this->assertSame('Bearer segredo-integracao', $this->pedidosAoGestor[0]['token']);
        $this->assertSame('wamid.G1', $c->mensagens()->where('direcao', 'pedro')->value('wamid'));
    }

    /**
     * 22/10/2026, a Jucilene: "Academia mente ativa" e, 4 s depois, "Me chamo Jucilene". A resposta
     * já falava das duas e o Gestor a recusou como superada; sem pedido novo, ela ficou sem resposta
     * e o Gestor mandou o menu.
     */
    public function test_duas_mensagens_seguidas_tem_resposta_mesmo_se_o_gestor_recusar(): void
    {
        $this->repasse('Academia mente ativa', 'wamid.1', $this->estado())->assertOk();
        $this->repasse('Me chamo Jucilene', 'wamid.2', $this->estado())->assertOk();
        $this->responde(['resposta' => 'Muito prazer, Jucilene!', 'acao' => 'responder']);
        $this->assertSame('wamid.2', $this->pedidosAoGestor[0]['dados']['em_resposta_a'], 'responde à última que o Pedro leu');

        // O Gestor recusa (o cliente escreveu enquanto o Pedro pensava): o Pedro tenta de novo.
        $this->repasse('Quanto custa?', 'wamid.3', $this->estado())->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $job = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->json();
        $this->repasse('Tem foto?', 'wamid.4', $this->estado())->assertOk();
        $this->recusarEnvios = 1;
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $this->postJson(route('suporte.runner.resposta', $job['id']), ['maquina' => 'wsl', 'texto' => "<<<PEDRO\n".json_encode(['resposta' => 'Vou te mostrar.', 'acao' => 'responder'])."\nPEDRO>>>"])->assertOk();
        $this->assertSame(1, SuportePedido::where('situacao', 'falhou')->count());
        $this->assertSame(1, SuportePedido::where('situacao', 'pendente')->count(), 'a resposta que não saiu não trava a fila');
    }

    public function test_conversa_que_ja_e_do_financeiro_no_gestor_nao_aciona_o_pedro(): void
    {
        $this->repasse('e o boleto?', 'wamid.1', $this->estado('financeiro', false))->assertOk();
        $this->assertSame('gestor', SuporteConversa::sole()->situacao);
        $this->assertSame(0, SuportePedido::count());
    }

    public function test_encaminhar_ao_financeiro_responde_e_entrega_ao_setor(): void
    {
        $this->identificada();
        $this->repasse('preciso da segunda via do boleto', 'wamid.1', $this->estado())->assertOk();
        $this->responde(['resposta' => 'Vou passar para o financeiro, que continua por aqui.', 'acao' => 'encaminhar_setor', 'setor' => 'financeiro', 'resumo' => '2ª via do boleto']);

        $this->assertSame(['enviar', 'entregar'], array_column($this->pedidosAoGestor, 'acao'));
        $this->assertSame(['setor' => 'financeiro', 'resumo' => '2ª via do boleto'], $this->pedidosAoGestor[1]['dados']);
        $this->assertSame(['gestor', 'financeiro'], [SuporteConversa::sole()->situacao, SuporteConversa::sole()->gestor_setor]);
        $this->assertSame(0, Tarefa::count());
    }

    public function test_setor_recusado_pelo_gestor_vira_atendimento_no_quadro(): void
    {
        $this->statusEntregar = 409;
        $this->repasse('quero um orçamento', 'wamid.1', $this->estado())->assertOk();
        $this->responde(['resposta' => 'Vou passar ao comercial.', 'acao' => 'encaminhar_setor', 'setor' => 'comercial', 'resumo' => 'orçamento de portão']);

        $this->assertStringContainsString('[comercial] orçamento de portão', Tarefa::sole()->resumo);
    }

    public function test_atendimento_de_suporte_marca_a_conversa_no_gestor(): void
    {
        $this->identificada();
        $this->repasse('o sistema não abre', 'wamid.1', $this->estado())->assertOk();
        $this->responde(['resposta' => 'Vou encaminhar para a equipe técnica.', 'acao' => 'abrir_atendimento', 'resumo' => 'Sistema fora do ar']);

        $this->assertSame(['suporte', 'enviar'], array_column($this->pedidosAoGestor, 'acao'));
        $this->assertSame('suporte', SuporteConversa::sole()->gestor_setor);
    }

    public function test_sem_resposta_da_ia_a_reserva_e_do_gestor_no_primeiro_atendimento(): void
    {
        $this->repasse('oi', 'wamid.1', $this->estado())->assertOk();
        Carbon::setTestNow(now()->addMinutes(3));
        $this->artisan('suporte:pedro')->assertSuccessful();

        $this->assertSame('expirado', SuportePedido::sole()->situacao);
        $this->assertSame([], $this->pedidosAoGestor, 'o Gestor manda o menu; daqui não sai nada');
        $this->assertSame('gestor', SuporteConversa::sole()->situacao);

        // Um minuto depois, nada de novo pedido: a conversa está no menu do Gestor.
        Carbon::setTestNow(now()->addMinute());
        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->assertSame(1, SuportePedido::count());

        // O cliente escolhe Suporte no menu: o repasse diz que voltou a ser nossa.
        $this->repasse('3', 'wamid.2', $this->estado('suporte'))->assertOk();
        $this->assertSame('pedro', SuporteConversa::sole()->situacao);
        $this->assertSame(1, SuportePedido::where('situacao', 'pendente')->count());
    }

    public function test_repasse_sem_o_estado_do_contato_so_registra(): void
    {
        $this->repasse('oi', 'wamid.1', ['5527000000000' => $this->estado()['5527988887777']])->assertOk();
        $this->assertSame(1, SuporteConversa::sole()->mensagens()->count());
        $this->assertSame(0, SuportePedido::count());
    }

    private function repasseMidia(string $tipo, string $id): void
    {
        $msg = ['from' => '5527988887777', 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => $tipo, $tipo => ['id' => 'media.'.$id] + ($tipo === 'image' ? ['caption' => 'o visor'] : [])];
        $valor = ['metadata' => ['phone_number_id' => '1179318548601637'], 'contacts' => [['wa_id' => '5527988887777', 'profile' => ['name' => 'Joaquim']]], 'messages' => [$msg]];
        $corpo = json_encode(['entry' => [['changes' => [['field' => 'messages', 'value' => $valor]]]]]);
        $this->call('POST', route('suporte.webhook.gestor'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer segredo-integracao',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $corpo, 'segredo-app'), 'HTTP_X_GESTOR_ESTADO' => json_encode($this->estado())], $corpo)->assertOk();
    }

    public function test_foto_e_audio_do_cliente_vao_ao_pedro_e_a_transcricao_fica_registrada(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->repasse('a catraca apagou', 'wamid.1', $this->estado())->assertOk();
        $this->repasseMidia('image', 'wamid.FOTO');
        $this->repasseMidia('audio', 'wamid.AUDIO');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $job = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->json();

        $foto = \App\Models\SuporteMensagem::where('wamid', 'wamid.FOTO')->sole();
        $audio = \App\Models\SuporteMensagem::where('wamid', 'wamid.AUDIO')->sole();
        $this->assertSame([['id' => $foto->id, 'mime' => 'image/jpeg', 'base64' => base64_encode('JPEGBYTES')]], $job['imagens']);
        $this->assertSame([['id' => $audio->id, 'extensao' => 'ogg', 'base64' => base64_encode('OGGBYTES')]], $job['audios']);
        $this->assertStringContainsString("[FOTO nº {$foto->id} — ANEXADA a esta mensagem: veja-a] legenda: o visor", $job['prompt']);
        $this->assertStringContainsString(sprintf(\App\Services\Suporte\Contexto::AUDIO, $audio->id), $job['prompt']);
        $this->assertSame('Bearer segredo-integracao', collect($this->pedidosAoGestor)->firstWhere('acao', 'midia')['token']);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($foto->fresh()->midia_path);

        $this->postJson(route('suporte.runner.resposta', $job['id']), ['maquina' => 'wsl', 'texto' => "<<<PEDRO\n".json_encode(['resposta' => 'Vi a foto: o visor está apagado.', 'acao' => 'responder'])."\nPEDRO>>>",
            'transcricoes' => [['id' => $audio->id, 'texto' => 'desde cedo está apagada']]])->assertOk();
        $this->assertSame('desde cedo está apagada', $audio->fresh()->transcricao);

        // Já transcrito: no próximo pedido, o áudio aparece como texto e não vai de novo; a foto vem do disco.
        $antes = count($this->pedidosAoGestor);
        $this->repasse('e agora?', 'wamid.2', $this->estado())->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $job2 = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->json();
        $this->assertSame([], $job2['audios']);
        $this->assertStringContainsString('[áudio, transcrito] desde cedo está apagada', $job2['prompt']);
        $this->assertSame(0, collect(array_slice($this->pedidosAoGestor, $antes))->where('acao', 'midia')->count(), 'a foto não é buscada de novo');
    }

    /** Vídeo (20/10/2026): vai ao runner uma vez; o que ele viu fica registrado e não volta. */
    public function test_video_do_cliente_vai_ao_pedro_uma_vez_e_fica_registrado(): void
    {
        $this->identificada();
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->repasse('o portão faz barulho', 'wamid.1', $this->estado())->assertOk();
        $this->repasseMidia('video', 'wamid.VIDEO');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $job = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->json();

        $video = \App\Models\SuporteMensagem::where('wamid', 'wamid.VIDEO')->sole();
        $this->assertSame([['id' => $video->id, 'extensao' => 'mp4', 'base64' => base64_encode('MP4BYTES')]], $job['videos']);
        $this->assertStringContainsString(sprintf(\App\Services\Suporte\Contexto::VIDEO, $video->id), $job['prompt']);
        $this->assertStringContainsString('VÍDEOS: de um vídeo você recebe alguns QUADROS', $job['prompt']);

        $this->postJson(route('suporte.runner.resposta', $job['id']), ['maquina' => 'wsl', 'texto' => "<<<PEDRO\n".json_encode(['resposta' => 'Vi o vídeo: o portão para no meio.', 'acao' => 'responder'])."\nPEDRO>>>",
            'transcricoes' => [['id' => $video->id, 'texto' => '[vídeo visto] ele trava aqui']]])->assertOk();
        $this->assertSame('[vídeo visto] ele trava aqui', $video->fresh()->transcricao);

        $this->repasse('e agora?', 'wamid.2', $this->estado())->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $job2 = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->json();
        $this->assertSame([], $job2['videos'], 'já visto: não vai de novo');
        $this->assertStringContainsString("[vídeo nº {$video->id}, já visto por você antes] [vídeo visto] ele trava aqui", $job2['prompt']);
    }
}
