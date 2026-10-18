<?php

namespace Tests\Feature\Documentos;

use App\Models\CentralIaRunner;
use App\Models\Documento;
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

/** Pastas de documentos espelhadas do OneDrive (docs/specs/documentos-espelhados). */
class DocumentosEspelhadosTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;
    private User $victor;
    private User $rossini;

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
    }

    private function inventario(array $arquivos, string $pasta = 'Orçamentos VixCard')
    {
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);

        return $this->postJson(route('documentos.runner.inventario'), ['maquina' => 'wsl', 'pasta' => $pasta, 'arquivos' => $arquivos]);
    }

    private function enviar(int $pasta, string $caminho, string $conteudo, string $modificado = '2026-10-18T10:00:00Z', ?string $sha = null)
    {
        $partes = str_split($conteudo, Espelho::PARTE_BYTES) ?: [''];
        $r = null;
        foreach ($partes as $i => $p) {
            $r = $this->post(route('documentos.runner.parte', $pasta), ['maquina' => 'wsl', 'caminho' => $caminho, 'tamanho' => strlen($conteudo),
                'modificado' => $modificado, 'sha256' => $sha ?? hash('sha256', $conteudo), 'parte' => $i, 'partes' => count($partes),
                'arquivo' => UploadedFile::fake()->createWithContent('parte.bin', $p)], ['Accept' => 'application/json']);
        }

        return $r;
    }

    private function espelhado(): DocumentoPasta
    {
        $id = $this->inventario([['caminho' => 'Lorenzo/proposta.pdf', 'tamanho' => 7, 'modificado' => '2026-10-18T10:00:00Z'],
            ['caminho' => 'notas.txt', 'tamanho' => 5, 'modificado' => '2026-10-18T10:00:00Z']])->json('pasta_id');
        $this->enviar($id, 'Lorenzo/proposta.pdf', '%PDF-1.')->assertOk()->assertJsonPath('completo', true);
        $this->enviar($id, 'notas.txt', 'oi oi')->assertOk();

        return DocumentoPasta::findOrFail($id);
    }

    public function test_inventario_pede_so_o_novo_ou_alterado_e_envio_em_partes_confere_o_sha(): void
    {
        $r = $this->inventario([['caminho' => 'Lorenzo/proposta.pdf', 'tamanho' => 7, 'modificado' => '2026-10-18T10:00:00Z']])->assertOk();
        $this->assertSame(['Lorenzo/proposta.pdf'], $r->json('enviar'));
        $id = $r->json('pasta_id');

        $this->enviar($id, 'Lorenzo/proposta.pdf', '%PDF-1.', sha: str_repeat('a', 64))->assertStatus(422);
        $this->assertSame(0, Documento::count());
        $this->enviar($id, 'Lorenzo/proposta.pdf', '%PDF-1.')->assertOk();
        $d = Documento::sole();
        $this->assertSame(['Lorenzo', 'proposta.pdf', 'pdf', 'application/pdf'], [$d->subpasta, $d->nome, $d->extensao, $d->mime]);
        Storage::disk(Documento::DISCO)->assertExists($d->arquivo);

        // Igual: nada a enviar. Alterado: pede de novo.
        $this->assertSame([], $this->inventario([['caminho' => 'Lorenzo/proposta.pdf', 'tamanho' => 7, 'modificado' => '2026-10-18T10:00:00Z']])->json('enviar'));
        $this->assertSame(['Lorenzo/proposta.pdf'], $this->inventario([['caminho' => 'Lorenzo/proposta.pdf', 'tamanho' => 9, 'modificado' => '2026-10-18T11:00:00Z']])->json('enviar'));
    }

    public function test_arquivo_grande_vai_em_varias_partes_na_ordem(): void
    {
        $conteudo = str_repeat('x', Espelho::PARTE_BYTES + 100);
        $id = $this->inventario([['caminho' => 'catalogo.pdf', 'tamanho' => strlen($conteudo), 'modificado' => '2026-10-18T10:00:00Z']])->json('pasta_id');
        $this->post(route('documentos.runner.parte', $id), ['maquina' => 'wsl', 'caminho' => 'catalogo.pdf', 'tamanho' => strlen($conteudo), 'modificado' => '2026-10-18T10:00:00Z',
            'sha256' => hash('sha256', $conteudo), 'parte' => 1, 'partes' => 2, 'arquivo' => UploadedFile::fake()->createWithContent('p', 'x')], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->enviar($id, 'catalogo.pdf', $conteudo)->assertOk()->assertJsonPath('completo', true);
        $this->assertSame(strlen($conteudo), Documento::sole()->tamanho);
    }

    public function test_caminho_perigoso_e_executavel_sao_recusados(): void
    {
        $r = $this->inventario([
            ['caminho' => '../../etc/passwd', 'tamanho' => 1, 'modificado' => '2026-10-18T10:00:00Z'],
            ['caminho' => '/etc/passwd', 'tamanho' => 1, 'modificado' => '2026-10-18T10:00:00Z'],
            ['caminho' => 'C:/Windows/x.txt', 'tamanho' => 1, 'modificado' => '2026-10-18T10:00:00Z'],
            ['caminho' => 'instalar.exe', 'tamanho' => 1, 'modificado' => '2026-10-18T10:00:00Z'],
            ['caminho' => 'ok.pdf', 'tamanho' => 1, 'modificado' => '2026-10-18T10:00:00Z'],
        ])->assertOk();
        $this->assertSame(['ok.pdf'], $r->json('enviar'));
        $this->enviar($r->json('pasta_id'), 'Lorenzo/../../segredo.pdf', 'x')->assertStatus(422);
    }

    public function test_o_que_some_da_origem_sai_daqui_e_do_disco(): void
    {
        $pasta = $this->espelhado();
        $arquivo = Documento::where('nome', 'notas.txt')->value('arquivo');
        $this->inventario([['caminho' => 'Lorenzo/proposta.pdf', 'tamanho' => 7, 'modificado' => '2026-10-18T10:00:00Z']])->assertJsonPath('removidos', 1);
        $this->assertSame(['proposta.pdf'], $pasta->documentos()->pluck('nome')->all());
        Storage::disk(Documento::DISCO)->assertMissing($arquivo);
        $this->assertSame(1, $pasta->fresh()->arquivos);
    }

    public function test_maquina_de_outra_conta_nao_envia(): void
    {
        $pasta = $this->espelhado();
        Sanctum::actingAs($this->rossini, ['central-ia-runner']);
        $this->postJson(route('documentos.runner.inventario'), ['maquina' => 'wsl', 'pasta' => 'X', 'arquivos' => []])->assertNotFound();
        CentralIaRunner::create(['user_id' => $this->rossini->id, 'maquina' => 'mac', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        $this->post(route('documentos.runner.parte', $pasta->id), ['maquina' => 'mac', 'caminho' => 'a.pdf', 'tamanho' => 1, 'modificado' => '2026-10-18T10:00:00Z',
            'sha256' => hash('sha256', 'a'), 'parte' => 0, 'partes' => 1, 'arquivo' => UploadedFile::fake()->createWithContent('p', 'a')], ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_so_dono_e_compartilhados_veem_admin_nao(): void
    {
        $pasta = $this->espelhado();
        $pdf = Documento::where('nome', 'proposta.pdf')->sole();
        $txt = Documento::where('nome', 'notas.txt')->sole();

        $this->actingAs($this->felipe)->get(route('documentos.pasta', $pasta->id))->assertOk()->assertSee('Lorenzo')->assertSee('proposta.pdf')->assertSee('Compartilhar');
        $this->get(route('documentos.ver', $pdf->id))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('documentos.ver', $txt->id))->assertNotFound('Texto não abre inline, só baixa');
        $this->get(route('documentos.baixar', $txt->id))->assertOk();

        foreach ([$this->victor, $this->rossini] as $u) {
            $this->actingAs($u)->get(route('documentos.pasta', $pasta->id))->assertNotFound();
            $this->get(route('documentos.ver', $pdf->id))->assertNotFound();
            $this->get(route('documentos.baixar', $pdf->id))->assertNotFound();
            $this->get(route('tarefas.index'))->assertDontSee(route('documentos.index'));
        }

        $this->actingAs($this->felipe)->put(route('documentos.acessos', $pasta->id), ['pessoas' => [$this->victor->id]])->assertSessionHas('status');
        $this->actingAs($this->victor)->get(route('documentos.index'))->assertRedirect(route('documentos.pasta', $pasta->id));
        $this->get(route('documentos.pasta', $pasta->id))->assertOk()->assertSee('proposta.pdf')->assertDontSee('Compartilhar');
        $this->get(route('documentos.ver', $pdf->id))->assertOk();
        $this->get(route('tarefas.index'))->assertSee(route('documentos.index'));
        $this->put(route('documentos.acessos', $pasta->id), ['pessoas' => [$this->rossini->id]])->assertNotFound('Só o dono compartilha');
        $this->actingAs($this->rossini)->get(route('documentos.pasta', $pasta->id))->assertNotFound();

        $this->actingAs($this->felipe)->put(route('documentos.acessos', $pasta->id), [])->assertSessionHas('status');
        $this->actingAs($this->victor)->get(route('documentos.pasta', $pasta->id))->assertNotFound();
    }

    public function test_busca_e_agrupamento_por_cliente(): void
    {
        $pasta = $this->espelhado();
        $this->actingAs($this->felipe)->get(route('documentos.pasta', [$pasta->id, 'q' => 'loren']))->assertOk()
            ->assertSee('proposta.pdf')->assertDontSee('notas.txt');
    }
}
