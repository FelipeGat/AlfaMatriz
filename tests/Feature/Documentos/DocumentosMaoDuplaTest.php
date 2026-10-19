<?php

namespace Tests\Feature\Documentos;

use App\Models\CentralIaRunner;
use App\Models\Documento;
use App\Models\DocumentoOperacao;
use App\Models\DocumentoPasta;
use App\Models\Perfil;
use App\Models\User;
use App\Services\Documentos\Espelho;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Documentos em mão dupla com o OneDrive (docs/specs/documentos-mao-dupla). */
class DocumentosMaoDuplaTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;
    private User $victor;
    private User $rossini;
    private DocumentoPasta $pasta;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Documento::DISCO);
        $this->felipe = User::factory()->create(['name' => 'Felipe']);
        (new PerfilPermissaoSeeder)->run();
        $this->rossini = User::factory()->create(['name' => 'Rossini Admin']);
        $this->victor = User::factory()->semPerfil()->create(['name' => 'Victor']);
        $this->victor->perfis()->attach(Perfil::where('slug', 'vixcard')->value('id'));
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);

        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $id = $this->postJson(route('documentos.runner.inventario'), ['maquina' => 'wsl', 'pasta' => 'Orçamentos VixCard',
            'arquivos' => [['caminho' => 'Clientes/Lorenzo/proposta.pdf', 'tamanho' => 7, 'modificado' => '2026-10-18T10:00:00Z']],
            'pastas' => ['Clientes', 'Clientes/Lorenzo', 'Clientes/Vazia', '../fora']])->json('pasta_id');
        $this->post(route('documentos.runner.parte', $id), ['maquina' => 'wsl', 'caminho' => 'Clientes/Lorenzo/proposta.pdf', 'tamanho' => 7,
            'modificado' => '2026-10-18T10:00:00Z', 'sha256' => hash('sha256', '%PDF-1.'), 'parte' => 0, 'partes' => 1,
            'arquivo' => UploadedFile::fake()->createWithContent('parte.bin', '%PDF-1.')], ['Accept' => 'application/json'])->assertOk();
        $this->pasta = DocumentoPasta::findOrFail($id);
        $this->pasta->pessoas()->attach($this->victor->id);
        $this->app['auth']->forgetGuards();
    }

    private function runner(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
    }

    private function concluir(DocumentoOperacao $op, array $dados)
    {
        $this->runner();

        return $this->postJson(route('documentos.runner.operacao-concluir', $op->id), ['maquina' => 'wsl'] + $dados);
    }

    public function test_pasta_vazia_aparece_e_cards_ou_linhas(): void
    {
        $this->assertSame(['Clientes', 'Clientes/Lorenzo', 'Clientes/Vazia'], $this->pasta->fresh()->subpastas, 'caminho perigoso fica de fora');
        $this->actingAs($this->victor)->get(route('documentos.pasta', [$this->pasta, 'caminho' => 'Clientes']))->assertOk()
            ->assertSee('Lorenzo')->assertSee('Vazia')->assertSee('Pasta vazia')->assertSee('Linhas')->assertSee('Nova pasta');
        $this->actingAs($this->victor)->get(route('documentos.pasta', [$this->pasta, 'caminho' => 'Clientes/Vazia']))->assertOk()
            ->assertSee('Arraste arquivos para cá');
    }

    public function test_victor_cria_pasta_e_a_maquina_aplica(): void
    {
        $this->actingAs($this->victor)->post(route('documentos.criar-pasta', $this->pasta), ['nivel' => 'Clientes', 'nome' => 'Padaria Monte Líbano'])
            ->assertRedirect()->assertSessionHas('status');
        $this->actingAs($this->victor)->post(route('documentos.criar-pasta', $this->pasta), ['nivel' => 'Clientes', 'nome' => 'Lorenzo'])->assertSessionHasErrors('nome');
        $this->actingAs($this->victor)->post(route('documentos.criar-pasta', $this->pasta), ['nivel' => '', 'nome' => '../fora'])->assertSessionHasErrors('nome');
        $op = DocumentoOperacao::sole();
        $this->assertSame([$this->victor->id, 'criar_pasta', 'Clientes/Padaria Monte Líbano', 'pendente'], [$op->user_id, $op->tipo, $op->caminho, $op->situacao]);
        $this->actingAs($this->victor)->get(route('documentos.pasta', [$this->pasta, 'caminho' => 'Clientes']))->assertSee('Aguardando a máquina de Felipe')
            ->assertSee('aguardando a máquina criar no OneDrive');

        $this->runner();
        $this->postJson(route('documentos.runner.operacoes'), ['maquina' => 'wsl'])->assertOk()
            ->assertJsonPath('operacoes.0.pasta', 'Orçamentos VixCard')->assertJsonPath('operacoes.0.caminho', 'Clientes/Padaria Monte Líbano');
        $this->concluir($op, ['situacao' => 'feita'])->assertOk();
        $this->assertContains('Clientes/Padaria Monte Líbano', $this->pasta->fresh()->subpastas);
        $this->concluir($op, ['situacao' => 'feita'])->assertNotFound();
    }

    public function test_envio_em_partes_vira_documento_sem_subir_de_novo(): void
    {
        $conteudo = str_repeat('x', Espelho::PARTE_BYTES + 10);
        foreach (str_split($conteudo, Espelho::PARTE_BYTES) as $i => $parte) {
            $this->actingAs($this->felipe)->post(route('documentos.enviar', $this->pasta), ['envio' => 'abc12345-envio', 'nome' => 'orçamento.pdf',
                'nivel' => 'Clientes/Lorenzo', 'tamanho' => strlen($conteudo), 'parte' => $i, 'partes' => 2,
                'arquivo' => UploadedFile::fake()->createWithContent('parte.bin', $parte)], ['Accept' => 'application/json'])
                ->assertOk()->assertJsonPath('completo', $i === 1);
        }
        $op = DocumentoOperacao::sole();
        $this->assertSame(['enviar', 'Clientes/Lorenzo/orçamento.pdf', hash('sha256', $conteudo)], [$op->tipo, $op->caminho, $op->sha256]);

        $this->runner();
        $this->get(route('documentos.runner.operacao-arquivo', ['operacao' => $op->id, 'maquina' => 'wsl']))->assertOk();
        $this->concluir($op, ['situacao' => 'feita', 'caminho_final' => 'Clientes/Lorenzo/orçamento (2).pdf', 'modificado' => '2026-10-19T12:00:00Z'])->assertOk();
        $doc = Documento::where('caminho', 'Clientes/Lorenzo/orçamento (2).pdf')->sole();
        $this->assertSame([strlen($conteudo), hash('sha256', $conteudo)], [$doc->tamanho, $doc->sha256]);
        Storage::disk(Documento::DISCO)->assertExists($doc->arquivo);
        $this->assertNull($op->fresh()->arquivo);

        // O espelho seguinte não pede para subir de novo (FR-005).
        $r = $this->postJson(route('documentos.runner.inventario'), ['maquina' => 'wsl', 'pasta' => 'Orçamentos VixCard', 'arquivos' => [
            ['caminho' => 'Clientes/Lorenzo/proposta.pdf', 'tamanho' => 7, 'modificado' => '2026-10-18T10:00:00Z'],
            ['caminho' => 'Clientes/Lorenzo/orçamento (2).pdf', 'tamanho' => strlen($conteudo), 'modificado' => '2026-10-19T12:00:00Z'],
        ]])->assertOk();
        $this->assertSame([], $r->json('enviar'));
    }

    public function test_executavel_e_grande_demais_recusados_no_envio(): void
    {
        $this->actingAs($this->felipe)->post(route('documentos.enviar', $this->pasta), ['envio' => 'abc12345', 'nome' => 'virus.exe', 'tamanho' => 3,
            'parte' => 0, 'partes' => 1, 'arquivo' => UploadedFile::fake()->createWithContent('parte.bin', 'abc')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->actingAs($this->felipe)->post(route('documentos.enviar', $this->pasta), ['envio' => 'abc12345', 'nome' => 'x.pdf', 'tamanho' => Documento::MAX_BYTES + 1,
            'parte' => 0, 'partes' => 1, 'arquivo' => UploadedFile::fake()->createWithContent('parte.bin', 'abc')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(0, DocumentoOperacao::count());
    }

    public function test_mover_e_apagar_valem_aqui_quando_a_maquina_confirma(): void
    {
        $this->actingAs($this->felipe)->post(route('documentos.mover', $this->pasta), ['caminho' => 'Clientes/Lorenzo', 'destino' => 'Clientes/Vazia'])->assertSessionHasErrors('destino');
        $this->actingAs($this->felipe)->post(route('documentos.mover', $this->pasta), ['caminho' => 'Clientes', 'destino' => 'Clientes/Lorenzo/x'])->assertSessionHasErrors('destino');
        $this->actingAs($this->felipe)->post(route('documentos.mover', $this->pasta), ['caminho' => 'Clientes/Lorenzo', 'destino' => 'Clientes/Lorenzo Marmoraria'])->assertSessionHas('status');
        $mover = DocumentoOperacao::sole();
        $this->assertTrue(Documento::where('caminho', 'Clientes/Lorenzo/proposta.pdf')->exists(), 'só muda quando a máquina confirma');
        $this->concluir($mover, ['situacao' => 'feita'])->assertOk();
        $doc = Documento::sole();
        $this->assertSame(['Clientes/Lorenzo Marmoraria/proposta.pdf', 'Clientes/Lorenzo Marmoraria', sha1('Clientes/Lorenzo Marmoraria/proposta.pdf')], [$doc->caminho, $doc->subpasta, $doc->caminho_hash]);
        $this->assertContains('Clientes/Lorenzo Marmoraria', $this->pasta->fresh()->subpastas);

        $this->actingAs($this->victor)->post(route('documentos.apagar', $this->pasta), ['caminho' => 'Clientes/Lorenzo Marmoraria'])->assertSessionHas('status');
        $apagar = DocumentoOperacao::where('tipo', 'apagar')->sole();
        $arquivo = $doc->arquivo;
        $this->concluir($apagar, ['situacao' => 'feita'])->assertOk();
        $this->assertSame(0, Documento::count());
        Storage::disk(Documento::DISCO)->assertMissing($arquivo);
        $this->assertSame([0, 0], [$this->pasta->fresh()->arquivos, (int) $this->pasta->fresh()->bytes]);
        $this->assertNotContains('Clientes/Lorenzo Marmoraria', $this->pasta->fresh()->subpastas);
        $this->actingAs($this->victor)->get(route('documentos.pasta', $this->pasta))->assertSee('Alterações recentes')->assertSee('Apagar Clientes/Lorenzo Marmoraria');
    }

    public function test_falha_fica_registrada_e_nao_repete(): void
    {
        $this->actingAs($this->felipe)->post(route('documentos.apagar', $this->pasta), ['caminho' => 'Clientes/Lorenzo/proposta.pdf']);
        $op = DocumentoOperacao::sole();
        $this->concluir($op, ['situacao' => 'falhou', 'erro' => 'arquivo aberto no Word'])->assertOk();
        $this->assertSame(['falhou', 'arquivo aberto no Word'], [$op->fresh()->situacao, $op->fresh()->erro]);
        $this->assertSame(1, Documento::count());
        $this->postJson(route('documentos.runner.operacoes'), ['maquina' => 'wsl'])->assertJsonCount(0, 'operacoes');
        $this->actingAs($this->felipe)->get(route('documentos.pasta', $this->pasta))->assertSee('1 com falha')->assertSee('arquivo aberto no Word');
    }

    public function test_quem_nao_ve_a_pasta_nao_altera(): void
    {
        $this->actingAs($this->rossini)->post(route('documentos.criar-pasta', $this->pasta), ['nome' => 'X'])->assertNotFound();
        $this->actingAs($this->rossini)->post(route('documentos.apagar', $this->pasta), ['caminho' => 'Clientes'])->assertNotFound();
        $this->actingAs($this->rossini)->post(route('documentos.mover', $this->pasta), ['caminho' => 'Clientes', 'destino' => 'Y'])->assertNotFound();
        $this->actingAs($this->rossini)->post(route('documentos.enviar', $this->pasta), ['envio' => 'abc12345', 'nome' => 'x.pdf', 'tamanho' => 3, 'parte' => 0, 'partes' => 1,
            'arquivo' => UploadedFile::fake()->createWithContent('parte.bin', 'abc')], ['Accept' => 'application/json'])->assertNotFound();
        $this->assertSame(0, DocumentoOperacao::count());

        // Máquina de outra conta não vê nem conclui as alterações desta pasta.
        $this->actingAs($this->felipe)->post(route('documentos.apagar', $this->pasta), ['caminho' => 'Clientes']);
        CentralIaRunner::create(['user_id' => $this->rossini->id, 'maquina' => 'mac', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->rossini, ['central-ia-runner']);
        $this->postJson(route('documentos.runner.operacoes'), ['maquina' => 'mac'])->assertJsonCount(0, 'operacoes');
        $this->postJson(route('documentos.runner.operacao-concluir', DocumentoOperacao::sole()->id), ['maquina' => 'mac', 'situacao' => 'feita'])->assertNotFound();
        $this->assertSame(1, Documento::count());
    }
}
