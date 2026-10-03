<?php

namespace Tests\Feature\Vigia;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\TarefaEvento;
use App\Models\User;
use App\Models\VigiaErro;
use App\Models\VigiaErroHora;
use App\Models\VigiaIgnorado;
use App\Services\Vigia\VigiaDeLogs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as RequisicaoHttp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * O vigia de logs recebendo erros (#219): token, agrupamento, a tarefa de Bug
 * que nasce, o comentário diário, a volta depois de encerrada, o pico, a
 * lista do que ignorar e o Telegram — sempre falsificado aqui.
 */
class RecebimentoDoVigiaTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $sistema;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-03 15:30:00'));

        config([
            'services.telegram.bot_token' => 'bot-de-teste',
            'services.telegram.chat_id_alertas' => '-100123',
            'app.url' => 'https://alfamatriz.exemplo',
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $this->sistema = Sistema::factory()->create(['nome' => 'AlfaGym', 'slug' => 'alfagym']);
        $this->token = $this->emitirToken($this->sistema);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function emitirToken(Sistema $sistema): string
    {
        $this->artisan('alfa:vigia-token', ['sistema' => $sistema->slug])->assertSuccessful();

        // O comando mostra o token e guarda só o hash; para o teste, emite um
        // conhecido do mesmo jeito.
        $segredo = 'segredo-'.$sistema->id;
        $sistema->forceFill(['vigia_token_hash' => hash('sha256', $segredo)])->save();

        return $sistema->id.'|'.$segredo;
    }

    private function erro(array $extra = []): array
    {
        return array_merge([
            'quando' => '2026-10-03T14:00:01-03:00',
            'nivel' => 'WARNING',
            'mensagem' => 'Falha ao validar check-in Wellhub do aluno 8812',
            'excecao' => 'org.springframework.dao.InvalidDataAccessApiUsageException',
            'trecho' => "org.springframework.dao.InvalidDataAccessApiUsageException: No EntityManager\n"
                ."at org.springframework.orm.jpa.SharedEntityManagerCreator.invoke(SharedEntityManagerCreator.java:303)\n"
                .'at br.com.alfa.alfagym.wellhub.WellhubService.validar(WellhubService.java:88)',
        ], $extra);
    }

    private function enviar(array $erros, ?string $token = null, string $ambiente = 'producao'): TestResponse
    {
        return $this->postJson('/api/vigia/erros', [
            'ambiente' => $ambiente,
            'origem' => 'vps-alfagym:docker:alfagym-backend',
            'erros' => $erros,
        ], $token === '' ? [] : ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    private function mensagensDoTelegram(): array
    {
        return Http::recorded()
            ->map(fn ($par) => $par[0])
            ->filter(fn (RequisicaoHttp $r) => str_contains($r->url(), 'api.telegram.org'))
            ->map(fn (RequisicaoHttp $r) => $r['text'])
            ->values()
            ->all();
    }

    // --- Token ---------------------------------------------------------------

    public function test_sem_token_responde_401(): void
    {
        $this->enviar([$this->erro()], '')->assertStatus(401);

        $this->assertSame(0, VigiaErro::count());
    }

    public function test_token_errado_responde_401(): void
    {
        $this->enviar([$this->erro()], $this->sistema->id.'|segredo-errado')->assertStatus(401);
        $this->enviar([$this->erro()], 'sem-formato')->assertStatus(401);
        $this->enviar([$this->erro()], '999999|segredo-'.$this->sistema->id)->assertStatus(401);

        $this->assertSame(0, VigiaErro::count());
    }

    public function test_token_de_um_sistema_nao_serve_para_outro(): void
    {
        $outro = Sistema::factory()->create();
        $outro->forceFill(['vigia_token_hash' => hash('sha256', 'do-outro')])->save();

        // O segredo do outro com o id deste: não passa.
        $this->enviar([$this->erro()], $this->sistema->id.'|do-outro')->assertStatus(401);
    }

    public function test_token_revogado_ou_sistema_desativado_responde_401(): void
    {
        $this->sistema->forceFill(['ativo' => false])->save();
        $this->enviar([$this->erro()])->assertStatus(401);

        $this->sistema->forceFill(['ativo' => true])->save();
        $this->artisan('alfa:vigia-token', ['sistema' => 'alfagym', '--revogar' => true])->assertSuccessful();
        $this->enviar([$this->erro()])->assertStatus(401);
    }

    public function test_token_valido_e_aceito(): void
    {
        $this->enviar([$this->erro()])->assertOk()->assertJson(['message' => 'ok', 'recebidos' => 1, 'assinaturas' => 1]);
    }

    // --- Erro novo -----------------------------------------------------------

    public function test_erro_novo_abre_bug_no_sistema_do_token_com_relato_e_avisa(): void
    {
        $this->enviar([
            $this->erro(),
            $this->erro(['quando' => '2026-10-03T14:10:00-03:00', 'mensagem' => 'Falha ao validar check-in Wellhub do aluno 77']),
        ])->assertOk();

        $this->assertSame(1, Tarefa::count(), 'Duas ocorrências do mesmo erro são UMA tarefa.');

        $tarefa = Tarefa::first();
        $vigia = User::vigiaDeLogs();

        $this->assertSame('bug', $tarefa->tipo);
        $this->assertSame($this->sistema->id, $tarefa->sistema_id);
        $this->assertSame('aberta', $tarefa->status);
        $this->assertNull($tarefa->responsavel_id);
        $this->assertSame('nao_definida', $tarefa->prioridade);
        $this->assertSame($vigia->id, $tarefa->criado_por_id);
        $this->assertSame(VigiaDeLogs::QUEM, $tarefa->defeito_quem);
        $this->assertSame('2026-10-03 14:00', $tarefa->defeito_quando->format('Y-m-d H:i'));
        $this->assertStringContainsString('Falha ao validar check-in Wellhub', $tarefa->titulo);
        $this->assertStringContainsString('2 vezes', $tarefa->resumo);
        $this->assertStringContainsString('produção', $tarefa->resumo);

        // O trecho do stack vai no primeiro comentário, assinado pelo vigia.
        $comentario = $tarefa->comentarios()->first();
        $this->assertSame($vigia->id, $comentario->autor_id);
        $this->assertStringContainsString('WellhubService.validar', $comentario->corpo);
        $this->assertStringContainsString('InvalidDataAccessApiUsageException', $comentario->corpo);

        $erro = VigiaErro::first();
        $this->assertSame(2, $erro->total);
        $this->assertSame($tarefa->id, $erro->tarefa_id);

        $mensagens = $this->mensagensDoTelegram();
        $this->assertCount(1, $mensagens);
        $this->assertStringContainsString('Erro novo no log', $mensagens[0]);
        $this->assertStringContainsString('AlfaGym', $mensagens[0]);
        $this->assertStringContainsString('https://alfamatriz.exemplo/tarefas?tarefa='.$tarefa->id, $mensagens[0]);

        Http::assertSent(fn (RequisicaoHttp $r) => $r['chat_id'] === '-100123' && $r['parse_mode'] === 'HTML');
    }

    public function test_a_conta_do_vigia_existe_desativada_e_sem_perfil(): void
    {
        $vigia = User::vigiaDeLogs();

        $this->assertFalse($vigia->ativo);
        $this->assertSame(0, $vigia->perfis()->count());
        $this->assertFalse($vigia->podeTriarTarefas());
    }

    public function test_mesmo_erro_em_staging_e_outra_assinatura(): void
    {
        $this->enviar([$this->erro()])->assertOk();
        $this->enviar([$this->erro()], null, 'staging')->assertOk();

        $this->assertSame(2, VigiaErro::count());
        $this->assertSame(2, Tarefa::count());
    }

    // --- Erro repetido -------------------------------------------------------

    public function test_erro_repetido_so_conta_e_comenta_no_maximo_uma_vez_por_dia(): void
    {
        $this->enviar([$this->erro()])->assertOk();
        $tarefa = Tarefa::first();
        $comentariosDepoisDeAbrir = $tarefa->comentarios()->count();

        // Uma hora depois: só conta.
        Carbon::setTestNow(now()->addHour());
        $this->enviar([$this->erro(['quando' => now()->subMinutes(5)->toIso8601String()])])->assertOk();

        $this->assertSame(1, Tarefa::count());
        $this->assertSame(2, VigiaErro::first()->total);
        $this->assertSame($comentariosDepoisDeAbrir, $tarefa->comentarios()->count());

        // No dia seguinte: um comentário com a contagem...
        Carbon::setTestNow(now()->addDay());
        $this->enviar([$this->erro(['quando' => now()->subMinutes(5)->toIso8601String()])])->assertOk();

        $this->assertSame($comentariosDepoisDeAbrir + 1, $tarefa->comentarios()->count());
        $ultimo = $tarefa->comentarios()->reorder()->latest('id')->first();
        $this->assertStringContainsString('3 vezes no total', $ultimo->corpo);

        // ...e não outro no lote seguinte.
        Carbon::setTestNow(now()->addHour());
        $this->enviar([$this->erro(['quando' => now()->subMinutes(5)->toIso8601String()])])->assertOk();

        $this->assertSame($comentariosDepoisDeAbrir + 1, $tarefa->comentarios()->count());
        $this->assertCount(1, $this->mensagensDoTelegram(), 'Repetido não avisa no Telegram.');
    }

    public function test_tarefa_arquivada_nao_recebe_o_comentario_diario(): void
    {
        $this->enviar([$this->erro()])->assertOk();
        $tarefa = Tarefa::first();
        $tarefa->forceFill(['arquivada_em' => now(), 'arquivamento_motivo' => 'depois'])->save();
        $antes = $tarefa->comentarios()->count();

        Carbon::setTestNow(now()->addDays(2));
        $this->enviar([$this->erro(['quando' => now()->toIso8601String()])])->assertOk();

        $this->assertSame($antes, $tarefa->comentarios()->count());
        $this->assertNotNull($tarefa->fresh()->arquivada_em, 'O vigia não desarquiva a tarefa.');
    }

    // --- Volta depois de encerrada --------------------------------------------

    public function test_erro_que_volta_depois_da_tarefa_encerrada_abre_tarefa_nova(): void
    {
        $this->enviar([$this->erro()])->assertOk();
        $antiga = Tarefa::first();

        $this->encerrar($antiga, 'concluida', now());

        // Um lote atrasado, com ocorrência de ANTES do encerramento: nada.
        Carbon::setTestNow(now()->addHour());
        $this->enviar([$this->erro(['quando' => now()->subHours(2)->toIso8601String()])])->assertOk();
        $this->assertSame(1, Tarefa::count());

        // Ocorrência depois do encerramento: voltou.
        $this->enviar([$this->erro(['quando' => now()->subMinutes(10)->toIso8601String()])])->assertOk();

        $this->assertSame(2, Tarefa::count());
        $nova = Tarefa::latest('id')->first();
        $this->assertNotSame($antiga->id, $nova->id);
        $this->assertSame('bug', $nova->tipo);
        $this->assertStringContainsString('voltou', mb_strtolower($nova->titulo));
        $this->assertStringContainsString($antiga->codigo(), $nova->resumo);
        $this->assertSame($nova->id, VigiaErro::first()->tarefa_id);

        $mensagens = $this->mensagensDoTelegram();
        $this->assertCount(2, $mensagens);
        $this->assertStringContainsString('Erro voltou no log', $mensagens[1]);
    }

    public function test_tarefa_cancelada_tambem_conta_como_encerrada(): void
    {
        $this->enviar([$this->erro()])->assertOk();
        $this->encerrar(Tarefa::first(), 'cancelada', now());

        Carbon::setTestNow(now()->addHour());
        $this->enviar([$this->erro(['quando' => now()->toIso8601String()])])->assertOk();

        $this->assertSame(2, Tarefa::count());
    }

    public function test_tarefa_excluida_do_quadro_faz_o_erro_abrir_outra(): void
    {
        $this->enviar([$this->erro()])->assertOk();
        Tarefa::first()->forceDelete();

        $this->enviar([$this->erro()])->assertOk();

        $this->assertSame(1, Tarefa::count());
        $this->assertSame(Tarefa::first()->id, VigiaErro::first()->tarefa_id);
    }

    private function encerrar(Tarefa $tarefa, string $etapa, Carbon $quando): void
    {
        $tarefa->forceFill(['status' => $etapa])->save();
        TarefaEvento::create([
            'tarefa_id' => $tarefa->id,
            'de_status' => 'aberta',
            'para_status' => $etapa,
            'entrou_em' => $quando,
        ]);
    }

    // --- Pico ---------------------------------------------------------------

    public function test_pico_avisa_e_respeita_o_intervalo_de_seis_horas(): void
    {
        $this->enviar([$this->erro(['quando' => now()->subDay()->toIso8601String()])])->assertOk();
        $erro = VigiaErro::first();

        // Um por hora nas últimas 24h: média 1/h.
        $horaAtual = now()->startOfHour();
        for ($h = 1; $h <= 24; $h++) {
            VigiaErroHora::updateOrCreate(
                ['vigia_erro_id' => $erro->id, 'hora' => $horaAtual->copy()->subHours($h)->format('Y-m-d H:i:s')],
                ['total' => 1],
            );
        }

        // 19 na hora: abaixo do piso de 20, mesmo sendo 19× a média.
        $this->enviar($this->varios(19))->assertOk();
        $this->assertCount(1, $this->mensagensDoTelegram());

        // Mais 6 (25 na hora): pico.
        $this->enviar($this->varios(6))->assertOk()->assertJson(['picos' => 1]);
        $mensagens = $this->mensagensDoTelegram();
        $this->assertCount(2, $mensagens);
        $this->assertStringContainsString('Pico de erro no log', $mensagens[1]);
        $this->assertStringContainsString('25 vezes', $mensagens[1]);

        // Mais um lote na mesma hora: sem segundo aviso.
        $this->enviar($this->varios(30))->assertOk()->assertJson(['picos' => 0]);
        $this->assertCount(2, $this->mensagensDoTelegram());

        // Sete horas depois, pico de novo: avisa outra vez.
        Carbon::setTestNow(now()->addHours(7));
        $this->enviar($this->varios(200))->assertOk()->assertJson(['picos' => 1]);
        $this->assertCount(3, $this->mensagensDoTelegram());
    }

    private function varios(int $quantos): array
    {
        return array_map(fn (int $i) => $this->erro([
            'quando' => now()->startOfHour()->addSeconds($i)->toIso8601String(),
            'mensagem' => 'Falha ao validar check-in Wellhub do aluno '.(1000 + $i),
        ]), range(1, $quantos));
    }

    // --- Ignorados -----------------------------------------------------------

    public function test_erro_ignorado_so_conta(): void
    {
        VigiaIgnorado::create(['padrao' => 'check-in wellhub']);

        $this->enviar([$this->erro(), $this->erro()])->assertOk()->assertJson(['ignorados' => 1]);

        $this->assertSame(0, Tarefa::count());
        $this->assertSame([], $this->mensagensDoTelegram());
        $this->assertSame(2, VigiaErro::first()->total);
        $this->assertTrue(VigiaErro::first()->ignorado);
    }

    public function test_regex_de_um_sistema_nao_cala_outro(): void
    {
        $outro = Sistema::factory()->create();
        VigiaIgnorado::create(['padrao' => '/Wellhub do aluno \d+/', 'sistema_id' => $outro->id]);

        $this->enviar([$this->erro()])->assertOk();

        $this->assertSame(1, Tarefa::count(), 'O padrão do outro sistema não vale aqui.');
    }

    public function test_tirar_o_padrao_da_lista_volta_a_vigiar(): void
    {
        $ignorado = VigiaIgnorado::create(['padrao' => 'Wellhub']);
        $this->enviar([$this->erro()])->assertOk();
        $this->assertSame(0, Tarefa::count());

        $ignorado->delete();
        $this->enviar([$this->erro()])->assertOk();

        $this->assertSame(1, Tarefa::count());
        $this->assertFalse(VigiaErro::first()->ignorado);
    }

    // --- Telegram -------------------------------------------------------------

    public function test_sem_configuracao_do_telegram_abre_a_tarefa_e_nao_envia(): void
    {
        config(['services.telegram.bot_token' => null, 'services.telegram.chat_id_alertas' => null]);

        $this->enviar([$this->erro()])->assertOk();

        $this->assertSame(1, Tarefa::count());
        Http::assertNothingSent();
    }

    public function test_telegram_fora_do_ar_nao_derruba_o_recebimento(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request'], 400)]);

        $this->enviar([$this->erro()])->assertOk();

        $this->assertSame(1, Tarefa::count());
    }

    public function test_muitos_erros_novos_no_mesmo_lote_resumem_os_avisos(): void
    {
        $erros = array_map(fn (int $i) => $this->erro([
            'mensagem' => "Coluna desconhecida: campo_{$i}x",
            'excecao' => 'Erro'.chr(64 + $i).'Exception',
        ]), range(1, 8));

        $this->enviar($erros)->assertOk();

        $this->assertSame(8, Tarefa::count());
        $mensagens = $this->mensagensDoTelegram();
        $this->assertCount(VigiaDeLogs::MAX_AVISOS_POR_LOTE + 1, $mensagens);
        $this->assertStringContainsString('E mais 3 aviso(s)', end($mensagens));
    }

    // --- Limites ------------------------------------------------------------

    public function test_lote_com_erros_demais_responde_413(): void
    {
        $this->enviar(array_fill(0, VigiaDeLogs::MAX_ERROS_POR_LOTE + 1, $this->erro()))->assertStatus(413);

        $this->assertSame(0, VigiaErro::count());
    }

    public function test_corpo_grande_demais_responde_413(): void
    {
        $corpo = json_encode([
            'ambiente' => 'producao',
            'erros' => [$this->erro(['trecho' => str_repeat('x', 4 * 1024 * 1024)])],
        ]);

        $this->call('POST', '/api/vigia/erros', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], $corpo)->assertStatus(413);
    }

    public function test_campo_comprido_e_cortado_e_nao_recusado(): void
    {
        $this->enviar([$this->erro([
            'mensagem' => str_repeat('m', 5000),
            'trecho' => str_repeat("linha do stack\n", 1000),
        ])])->assertOk();

        $erro = VigiaErro::first();
        $this->assertSame(VigiaDeLogs::LIMITES['mensagem'], mb_strlen($erro->mensagem));
        $this->assertSame(VigiaDeLogs::LIMITES['trecho'], mb_strlen($erro->trecho));
    }

    public function test_lote_invalido_responde_422(): void
    {
        $this->enviar([$this->erro()], null, 'desenvolvimento')->assertStatus(422);
        $this->enviar([['nivel' => 'ERROR']])->assertStatus(422);
        $this->enviar([])->assertStatus(422);
    }

    public function test_utf8_quebrado_no_log_nao_derruba_o_lote(): void
    {
        $corpo = '{"ambiente":"producao","erros":[{"nivel":"ERROR","mensagem":"Arquivo '."\xC3\x28".' sumiu"}]}';

        $this->call('POST', '/api/vigia/erros', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], $corpo)->assertOk();

        $this->assertSame(1, VigiaErro::count());
    }
}
