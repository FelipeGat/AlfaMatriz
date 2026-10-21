<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\Documento;
use App\Models\DocumentoPasta;
use App\Models\Perfil;
use App\Models\SuporteConversa;
use App\Models\SuporteMensagem;
use App\Models\User;
use App\Services\Suporte\Conhecimento;
use App\Services\Suporte\Contexto;
use App\Services\Suporte\ContextoDoSuporte;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A base "Contexto do Suporte" que o Pedro consulta (T-476; docs/specs/suporte-pedro-whatsapp/contexto-do-suporte.md). */
class PedroContextoDoSuporteTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;

    private User $rossini;

    private DocumentoPasta $pasta;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00', 'America/Sao_Paulo'));
        Storage::fake(Documento::DISCO);
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 'segredo', 'verify_token' => 'v', 'graph_version' => 'v20.0']]);
        config(['services.suporte_pedro.conta' => 'felipe@teste.local', 'services.suporte_pedro.responsaveis' => ['rossini@teste.local'],
            'services.suporte_pedro.editores' => ['felipe@teste.local'], 'services.suporte_pedro.gestor_gravar' => false]);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.out.'.uniqid('', true)]]])]);
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['name' => 'Felipe', 'email' => 'felipe@teste.local']);
        $this->rossini = User::factory()->create(['name' => 'Rossini', 'email' => 'rossini@teste.local']);
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        $this->retrato();
        $this->pasta = DocumentoPasta::create(['user_id' => $this->felipe->id, 'nome' => 'Contexto do Suporte', 'maquina' => 'HOME', 'lido_em' => now()]);
        $this->semear();
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

    private function nota(string $caminho, string $texto, ?DocumentoPasta $pasta = null): Documento
    {
        $pasta ??= $this->pasta;
        $sha = hash('sha256', $texto);
        $arquivo = "documentos/{$pasta->id}/".substr($sha, 0, 2)."/{$sha}.md";
        Storage::disk(Documento::DISCO)->put($arquivo, $texto);

        return Documento::create(['pasta_id' => $pasta->id, 'caminho' => $caminho, 'caminho_hash' => sha1($caminho),
            'subpasta' => str_contains($caminho, '/') ? substr($caminho, 0, strrpos($caminho, '/')) : '', 'nome' => basename($caminho),
            'extensao' => 'md', 'mime' => 'text/markdown', 'tamanho' => strlen($texto), 'sha256' => $sha, 'arquivo' => $arquivo, 'modificado_em' => now()]);
    }

    private function semear(): void
    {
        $this->nota('contexto do suporte.md', "---\ntitulo: Mapa\ntipo: mapa\n---\n# Contexto do Suporte\nMAPA-DA-BASE");
        $this->nota('03 - Clientes/Castanheiras · gestor-13/contexto.md', "---\ntitulo: Castanheiras\ntipo: cliente\nvalidacao: validado\n---\n# Castanheiras\n"
            ."CONTEXTO-DO-CASTANHEIRAS: portão da garagem deslizante.\nVer [[Portão não fecha]].");
        $this->nota('03 - Clientes/Castanheiras · gestor-13/atendimentos/AT-20261020-7 Portão não fecha.md', "---\ntitulo: Portão não fecha\ntipo: atendimento\n"
            ."situacao: aguardando equipe\n---\n# AT-20261020-7\n## Relato do cliente\nATENDIMENTO-ABERTO-DO-CASTANHEIRAS");
        $this->nota('03 - Clientes/Castanheiras · gestor-13/atendimentos/AT-20260901-3 Câmera sem imagem.md', "---\ntitulo: Câmera sem imagem\ntipo: atendimento\n"
            ."situacao: resolvido\n---\n# AT-20260901-3 Câmera\n## Relato do cliente\nRELATO-ANTIGO-QUE-NAO-VAI\n## Solução e resultado\nSOLUCAO-DA-CAMERA confirmada pela síndica.\n## Ligações\n-");
        $this->nota('03 - Clientes/Solar · gestor-20/contexto.md', "---\ntipo: cliente\n---\nCONTEXTO-DO-SOLAR-NUNCA-NO-CASTANHEIRAS");
        $this->nota('02 - Procedimentos/Portão/Portão não fecha.md', "---\ntitulo: Portão não fecha\ntipo: procedimento\nvalidacao: validado\nvalidado_por: Felipe\n"
            ."quando_usar: portão que não fecha ou volta\n---\nPROCEDIMENTO-PORTAO");
        $this->nota('02 - Procedimentos/Impressora/Impressora não imprime.md', "---\ntitulo: Impressora não imprime\ntipo: procedimento\nvalidacao: validado\n"
            ."validado_por: Fulano de Tal\nquando_usar: impressora que não imprime ou falha\n---\nPROCEDIMENTO-IMPRESSORA");
        $this->nota('_modelos/modelo de cliente.md', 'MODELO-QUE-NAO-VAI');
    }

    private function ligarBase(): void
    {
        app(Conhecimento::class)->gravarAjuste(ContextoDoSuporte::AJUSTE, (string) $this->pasta->id);
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

    public function test_sem_base_escolhida_o_pedido_sai_como_antes(): void
    {
        SuporteConversa::create(['telefone' => '552733894253', 'gestor_cliente_id' => 13, 'ultima_entrada_em' => now()]);
        $this->chega('o portão não fecha', 'wamid.1', '552733894253');
        $prompt = $this->prompt();

        $this->assertStringNotContainsString('CONTEXTO DO SUPORTE', $prompt);
        $this->assertStringNotContainsString('"fontes"', $prompt);
        $this->assertStringNotContainsString('MAPA-DA-BASE', $prompt);
    }

    public function test_leva_o_mapa_e_so_a_pasta_do_cliente_confirmado(): void
    {
        $this->ligarBase();
        SuporteConversa::create(['telefone' => '552733894253', 'gestor_cliente_id' => 13, 'ultima_entrada_em' => now()]);
        $this->chega('o portão voltou a dar problema', 'wamid.1', '552733894253');
        $prompt = $this->prompt();

        $this->assertStringContainsString(Contexto::REGRAS_DA_BASE, $prompt);
        $this->assertStringContainsString('"fontes": [', $prompt);
        $this->assertStringContainsString('MAPA-DA-BASE', $prompt);
        $this->assertStringContainsString('[arquivo: 03 - Clientes/Castanheiras · gestor-13/contexto.md]', $prompt);
        $this->assertStringContainsString('CONTEXTO-DO-CASTANHEIRAS', $prompt);
        $this->assertStringContainsString('ATENDIMENTO-ABERTO-DO-CASTANHEIRAS', $prompt);
        $this->assertStringContainsString('situacao: aguardando equipe', $prompt);
        // Do encerrado, só a solução.
        $this->assertStringContainsString('SOLUCAO-DA-CAMERA', $prompt);
        $this->assertStringNotContainsString('RELATO-ANTIGO-QUE-NAO-VAI', $prompt);
        // O procedimento ligado no contexto do cliente entra, validado pelo Felipe.
        $this->assertStringContainsString('PROCEDIMENTO-PORTAO', $prompt);
        $this->assertStringContainsString('(procedimento · VALIDADO por Felipe)', $prompt);
        // Outro cliente e os modelos, nunca.
        $this->assertStringNotContainsString('CONTEXTO-DO-SOLAR', $prompt);
        $this->assertStringNotContainsString('MODELO-QUE-NAO-VAI', $prompt);
    }

    public function test_sem_cliente_confirmado_vai_so_o_geral_que_combina(): void
    {
        $this->ligarBase();
        SuporteConversa::create(['telefone' => '5527911112222', 'ultima_entrada_em' => now()]);
        $this->chega('minha impressora não imprime nada', 'wamid.1', '5527911112222');
        $prompt = $this->prompt();

        $this->assertStringContainsString('Cliente não confirmado: nenhuma pasta de cliente foi incluída.', $prompt);
        $this->assertStringNotContainsString('CONTEXTO-DO-CASTANHEIRAS', $prompt);
        $this->assertStringNotContainsString('CONTEXTO-DO-SOLAR', $prompt);
        // Combina com a conversa, mas quem validou não é editor nem administrador: vai como rascunho.
        $this->assertStringContainsString('PROCEDIMENTO-IMPRESSORA', $prompt);
        $this->assertStringContainsString('Impressora não imprime.md] (procedimento · RASCUNHO', $prompt);
        $this->assertStringNotContainsString('PROCEDIMENTO-PORTAO', $prompt);
    }

    public function test_administrador_tambem_valida(): void
    {
        $admin = User::factory()->create(['name' => 'Fulano de Tal', 'email' => 'fulano@teste.local']);
        $admin->perfis()->syncWithoutDetaching([Perfil::where('slug', 'admin')->value('id')]);
        $this->ligarBase();
        SuporteConversa::create(['telefone' => '5527911112222', 'ultima_entrada_em' => now()]);
        $this->chega('minha impressora não imprime nada', 'wamid.1', '5527911112222');

        $this->assertStringContainsString('Impressora não imprime.md] (procedimento · VALIDADO por Fulano de Tal)', $this->prompt());

        // Conta que existe, mas sem administrador e fora dos editores: não valida.
        $admin->perfis()->detach();
        $this->assertStringContainsString('Impressora não imprime.md] (procedimento · RASCUNHO',
            (string) app(ContextoDoSuporte::class)->paraOPrompt(SuporteConversa::sole(), 'minha impressora não imprime nada'));
    }

    public function test_nota_grande_e_cortada_e_o_recorte_respeita_o_teto(): void
    {
        $this->ligarBase();
        $this->nota('02 - Procedimentos/Portão/Portão parado.md', "---\ntitulo: Portão parado\nquando_usar: portão parado travado\n---\n".str_repeat('passo do portão ', 2000));
        SuporteConversa::create(['telefone' => '552733894253', 'gestor_cliente_id' => 13, 'ultima_entrada_em' => now()]);
        $this->chega('o portão travou parado', 'wamid.1', '552733894253');
        $prompt = $this->prompt();

        $this->assertStringContainsString('…(nota cortada)', $prompt);
        $secao = Str::between($prompt, '## CONTEXTO DO SUPORTE', '## MENSAGENS');
        $this->assertLessThan(ContextoDoSuporte::TETO + 500, mb_strlen($secao));
    }

    public function test_a_resposta_guarda_so_as_fontes_que_existem_e_a_conversa_mostra(): void
    {
        $this->ligarBase();
        $c = SuporteConversa::create(['telefone' => '552733894253', 'gestor_cliente_id' => 13, 'ultima_entrada_em' => now()]);
        $this->chega('o portão não fecha', 'wamid.1', '552733894253');
        $this->runner();
        $job = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->json();
        $bloco = ['resposta' => 'Vamos conferir o sensor.', 'acao' => 'responder', 'resumo' => 'portão',
            'fontes' => ['02 - Procedimentos/Portão/Portão não fecha.md', '03 - Clientes/Inventado/contexto.md', 42]];
        $this->postJson(route('suporte.runner.resposta', $job['id']), ['maquina' => 'wsl', 'texto' => "<<<PEDRO\n".json_encode($bloco, JSON_UNESCAPED_UNICODE)."\nPEDRO>>>"])->assertOk();

        $m = SuporteMensagem::where('direcao', 'pedro')->sole();
        $this->assertSame(['02 - Procedimentos/Portão/Portão não fecha.md'], $m->fontes);
        $this->actingAs($this->rossini)->get(route('suporte.conversas', $c->id))->assertOk()
            ->assertSee('Fontes: 02 - Procedimentos/Portão/Portão não fecha.md');
    }

    public function test_so_o_editor_escolhe_a_base_e_so_entre_as_pastas_que_ve(): void
    {
        $alheia = DocumentoPasta::create(['user_id' => $this->rossini->id, 'nome' => 'Pasta do Rossini']);

        $this->actingAs($this->rossini)->post(route('suporte.pedro.base'), ['base_pasta' => $this->pasta->id])->assertForbidden();
        $this->actingAs($this->felipe)->post(route('suporte.pedro.base'), ['base_pasta' => $alheia->id])->assertSessionHasErrors('base_pasta');
        $this->assertSame('', app(Conhecimento::class)->ajuste(ContextoDoSuporte::AJUSTE));

        $this->actingAs($this->felipe)->get(route('suporte.pedro'))->assertOk()->assertSee('Contexto do Suporte')->assertDontSee('Pasta do Rossini');
        $this->actingAs($this->felipe)->post(route('suporte.pedro.base'), ['base_pasta' => $this->pasta->id])->assertSessionHasNoErrors();
        $this->assertSame((string) $this->pasta->id, app(Conhecimento::class)->ajuste(ContextoDoSuporte::AJUSTE));
        $this->actingAs($this->felipe)->get(route('suporte.pedro'))->assertSee('Ligada:')->assertSee('2 clientes');

        $this->actingAs($this->felipe)->post(route('suporte.pedro.base'), ['base_pasta' => ''])->assertSessionHasNoErrors();
        $this->assertSame('', app(Conhecimento::class)->ajuste(ContextoDoSuporte::AJUSTE));
    }

    public function test_le_o_cabecalho_e_os_links_das_notas(): void
    {
        $n = ContextoDoSuporte::nota("---\ntitulo: \"Portão: garagem\"\ntipo: atendimento # comentário\nfontes:\n  - \"Conversa 1532\"\n  - Visita\ncodigos: [gestor:13, \"matriz:5\"]\n---\n# Corpo");
        $this->assertSame('Portão: garagem', $n['meta']['titulo']);
        $this->assertSame('atendimento', $n['meta']['tipo']);
        $this->assertSame(['Conversa 1532', 'Visita'], $n['meta']['fontes']);
        $this->assertSame(['gestor:13', 'matriz:5'], $n['meta']['codigos']);
        $this->assertSame('# Corpo', $n['corpo']);
        $this->assertSame([], ContextoDoSuporte::nota("sem cabeçalho\n---\n")['meta']);

        $this->assertSame(['Portão não fecha', 'contexto', 'Sensor'], ContextoDoSuporte::links('ver [[Portão não fecha]], [[X · gestor-1/contexto|X]] e [[Sensor#Chuva]]'));
    }
}
