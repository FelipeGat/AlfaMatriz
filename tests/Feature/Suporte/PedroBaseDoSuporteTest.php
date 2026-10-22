<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\Perfil;
use App\Models\SuporteConversa;
use App\Models\SuporteMensagem;
use App\Models\SuporteNota;
use App\Models\SuportePedido;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\Suporte\BaseDoSuporte;
use App\Services\Suporte\Contexto;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A base do suporte, no Matriz e alimentada pelos atendimentos (T-476; docs/specs/suporte-pedro-whatsapp/base-do-suporte.md). */
class PedroBaseDoSuporteTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;

    private User $rossini;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00', 'America/Sao_Paulo'));
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 'segredo', 'verify_token' => 'v', 'graph_version' => 'v20.0']]);
        config(['services.suporte_pedro.conta' => 'felipe@teste.local', 'services.suporte_pedro.responsaveis' => ['rossini@teste.local'],
            'services.suporte_pedro.editores' => ['felipe@teste.local'], 'services.suporte_pedro.gestor_gravar' => false]);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.out.'.uniqid('', true)]]])]);
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['name' => 'Felipe', 'email' => 'felipe@teste.local']);
        $this->rossini = User::factory()->create(['name' => 'Rossini', 'email' => 'rossini@teste.local']);
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        $this->retrato();
    }

    private function retrato(): void
    {
        $this->runner();
        $this->postJson(route('suporte.runner.gestor'), ['maquina' => 'wsl', 'lido_em' => now()->toIso8601String(),
            'clientes' => [
                ['id' => 13, 'nome' => 'CONDOMINIO CASTANHEIRAS', 'fantasia' => 'CONDOMINIO CASTANHEIRAS', 'razao' => 'CONDOMINIO RESIDENCIAL PARQUE DAS CASTANHEIRAS', 'cidade' => 'VILA VELHA',
                    'empresa_id' => 3, 'empresa' => 'INVEST', 'telefones' => ['(27) 3389-4253']],
                ['id' => 20, 'nome' => 'CONDOMINIO SOLAR', 'fantasia' => '', 'razao' => 'CONDOMINIO SOLAR DOS IPES', 'cidade' => 'VILA VELHA', 'empresa_id' => 3, 'empresa' => 'INVEST', 'telefones' => []],
            ],
            'atendimentos' => [], 'assuntos' => [['id' => 3, 'nome' => 'PORTÃO / ACESSO', 'tipo' => 'SERVICO', 'categoria' => 'CONTOLE DE ACESSO', 'empresa_id' => 3]],
        ])->assertOk();
    }

    private function runner(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
    }

    /** Alguém do suporte que não é editor do Pedro nem administrador. */
    private function atendente(string $nome): User
    {
        $perfil = Perfil::firstOrCreate(['slug' => 'atendimento-teste'], ['nome' => 'Atendimento (teste)']);
        DB::table('perfil_permissao')->updateOrInsert(['perfil_id' => $perfil->id, 'permissao_id' => DB::table('permissoes')->where('recurso', 'suporte')->value('id')],
            ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => false, 'excluir' => false]);
        $u = User::factory()->semPerfil()->create(['name' => $nome, 'email' => Str::slug($nome).'@teste.local']);
        $u->perfis()->attach($perfil->id);

        return $u;
    }

    private function nota(array $dados): SuporteNota
    {
        return SuporteNota::create($dados + ['estado' => 'ativa']);
    }

    private function semear(): void
    {
        $this->nota(['tipo' => 'empresa', 'titulo' => 'Quem somos', 'corpo' => 'EMPRESA-INTERNA', 'validacao' => 'validado', 'validado_por' => $this->felipe->id]);
        $this->nota(['tipo' => 'cliente', 'titulo' => 'Castanheiras', 'gestor_cliente_id' => 13, 'corpo' => 'CONTEXTO-DO-CASTANHEIRAS: portão deslizante.']);
        $this->nota(['tipo' => 'atendimento', 'titulo' => 'Portão não fecha', 'gestor_cliente_id' => 13, 'situacao' => 'em_curso', 'codigo' => 'AT-20261020-7',
            'relato' => 'ATENDIMENTO-ABERTO-DO-CASTANHEIRAS']);
        $this->nota(['tipo' => 'atendimento', 'titulo' => 'Câmera sem imagem', 'gestor_cliente_id' => 13, 'situacao' => 'resolvido', 'codigo' => 'AT-20260901-3',
            'relato' => 'RELATO-ANTIGO-QUE-NAO-VAI', 'solucao' => 'SOLUCAO-DA-CAMERA confirmada pela síndica.']);
        $this->nota(['tipo' => 'cliente', 'titulo' => 'Solar', 'gestor_cliente_id' => 20, 'corpo' => 'CONTEXTO-DO-SOLAR-NUNCA-NO-CASTANHEIRAS']);
        $this->nota(['tipo' => 'atendimento', 'titulo' => 'Proposta que ainda não vale', 'gestor_cliente_id' => 13, 'situacao' => 'em_curso', 'estado' => 'proposta', 'relato' => 'PROPOSTA-NAO-CONFIRMADA']);
        $this->nota(['tipo' => 'procedimento', 'titulo' => 'Portão não fecha', 'quando_usar' => 'portão que não fecha ou volta', 'corpo' => 'PROCEDIMENTO-PORTAO',
            'validacao' => 'validado', 'validado_por' => $this->felipe->id]);
        $this->nota(['tipo' => 'procedimento', 'titulo' => 'Impressora não imprime', 'quando_usar' => 'impressora que não imprime ou falha', 'corpo' => 'PROCEDIMENTO-IMPRESSORA']);
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

    /** O Pedro pega o próximo pedido e responde com o bloco dado. */
    private function responde(array $bloco): void
    {
        $this->runner();
        $job = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->json();
        $this->postJson(route('suporte.runner.resposta', $job['id']), ['maquina' => 'wsl',
            'texto' => "<<<PEDRO\n".json_encode($bloco + ['resumo' => 'portão'], JSON_UNESCAPED_UNICODE)."\nPEDRO>>>"])->assertOk();
    }

    public function test_base_vazia_o_pedido_sai_sem_base_mas_pede_o_registro(): void
    {
        SuporteConversa::create(['telefone' => '552733894253', 'gestor_cliente_id' => 13, 'solicitante_nome' => 'Maria', 'ultima_entrada_em' => now()]);
        $this->chega('o portão não fecha', 'wamid.1', '552733894253');
        $prompt = $this->prompt();

        $this->assertStringNotContainsString('BASE DO SUPORTE', $prompt);
        $this->assertStringNotContainsString('"fontes"', $prompt);
        $this->assertStringContainsString(Contexto::REGRAS_DO_REGISTRO, $prompt);
        $this->assertStringContainsString('"registro": {', $prompt);
    }

    public function test_leva_a_empresa_e_so_o_cliente_confirmado(): void
    {
        $this->semear();
        SuporteConversa::create(['telefone' => '552733894253', 'gestor_cliente_id' => 13, 'solicitante_nome' => 'Maria', 'ultima_entrada_em' => now()]);
        $this->chega('o portão voltou a dar problema, não fecha', 'wamid.1', '552733894253');
        $prompt = $this->prompt();

        $this->assertStringContainsString(Contexto::REGRAS_DA_BASE, $prompt);
        $this->assertStringContainsString('"fontes": [', $prompt);
        $this->assertStringContainsString('EMPRESA-INTERNA', $prompt);
        $this->assertStringContainsString('CONTEXTO-DO-CASTANHEIRAS', $prompt);
        $this->assertStringContainsString('ATENDIMENTO-ABERTO-DO-CASTANHEIRAS', $prompt);
        $this->assertStringContainsString('AT-20261020-7 — Portão não fecha (Atendimento · Em curso', $prompt);
        // Do encerrado, só a solução.
        $this->assertStringContainsString('SOLUCAO-DA-CAMERA', $prompt);
        $this->assertStringNotContainsString('RELATO-ANTIGO-QUE-NAO-VAI', $prompt);
        // O procedimento que combina com a conversa, validado pelo Felipe.
        $this->assertStringContainsString('PROCEDIMENTO-PORTAO', $prompt);
        $this->assertStringContainsString('Portão não fecha (Procedimento · VALIDADO por Felipe)', $prompt);
        // Outro cliente e o que ainda é proposta, nunca.
        $this->assertStringNotContainsString('CONTEXTO-DO-SOLAR', $prompt);
        $this->assertStringNotContainsString('PROPOSTA-NAO-CONFIRMADA', $prompt);
        $this->assertStringNotContainsString('PROCEDIMENTO-IMPRESSORA', $prompt);
    }

    /**
     * Antes da T-478, quem não tinha cliente confirmado recebia só as notas gerais. Agora,
     * sem identificação, o Pedro só pede a identificação: nenhuma nota vai, nem "fontes".
     */
    public function test_sem_identificacao_o_pedido_sai_sem_a_base(): void
    {
        $this->semear();
        SuporteConversa::create(['telefone' => '5527911112222', 'ultima_entrada_em' => now()]);
        $this->chega('minha impressora não imprime nada', 'wamid.1', '5527911112222');
        $prompt = $this->prompt();

        $this->assertStringContainsString('IDENTIFICAÇÃO PRIMEIRO', $prompt);
        $this->assertStringNotContainsString('BASE DO SUPORTE', $prompt);
        $this->assertStringNotContainsString('"fontes"', $prompt);
        $this->assertStringNotContainsString('PROCEDIMENTO-IMPRESSORA', $prompt);
        $this->assertStringNotContainsString('CONTEXTO-DO-CASTANHEIRAS', $prompt);
    }

    public function test_nota_grande_e_cortada_e_o_recorte_respeita_o_teto(): void
    {
        $this->semear();
        $this->nota(['tipo' => 'procedimento', 'titulo' => 'Portão parado', 'quando_usar' => 'portão parado travado', 'corpo' => str_repeat('passo do portão ', 2000)]);
        $this->nota(['tipo' => 'cliente', 'titulo' => 'Castanheiras (Matriz)', 'cliente_id' => null, 'gestor_cliente_id' => 13, 'corpo' => str_repeat('memória longa ', 2000)]);
        SuporteConversa::create(['telefone' => '552733894253', 'gestor_cliente_id' => 13, 'solicitante_nome' => 'Maria', 'ultima_entrada_em' => now()]);
        $this->chega('o portão travou parado', 'wamid.1', '552733894253');
        $prompt = $this->prompt();

        $this->assertStringContainsString('…(nota cortada)', $prompt);
        $this->assertLessThan(BaseDoSuporte::TETO + 500, mb_strlen(Str::between($prompt, '## BASE DO SUPORTE', '## MENSAGENS')));
    }

    public function test_a_resposta_guarda_so_as_notas_que_existem_e_a_conversa_mostra(): void
    {
        $this->semear();
        $proc = SuporteNota::where('corpo', 'PROCEDIMENTO-PORTAO')->sole();
        $proposta = SuporteNota::propostas()->sole();
        $c = SuporteConversa::create(['telefone' => '552733894253', 'gestor_cliente_id' => 13, 'solicitante_nome' => 'Maria', 'ultima_entrada_em' => now()]);
        $this->chega('o portão não fecha', 'wamid.1', '552733894253');
        $this->responde(['resposta' => 'Vamos conferir o sensor.', 'acao' => 'responder', 'fontes' => ["nota {$proc->id}", 999, $proposta->id, 'qualquer']]);

        $this->assertSame([$proc->id], SuporteMensagem::where('direcao', 'pedro')->sole()->fontes);
        $this->actingAs($this->rossini)->get(route('suporte.conversas', $c->id))->assertOk()
            ->assertSee('Fontes:')->assertSee(route('suporte.base.ver', $proc->id));
    }

    public function test_o_pedro_propoe_o_registro_ao_abrir_o_atendimento_e_completa_a_mesma_proposta(): void
    {
        $c = SuporteConversa::create(['telefone' => '552733894253', 'gestor_cliente_id' => 13, 'solicitante_nome' => 'Maria', 'ultima_entrada_em' => now()]);
        $this->chega('o portão não fecha', 'wamid.1', '552733894253');
        $this->responde(['resposta' => 'Vou abrir o atendimento.', 'acao' => 'abrir_atendimento',
            'registro' => ['titulo' => 'Portão da garagem não fecha', 'relato' => 'Cliente diz que o portão volta.', 'verificado' => '', 'solucao' => '', 'situacao' => 'resolvido']]);

        $n = SuporteNota::sole();
        $t = Tarefa::sole();
        $this->assertSame(['atendimento', 'proposta', 'em_curso', "AT-20261021-{$c->id}", $t->id, 13, null],
            [$n->tipo, $n->estado, $n->situacao, $n->codigo, $n->tarefa_id, $n->gestor_cliente_id, $n->autor_id]);

        // A resposta seguinte completa a mesma proposta em vez de abrir outra.
        $this->chega('funcionou depois que o técnico limpou o sensor', 'wamid.2', '552733894253');
        $this->responde(['resposta' => 'Que bom!', 'acao' => 'responder',
            'registro' => ['titulo' => 'Portão da garagem não fecha', 'solucao' => 'Técnico limpou o sensor; cliente confirmou.', 'situacao' => 'resolvido']]);
        $n = SuporteNota::sole();
        $this->assertSame(['resolvido', 'Cliente diz que o portão volta.', 'Técnico limpou o sensor; cliente confirmou.'], [$n->situacao, $n->relato, $n->solucao]);
        $this->actingAs($this->rossini)->get(route('suporte.conversas', $c->id))->assertSee('Conferir e confirmar');
    }

    public function test_quem_atendeu_confirma_e_o_registro_passa_a_valer(): void
    {
        $ana = $this->atendente('Ana Suporte');
        $bia = $this->atendente('Bia Suporte');
        $c = SuporteConversa::create(['telefone' => '552733894253', 'gestor_cliente_id' => 13, 'solicitante_nome' => 'Maria', 'ultima_entrada_em' => now()]);
        $this->chega('o portão não fecha', 'wamid.1', '552733894253');
        $this->actingAs($ana)->post(route('suporte.responder', $c->id), ['texto' => 'Oi, aqui é a Ana.'])->assertSessionHasNoErrors();
        $n = app(BaseDoSuporte::class)->proporRegistro($c->fresh(), ['titulo' => 'Portão', 'relato' => 'volta sozinho']);
        $dados = ['titulo' => 'Portão não fecha', 'situacao' => 'resolvido', 'relato' => 'volta sozinho', 'verificado' => 'Ana viu o sensor sujo', 'solucao' => '', 'gestor_cliente_id' => 13];

        // Só quem atendeu (ou quem valida) confirma; e "resolvido" exige a solução.
        $this->actingAs($bia)->post(route('suporte.base.confirmar', $n->id), $dados + ['solucao' => 'x'])->assertForbidden();
        $this->actingAs($ana)->post(route('suporte.base.confirmar', $n->id), $dados)->assertSessionHasErrors('solucao');
        $this->actingAs($ana)->post(route('suporte.base.confirmar', $n->id), ['solucao' => 'Limpeza do sensor; síndica confirmou.'] + $dados)->assertRedirect(route('suporte.base'));

        $n->refresh();
        $this->assertSame(['ativa', $ana->id, 'resolvido'], [$n->estado, $n->confirmado_por, $n->situacao]);
        $this->chega('e agora a câmera', 'wamid.2', '552733894253');
        $this->actingAs($ana)->post(route('suporte.devolver', $c->id));
        $this->assertStringContainsString('Limpeza do sensor; síndica confirmou.', (string) app(BaseDoSuporte::class)->paraOPrompt($c->fresh(), 'câmera'));
    }

    public function test_sem_ninguem_da_equipe_qualquer_um_do_suporte_confirma_e_pode_descartar(): void
    {
        $bia = $this->atendente('Bia Suporte');
        $c = SuporteConversa::create(['telefone' => '552733894253', 'ultima_entrada_em' => now()]);
        $n = app(BaseDoSuporte::class)->proporRegistro($c, ['titulo' => 'Dúvida de boleto', 'relato' => 'quer a segunda via']);

        $this->assertTrue(app(BaseDoSuporte::class)->podeConfirmar($bia, $n));
        $this->actingAs($bia)->post(route('suporte.base.descartar', $n->id))->assertRedirect(route('suporte.base'));
        $this->assertSame('descartada', $n->fresh()->estado);
        $this->assertNull(app(BaseDoSuporte::class)->paraOPrompt($c, 'boleto segunda via'));
    }

    public function test_cliente_que_sumiu_vira_encerrado_sem_confirmacao_uma_vez_so(): void
    {
        $sumiu = SuporteConversa::create(['telefone' => '5527900000001', 'ultima_entrada_em' => now()->subHours(30), 'gestor_cliente_id' => 13, 'solicitante_nome' => 'Maria']);
        // Quem nunca se identificou não teve atendimento: não vira registro (T-478).
        $anonima = SuporteConversa::create(['telefone' => '5527900000003', 'ultima_entrada_em' => now()->subHours(30)]);
        $e3 = SuporteMensagem::create(['conversa_id' => $anonima->id, 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => 'o portão não abre']);
        SuporteMensagem::create(['conversa_id' => $anonima->id, 'direcao' => 'pedro', 'tipo' => 'text', 'texto' => 'Me diga seu nome e o do condomínio.']);
        SuportePedido::forceCreate(['conversa_id' => $anonima->id, 'ate_mensagem_id' => $e3->id, 'situacao' => 'respondido', 'acao' => 'responder', 'resumo' => 'Portão não abre']);
        $entrada = SuporteMensagem::create(['conversa_id' => $sumiu->id, 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => 'como troco a senha do portal?']);
        SuporteMensagem::create(['conversa_id' => $sumiu->id, 'direcao' => 'pedro', 'tipo' => 'text', 'texto' => 'Use o Esqueci minha senha.']);
        SuportePedido::forceCreate(['conversa_id' => $sumiu->id, 'ate_mensagem_id' => $entrada->id, 'situacao' => 'respondido', 'acao' => 'responder', 'resumo' => 'Troca de senha do portal']);
        // Com atendimento aberto, quem cuida é a equipe: não vira "sem confirmação".
        $comTarefa = SuporteConversa::create(['telefone' => '5527900000002', 'ultima_entrada_em' => now()->subHours(30),
            'tarefa_id' => Tarefa::factory()->create(['status' => 'em_desenvolvimento'])->id]);
        $e2 = SuporteMensagem::create(['conversa_id' => $comTarefa->id, 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => 'portão']);
        SuporteMensagem::create(['conversa_id' => $comTarefa->id, 'direcao' => 'pedro', 'tipo' => 'text', 'texto' => 'Abri o atendimento.']);
        SuportePedido::forceCreate(['conversa_id' => $comTarefa->id, 'ate_mensagem_id' => $e2->id, 'situacao' => 'respondido', 'acao' => 'responder', 'resumo' => 'Portão']);

        $this->artisan('suporte:registros')->assertSuccessful();
        $this->artisan('suporte:registros')->assertSuccessful();

        $n = SuporteNota::sole();
        $this->assertSame([$sumiu->id, 'proposta', 'encerrado_sem_confirmacao', 'Troca de senha do portal', 'como troco a senha do portal?'],
            [$n->conversa_id, $n->estado, $n->situacao, $n->titulo, $n->relato]);
    }

    public function test_so_editor_ou_administrador_valida_e_mudanca_de_outro_volta_a_rascunho(): void
    {
        $ana = $this->atendente('Ana Suporte');
        $this->actingAs($ana)->post(route('suporte.base.criar'), ['tipo' => 'procedimento', 'titulo' => 'Portão não fecha', 'quando_usar' => 'portão que volta', 'corpo' => 'Limpe o sensor.'])
            ->assertSessionHasNoErrors();
        $n = SuporteNota::sole();
        $this->assertSame(['rascunho', $ana->id], [$n->validacao, $n->autor_id]);

        $this->actingAs($ana)->post(route('suporte.base.validar', $n->id))->assertForbidden();
        $this->actingAs($this->felipe)->post(route('suporte.base.validar', $n->id))->assertSessionHasNoErrors();
        $this->assertTrue($n->fresh()->validada());

        // Quem valida mexeu: continua validada, agora com o nome dele.
        $this->actingAs($this->rossini)->post(route('suporte.base.salvar', $n->id), ['titulo' => 'Portão não fecha', 'quando_usar' => 'portão que volta', 'corpo' => 'Limpe o sensor e teste.']);
        $this->assertSame([true, $this->rossini->id], [$n->fresh()->validada(), $n->fresh()->validado_por]);
        // Outra pessoa mudou o texto: volta a rascunho, e o Pedro só cita como possibilidade.
        $this->actingAs($ana)->post(route('suporte.base.salvar', $n->id), ['titulo' => 'Portão não fecha', 'quando_usar' => 'portão que volta', 'corpo' => 'Desligue o portão.']);
        $this->assertSame(['rascunho', null], [$n->fresh()->validacao, $n->fresh()->validado_por]);
        $this->actingAs($ana)->post(route('suporte.base.apagar', $n->id))->assertForbidden();
    }

    public function test_atendimento_vira_conhecimento_em_rascunho(): void
    {
        $at = $this->nota(['tipo' => 'atendimento', 'titulo' => 'Sensor sujo', 'gestor_cliente_id' => 13, 'situacao' => 'resolvido',
            'relato' => 'portão volta sozinho', 'solucao' => 'limpeza do sensor']);
        $this->actingAs($this->rossini)->post(route('suporte.base.conhecimento', $at->id))->assertRedirect();

        $k = SuporteNota::where('tipo', 'conhecimento')->sole();
        $this->assertSame([$at->id, 'rascunho', null], [$k->origem_id, $k->validacao, $k->gestor_cliente_id]);
        $this->assertStringContainsString('limpeza do sensor', $k->corpo);
    }

    public function test_um_cliente_tem_uma_memoria_so(): void
    {
        $this->nota(['tipo' => 'cliente', 'titulo' => 'Castanheiras', 'gestor_cliente_id' => 13, 'corpo' => 'x']);

        $this->actingAs($this->rossini)->post(route('suporte.base.criar'), ['tipo' => 'cliente', 'titulo' => 'De novo', 'gestor_cliente_id' => 13, 'corpo' => 'y'])
            ->assertSessionHasErrors('cliente_id');
        $this->actingAs($this->rossini)->post(route('suporte.base.criar'), ['tipo' => 'cliente', 'titulo' => 'Sem cliente', 'corpo' => 'y'])
            ->assertSessionHasErrors('cliente_id');
        $this->assertSame(1, SuporteNota::count());
    }

    public function test_a_tela_lista_busca_e_mostra_os_registros_a_confirmar(): void
    {
        $this->semear();
        $this->actingAs($this->rossini)->get(route('suporte.base'))->assertOk()
            ->assertSee('Registros a confirmar')->assertSee('Proposta que ainda não vale')->assertSee('Impressora não imprime');
        $this->actingAs($this->rossini)->get(route('suporte.base', ['q' => 'impress']))->assertOk()
            ->assertSee('Impressora não imprime')->assertDontSee('Câmera sem imagem');
        $this->actingAs($this->rossini)->get(route('suporte.base.nova', ['tipo' => 'procedimento']))->assertOk()->assertSee('Passos:');
        $this->actingAs($this->rossini)->get(route('suporte.base.ver', SuporteNota::propostas()->sole()->id))->assertOk()->assertSee('Confirmar registro');

        $sem = User::factory()->semPerfil()->create();
        $this->actingAs($sem)->get(route('suporte.base'))->assertForbidden();
    }
}
