<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaAnexo;
use App\Models\CentralIaConversa;
use App\Models\CentralIaEntrega;
use App\Models\CentralIaExecucao;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaPrevia;
use App\Models\CentralIaTarefa;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\Limpeza;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T-378: o que passou do prazo sai; o que está no prazo, inclusive no limite
 * exato, fica.
 */
class LimpezaTest extends TestCase
{
    use RefreshDatabase;

    private User $u;

    private int $conversa;

    private Carbon $agora;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('central_ia');
        $this->agora = Carbon::parse('2026-10-14 03:30:00');
        $this->travelTo($this->agora);
        $this->u = User::factory()->create();
        $this->conversa = CentralIaConversa::create(['user_id' => $this->u->id])->id;
    }

    private function idade(string $tabela, $id, Carbon $quando): void
    {
        DB::table($tabela)->where('id', $id)->update(['created_at' => $quando]);
    }

    private function mensagem(Carbon $quando): CentralIaMensagem
    {
        $m = CentralIaMensagem::create(['conversa_id' => $this->conversa, 'user_id' => $this->u->id, 'projeto' => '',
            'autor_tipo' => CentralIaMensagem::AUTOR_USUARIO, 'origem' => CentralIaMensagem::ORIGEM_WEB, 'conteudo' => 'oi']);
        $this->idade('central_ia_mensagens', $m->id, $quando);

        return $m;
    }

    private function execucao(Carbon $quando, string $status = CentralIaExecucao::CONCLUIDA): CentralIaExecucao
    {
        $pedido = $this->mensagem($quando);
        $resposta = $this->mensagem($quando);
        $e = CentralIaExecucao::create(['user_id' => $this->u->id, 'pedido_id' => $pedido->id, 'resposta_id' => $resposta->id,
            'projeto' => 'alfamatriz', 'agente' => 'claude', 'modo' => 'analisar', 'instrucao' => 'x', 'status' => $status]);
        $this->idade('central_ia_execucoes', $e->id, $quando);

        return $e;
    }

    private function evento(Carbon $quando, ?int $execucao = null): int
    {
        return DB::table('central_ia_eventos')->insertGetId(['user_id' => $this->u->id, 'tipo' => 'teste',
            'execucao_id' => $execucao, 'descricao' => 'x', 'created_at' => $quando]);
    }

    private function anexo(Carbon $quando, ?int $mensagem = null): CentralIaAnexo
    {
        $id = (string) Str::uuid();
        $caminho = "central-ia/{$this->u->id}/{$id}";
        Storage::disk('central_ia')->put($caminho, 'png');
        $a = CentralIaAnexo::create(['id' => $id, 'user_id' => $this->u->id, 'mensagem_id' => $mensagem, 'nome' => 'tela.png',
            'mime' => 'image/png', 'tamanho' => 3, 'caminho' => $caminho, 'sha256' => str_repeat('a', 64)]);
        $this->idade('central_ia_anexos', $id, $quando);

        return $a;
    }

    private function previa(Carbon $quando, CentralIaExecucao $e): CentralIaPrevia
    {
        $p = CentralIaPrevia::create(['user_id' => $this->u->id, 'execucao_id' => $e->id, 'chave' => Str::random(40),
            'caminho' => 'tela.png', 'mime' => 'image/png', 'tamanho' => 3, 'sha256' => str_repeat('a', 64)]);
        Storage::disk('central_ia')->put("central-ia/previas/{$this->u->id}/{$e->id}/tela.png", 'png');
        $this->idade('central_ia_previas', $p->id, $quando);

        return $p;
    }

    public function test_anexo_com_exatos_90_dias_fica_e_com_um_segundo_a_mais_sai_com_o_arquivo(): void
    {
        $limite = $this->anexo($this->agora->copy()->subDays(90));
        $vencido = $this->anexo($this->agora->copy()->subDays(90)->subSecond());
        $semArquivo = $this->anexo($this->agora->copy()->subDays(200));
        Storage::disk('central_ia')->delete($semArquivo->caminho);

        $this->artisan('central-ia:limpar')->assertSuccessful();

        $this->assertTrue(CentralIaAnexo::whereKey($limite->id)->exists());
        Storage::disk('central_ia')->assertExists($limite->caminho);
        $this->assertFalse(CentralIaAnexo::whereKey($vencido->id)->exists());
        Storage::disk('central_ia')->assertMissing($vencido->caminho);
        // Arquivo que já não estava lá não segura a linha.
        $this->assertFalse(CentralIaAnexo::whereKey($semArquivo->id)->exists());
    }

    public function test_previa_vencida_sai_com_o_arquivo_e_a_pasta_do_pedido(): void
    {
        $e = $this->execucao($this->agora->copy()->subDays(10));
        $limite = $this->previa($this->agora->copy()->subDays(90), $this->execucao($this->agora->copy()->subDays(10)));
        $vencida = $this->previa($this->agora->copy()->subDays(90)->subSecond(), $e);

        $this->artisan('central-ia:limpar')->assertSuccessful();

        $this->assertTrue(CentralIaPrevia::whereKey($limite->id)->exists());
        $this->assertFalse(CentralIaPrevia::whereKey($vencida->id)->exists());
        Storage::disk('central_ia')->assertMissing("central-ia/previas/{$this->u->id}/{$e->id}");
        // O pedido de 10 dias continua: só o arquivo passou do prazo.
        $this->assertTrue(CentralIaExecucao::whereKey($e->id)->exists());
    }

    public function test_historico_com_exato_um_ano_fica_e_com_um_segundo_a_mais_sai(): void
    {
        $limite = $this->agora->copy()->subDays(365);
        $vencido = $limite->copy()->subSecond();

        $eFica = $this->execucao($limite);
        $eSai = $this->execucao($vencido);
        $evFica = $this->evento($limite);
        $evSai = $this->evento($vencido, $eSai->id);
        $evNovoDoPedidoVelho = $this->evento($this->agora->copy()->subDay(), $eSai->id);
        $mFica = $this->mensagem($limite);
        $mSai = $this->mensagem($vencido);
        $enFica = CentralIaEntrega::create(['mensagem_id' => $mFica->id, 'user_id' => $this->u->id, 'chat_id' => 1, 'status' => 'entregue']);
        $this->idade('central_ia_entregas', $enFica->id, $limite);
        $enSai = CentralIaEntrega::create(['mensagem_id' => $mSai->id, 'user_id' => $this->u->id, 'chat_id' => 1, 'status' => 'entregue']);
        $this->idade('central_ia_entregas', $enSai->id, $vencido);
        $anexoDaMensagem = $this->anexo($vencido, $mSai->id);

        $this->artisan('central-ia:limpar')->assertSuccessful();

        $this->assertTrue(CentralIaExecucao::whereKey($eFica->id)->exists());
        $this->assertTrue(CentralIaMensagem::whereKey([$eFica->pedido_id, $eFica->resposta_id, $mFica->id])->count() === 3);
        $this->assertTrue(DB::table('central_ia_eventos')->where('id', $evFica)->exists());
        $this->assertTrue(CentralIaEntrega::whereKey($enFica->id)->exists());

        $this->assertFalse(CentralIaExecucao::whereKey($eSai->id)->exists());
        $this->assertSame(0, CentralIaMensagem::whereKey([$eSai->pedido_id, $eSai->resposta_id, $mSai->id])->count());
        $this->assertFalse(DB::table('central_ia_eventos')->where('id', $evSai)->exists());
        $this->assertFalse(CentralIaEntrega::whereKey($enSai->id)->exists());
        $this->assertFalse(CentralIaAnexo::whereKey($anexoDaMensagem->id)->exists());
        Storage::disk('central_ia')->assertMissing($anexoDaMensagem->caminho);
        // Evento recente fica, só perde o vínculo com o pedido que saiu.
        $this->assertNull(DB::table('central_ia_eventos')->where('id', $evNovoDoPedidoVelho)->value('execucao_id'));
    }

    public function test_pedido_pendente_ou_executando_nunca_sai_nem_a_mensagem_dele(): void
    {
        $velho = $this->agora->copy()->subYears(2);
        $pendente = $this->execucao($velho, CentralIaExecucao::PENDENTE);
        $executando = $this->execucao($velho, CentralIaExecucao::EXECUTANDO);

        $this->artisan('central-ia:limpar')->assertSuccessful();

        $this->assertSame(2, CentralIaExecucao::count());
        $this->assertSame(4, CentralIaMensagem::whereIn('id', [$pendente->pedido_id, $pendente->resposta_id, $executando->pedido_id, $executando->resposta_id])->count());
    }

    public function test_mensagem_vencida_com_entrega_recente_fica(): void
    {
        $m = $this->mensagem($this->agora->copy()->subYears(2));
        CentralIaEntrega::create(['mensagem_id' => $m->id, 'user_id' => $this->u->id, 'chat_id' => 1, 'status' => 'pendente']);

        $this->artisan('central-ia:limpar')->assertSuccessful();

        $this->assertTrue(CentralIaMensagem::whereKey($m->id)->exists());
    }

    public function test_dry_run_conta_o_mesmo_que_a_volta_de_verdade_e_nao_apaga_nada(): void
    {
        $velho = $this->agora->copy()->subYears(2);
        $e = $this->execucao($velho);
        $this->previa($velho, $e);
        $this->evento($velho, $e->id);
        $m = $this->mensagem($velho);
        $this->anexo($velho, $m->id);
        $this->anexo($this->agora->copy()->subDays(100));
        CentralIaEntrega::create(['mensagem_id' => $m->id, 'user_id' => $this->u->id, 'chat_id' => 1, 'status' => 'entregue']);
        $this->idade('central_ia_entregas', CentralIaEntrega::value('id'), $velho);
        $this->mensagem($this->agora->copy()->subDay());

        $ensaio = app(Limpeza::class)->executar(true);
        $this->assertSame(4, CentralIaMensagem::count());
        $this->assertSame(2, CentralIaAnexo::count());
        $this->assertSame(1, CentralIaPrevia::count());

        $this->artisan('central-ia:limpar --dry-run')->expectsOutputToContain('nada foi apagado')->assertSuccessful();
        $this->assertSame(4, CentralIaMensagem::count());

        $real = app(Limpeza::class)->executar();
        $this->assertSame(['anexos' => 2, 'previas' => 1, 'eventos' => 1, 'entregas' => 1, 'execucoes' => 1, 'mensagens' => 3], $real);
        $this->assertSame($real, $ensaio);
        $this->assertSame(1, CentralIaMensagem::count());
    }

    public function test_prazo_abaixo_do_minimo_recusa_e_nao_apaga_nada(): void
    {
        config(['central_ia.retencao.historico_dias' => 0]);
        $this->mensagem($this->agora->copy()->subYears(2));

        $this->artisan('central-ia:limpar')->assertFailed();

        $this->assertSame(1, CentralIaMensagem::count());
    }

    public function test_os_prazos_aparecem_nos_ajustes(): void
    {
        $this->u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        config(['central_ia.retencao.anexos_dias' => 91]);

        $this->actingAs($this->u)->get(route('central-ia.index'))->assertOk()
            ->assertSee('Por quanto tempo a Central guarda')->assertSee('Anexos e prévias: 91 dias')->assertSee('365 dias');
    }

    public function test_batida_diz_a_maquina_quais_tarefas_com_worktree_estao_encerradas(): void
    {
        $this->u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        $outro = User::factory()->create();
        $t = fn (User $dono, string $status, ?string $wt) => CentralIaTarefa::create(['user_id' => $dono->id, 'projeto' => 'app',
            'titulo' => 'x', 'objetivo' => 'x', 'status' => $status, 'worktree' => $wt])->id;
        $concluida = $t($this->u, CentralIaTarefa::CONCLUIDA, '/p/.central-ia/worktrees/app-t1');
        $arquivada = $t($this->u, CentralIaTarefa::ARQUIVADA, '/p/.central-ia/worktrees/app-t2');
        $t($this->u, CentralIaTarefa::ABERTA, '/p/.central-ia/worktrees/app-t3');
        $t($this->u, CentralIaTarefa::PAUSADA, '/p/.central-ia/worktrees/app-t4');
        $t($this->u, CentralIaTarefa::CONCLUIDA, null);
        $t($outro, CentralIaTarefa::CONCLUIDA, '/q/.central-ia/worktrees/app-t6');
        Sanctum::actingAs($this->u, ['central-ia-runner']);

        $ids = $this->postJson(route('central-ia.runner.batida'), ['maquina' => 'mac', 'agentes' => [], 'projetos' => []])
            ->assertOk()->json('encerradas');

        $this->assertEqualsCanonicalizing([$concluida, $arquivada], $ids);
    }

    public function test_o_instalador_leva_o_modulo_de_limpeza(): void
    {
        $this->get(route('central-ia.runner.arquivo', 'limpeza.mjs'))->assertOk()->assertSee('export function limparWorktrees', false);
    }

    public function test_limpeza_esta_agendada_uma_vez_por_dia(): void
    {
        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'central-ia:limpar'));
        $this->assertNotNull($evento);
        $this->assertSame('30 3 * * *', $evento->expression);
    }
}
