<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\Notificacao;
use App\Models\Perfil;
use App\Models\SuporteAtendimento;
use App\Models\SuporteConversa;
use App\Models\SuporteRoteiro;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** O conhecimento do Pedro, a prioridade do roteiro e o nº do Gestor obrigatório (docs/specs/suporte-pedro-whatsapp/conhecimento.md). */
class PedroConhecimentoTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;
    private User $rossini;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00', 'America/Sao_Paulo'));
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 'segredo', 'verify_token' => 'v', 'graph_version' => 'v20.0']]);
        config(['services.suporte_pedro.conta' => 'felipe@teste.local', 'services.suporte_pedro.responsaveis' => ['rossini@teste.local'],
            'services.suporte_pedro.editores' => ['felipe@teste.local'], 'services.suporte_pedro.gestor_gravar' => false]);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.out.'.uniqid('', true)]]])]);
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['email' => 'felipe@teste.local']);
        $this->rossini = User::factory()->create(['name' => 'Rossini', 'email' => 'rossini@teste.local']);
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        $this->retrato('aberto');
    }

    private function retrato(string $etapaDo900): void
    {
        $this->runner();
        $this->postJson(route('suporte.runner.gestor'), ['maquina' => 'wsl', 'lido_em' => now()->toIso8601String(),
            'clientes' => [
                ['id' => 13, 'nome' => 'CONDOMINIO CASTANHEIRAS', 'fantasia' => 'CONDOMINIO CASTANHEIRAS', 'razao' => 'CONDOMINIO RESIDENCIAL PARQUE DAS CASTANHEIRAS', 'cidade' => 'VILA VELHA',
                    'empresa_id' => 3, 'empresa' => 'INVEST', 'telefones' => ['(27) 3389-4253'], 'portal' => ['sindico@castanheiras.com.br']],
                ['id' => 20, 'nome' => 'CONDOMINIO SOLAR', 'fantasia' => '', 'razao' => 'CONDOMINIO SOLAR DOS IPES', 'cidade' => 'VILA VELHA', 'empresa_id' => 3, 'empresa' => 'INVEST', 'telefones' => []],
            ],
            'atendimentos' => [
                ['id' => 1, 'numero' => '900', 'cliente_id' => 13, 'descricao' => 'Portão G1 parado', 'status' => $etapaDo900, 'empresa_id' => 3, 'assunto_id' => 3, 'criado_em' => '2026-10-20 10:05:00'],
                ['id' => 2, 'numero' => '901', 'cliente_id' => 20, 'descricao' => 'Câmera', 'status' => 'aberto', 'empresa_id' => 3, 'assunto_id' => 27, 'criado_em' => '2026-10-20 10:06:00'],
            ],
            'assuntos' => [['id' => 3, 'nome' => 'PORTÃO / ACESSO', 'tipo' => 'SERVICO', 'categoria' => 'CONTOLE DE ACESSO', 'empresa_id' => 3]],
        ])->assertOk();
    }

    private function runner(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
    }

    private function chega(string $texto, string $id, string $de): void
    {
        $valor = ['metadata' => ['phone_number_id' => '111222'], 'contacts' => [['wa_id' => $de, 'profile' => ['name' => 'Joaquim']]],
            'messages' => [['from' => $de, 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $texto]]]];
        $corpo = json_encode(['entry' => [['changes' => [['field' => 'messages', 'value' => $valor]]]]]);
        $this->call('POST', route('suporte.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $corpo, 'segredo')], $corpo)->assertOk();
    }

    private function prompt(): string
    {
        $this->runner();

        return (string) $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->json('prompt');
    }

    private function responde(array $bloco): void
    {
        $this->runner();
        $job = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->json();
        $this->postJson(route('suporte.runner.resposta', $job['id']), ['maquina' => 'wsl', 'texto' => "<<<PEDRO\n".json_encode($bloco, JSON_UNESCAPED_UNICODE)."\nPEDRO>>>"])->assertOk();
    }

    private function portaoParado(): Tarefa
    {
        SuporteConversa::create(['telefone' => '5527988887777', 'gestor_cliente_id' => 13, 'ultima_entrada_em' => now()]);
        $this->chega('o portão G1 parou', 'wamid.1', '5527988887777');
        $this->responde(['resposta' => 'Portão parado é prioridade: até 3 horas.', 'acao' => 'abrir_atendimento', 'resumo' => 'Portão G1 parado', 'destino' => 'gestor',
            'assunto_id' => 3, 'roteiro' => SuporteRoteiro::where('titulo', 'Portão parado')->value('id')]);

        return Tarefa::sole();
    }

    public function test_o_prompt_leva_empresa_roteiros_e_o_login_so_para_o_telefone_do_cadastro(): void
    {
        $this->actingAs($this->felipe)->post(route('suporte.pedro.empresa'), ['apresentacao' => 'Somos o Grupo Soluções.', 'site' => 'https://exemplo.com.br',
            'folders' => "Segurança — https://exemplo.com.br/seg.pdf\n", 'cobranca_minutos' => 30])->assertRedirect();

        // Do telefone cadastrado do Castanheiras: o e-mail do portal vai.
        SuporteConversa::create(['telefone' => '552733894253', 'gestor_cliente_id' => 13, 'ultima_entrada_em' => now()]);
        $this->chega('preciso do boleto', 'wamid.1', '552733894253');
        $prompt = $this->prompt();
        $this->assertStringContainsString("## A EMPRESA\nSomos o Grupo Soluções.\nSite: https://exemplo.com.br\nFolders", $prompt);
        $this->assertStringContainsString('Portão parado', $prompt);
        $this->assertStringContainsString('prioridade crítica, prazo de até 3 h para atender', $prompt);
        $this->assertStringContainsString('Login do portal (o número que escreve é do cadastro): sindico@castanheiras.com.br', $prompt);

        // Quem só disse o nome não recebe o e-mail.
        SuporteConversa::create(['telefone' => '5527911112222', 'gestor_cliente_id' => 13, 'ultima_entrada_em' => now()]);
        $this->chega('preciso do boleto do castanheiras', 'wamid.2', '5527911112222');
        $prompt = $this->prompt();
        $this->assertStringContainsString('Login do portal: não informar', $prompt);
        $this->assertStringNotContainsString('sindico@castanheiras.com.br', $prompt);
    }

    public function test_portao_parado_nasce_critico_com_prazo_e_exige_o_numero_do_gestor(): void
    {
        $t = $this->portaoParado();
        $this->assertSame('critica', $t->prioridade);
        $a = SuporteAtendimento::sole();
        $this->assertSame(['critica', 'gestor', 13], [$a->prioridade, $a->destino, $a->gestor_cliente_id]);
        $this->assertTrue($a->prazo_em->eq(now()->addHours(3)));

        $c = SuporteConversa::sole();
        $this->actingAs($this->rossini)->post(route('suporte.assumir', $c->id));
        $this->actingAs($this->rossini)->post(route('suporte.devolver', $c->id))->assertSessionHasErrors('gestor_numero');

        $t->forceFill(['status' => 'em_desenvolvimento', 'responsavel_id' => $this->rossini->id])->save();
        try {
            app(FluxoTarefaService::class)->mover($t->fresh(), 'concluida');
            $this->fail('concluiu sem o nº do Gestor');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('informe o número', $e->getMessage());
        }

        // Número de outro cliente no Gestor é recusado; o certo é aceito.
        $this->actingAs($this->rossini)->post(route('suporte.gestor-numero', $c->id), ['gestor_numero' => '901'])->assertSessionHasErrors('gestor_numero');
        $this->actingAs($this->rossini)->post(route('suporte.gestor-numero', $c->id), ['gestor_numero' => '900'])->assertSessionHasNoErrors();
        $this->assertSame(['900', $this->rossini->id], [$a->fresh()->gestor_numero, $a->fresh()->gestor_registrado_por]);
        $this->actingAs($this->rossini)->get(route('suporte.conversas', $c->id))->assertOk()->assertSee('Gestor nº 900')->assertSee('Crítica');
        app(FluxoTarefaService::class)->mover($t->fresh(), 'concluida');
        $this->assertSame('concluida', $t->fresh()->status);
    }

    public function test_cobra_o_critico_no_intervalo_e_para_quando_o_gestor_conclui(): void
    {
        $t = $this->portaoParado();
        $cobrancas = fn () => Notificacao::where('destinatario_id', $this->rossini->id)->where('titulo', 'like', 'Cobrança T-%')->count();

        Carbon::setTestNow(now()->addMinutes(30));
        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->assertSame(0, $cobrancas(), 'antes do intervalo, nada');

        Carbon::setTestNow(now()->addMinutes(31));
        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->assertSame(1, $cobrancas());
        $n = Notificacao::where('titulo', 'like', 'Cobrança T-%')->latest('id')->first();
        $this->assertStringContainsString("Cobrança T-{$t->id}: Joaquim — CRÍTICO (Portão parado) · faltam 1 h 59 min", $n->titulo);
        $this->assertSame('critico', $n->nivel);

        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->assertSame(1, $cobrancas(), 'uma por intervalo');

        // Prazo estourado aparece na cobrança.
        $c = SuporteConversa::sole();
        $this->actingAs($this->rossini)->post(route('suporte.gestor-numero', $c->id), ['gestor_numero' => '900']);
        Carbon::setTestNow(now()->addHours(2)->addMinutes(30));
        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->assertStringContainsString('PRAZO ESTOURADO há 31 min · Gestor nº 900: aberto', Notificacao::where('titulo', 'like', 'Cobrança T-%')->latest('id')->value('titulo'));

        // O Gestor concluiu: a cobrança para, mesmo com a tarefa ainda aberta no Matriz.
        $this->retrato('concluido');
        Carbon::setTestNow(now()->addHours(2));
        $antes = $cobrancas();
        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->assertSame($antes, $cobrancas());

        // Fora do horário ninguém é cobrado.
        $this->retrato('aberto');
        Carbon::setTestNow(Carbon::parse('2026-10-20 22:00', 'America/Sao_Paulo'));
        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->assertSame($antes, $cobrancas());
    }

    public function test_so_o_editor_altera_o_conhecimento(): void
    {
        $this->actingAs($this->rossini)->get(route('suporte.pedro'))->assertOk()->assertSee('Portão parado')->assertSee('só o Felipe altera');
        $this->actingAs($this->rossini)->post(route('suporte.pedro.roteiro.novo'), ['titulo' => 'X', 'quando' => 'y'])->assertForbidden();
        $this->actingAs($this->rossini)->post(route('suporte.pedro.empresa'), ['cobranca_minutos' => 60])->assertForbidden();

        $this->actingAs($this->felipe)->post(route('suporte.pedro.roteiro.novo'), ['titulo' => 'Câmera sem imagem', 'quando' => 'Câmera ou DVR sem imagem',
            'prioridade' => 'alta', 'prazo_horas' => 24, 'abre_atendimento' => '1', 'ativo' => '1'])->assertRedirect();
        $r = SuporteRoteiro::where('titulo', 'Câmera sem imagem')->sole();
        $this->assertSame(['alta', 24, true, $this->felipe->id], [$r->prioridade, $r->prazo_horas, $r->abre_atendimento, $r->atualizado_por]);

        $boleto = SuporteRoteiro::where('titulo', 'Boleto / segunda via')->sole();
        $this->actingAs($this->felipe)->post(route('suporte.pedro.roteiro', $boleto->id), ['titulo' => $boleto->titulo, 'quando' => $boleto->quando])->assertRedirect();
        $this->assertFalse($boleto->fresh()->ativo, 'desmarcado, desliga');
        $this->actingAs($this->felipe)->post(route('suporte.pedro.roteiro.apagar', $r->id))->assertRedirect();
        $this->assertNull($r->fresh());

        $victor = User::factory()->semPerfil()->create();
        $victor->perfis()->attach(Perfil::where('slug', 'vixcard')->value('id'));
        $this->actingAs($victor)->get(route('suporte.pedro'))->assertForbidden();
    }
}
