<?php

namespace Tests\Feature\Documentos;

use App\Models\CentralIaRunner;
use App\Models\Documento;
use App\Models\DocumentoPasta;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Miniaturas dos documentos (19/10/2026: "identificar o arquivo sem precisar abrir"). */
class DocumentosMiniaturasTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;
    private User $rossini;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Documento::DISCO);
        $this->felipe = User::factory()->create(['name' => 'Felipe']);
        (new PerfilPermissaoSeeder)->run();
        $this->rossini = User::factory()->create();
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
    }

    private static function png(int $w = 1200, int $h = 900): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 30, 140, 160));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /** @param array<string, string> $arquivos caminho => conteúdo */
    private function espelhar(array $arquivos)
    {
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $lista = collect($arquivos)->map(fn ($c, $k) => ['caminho' => $k, 'tamanho' => strlen($c), 'modificado' => '2026-10-19T10:00:00Z'])->values()->all();
        $r = $this->postJson(route('documentos.runner.inventario'), ['maquina' => 'wsl', 'pasta' => 'VixCard', 'arquivos' => $lista])->assertOk();
        foreach ($r->json('enviar') as $c) {
            $this->post(route('documentos.runner.parte', $r->json('pasta_id')), ['maquina' => 'wsl', 'caminho' => $c, 'tamanho' => strlen($arquivos[$c]),
                'modificado' => '2026-10-19T10:00:00Z', 'sha256' => hash('sha256', $arquivos[$c]), 'parte' => 0, 'partes' => 1,
                'arquivo' => UploadedFile::fake()->createWithContent('parte.bin', $arquivos[$c])], ['Accept' => 'application/json'])->assertOk();
        }
        $this->app['auth']->forgetGuards();

        return $r;
    }

    public function test_imagem_vira_miniatura_no_servidor_e_so_quem_ve_a_pasta_ve(): void
    {
        $this->espelhar(['Demo/portal.png' => self::png(), 'Demo/proposta.pdf' => '%PDF-1.4']);
        $doc = Documento::where('nome', 'portal.png')->sole();
        $this->assertTrue($doc->temMiniatura());

        $r = $this->actingAs($this->felipe)->get(route('documentos.miniatura', $doc->id))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        [$w, $h] = getimagesizefromstring($r->streamedContent());
        $this->assertSame([480, 360], [$w, $h], 'reduzida a 480 px no lado maior');
        $this->assertSame('ok', $doc->fresh()->miniatura);

        $this->actingAs($this->rossini)->get(route('documentos.miniatura', $doc->id))->assertNotFound();
        // PDF sem miniatura ainda: 404, a tela cai no ícone.
        $this->actingAs($this->felipe)->get(route('documentos.miniatura', Documento::where('nome', 'proposta.pdf')->value('id')))->assertNotFound();
        $this->actingAs($this->felipe)->get(route('documentos.pasta', [DocumentoPasta::sole(), 'caminho' => 'Demo']))->assertOk()
            ->assertSee(route('documentos.miniatura', $doc->id), false)->assertSee('Imagem · ');
    }

    public function test_imagem_corrompida_fica_sem_e_nao_tenta_de_novo(): void
    {
        $this->espelhar(['quebrada.jpg' => 'isto não é imagem']);
        $doc = Documento::sole();
        $this->actingAs($this->felipe)->get(route('documentos.miniatura', $doc->id))->assertNotFound();
        $this->assertSame('sem', $doc->fresh()->miniatura);
        $this->assertFalse($doc->fresh()->temMiniatura());
    }

    public function test_pdf_e_video_a_maquina_gera_e_o_servidor_regrava(): void
    {
        $r = $this->espelhar(['proposta.pdf' => '%PDF-1.4 a', 'demo.mp4' => 'mp4!', 'foto.png' => self::png(10, 10)]);
        $this->assertSame([], $r->json('miniaturas'), 'o que acabou de subir entra na próxima rodada');
        $r = $this->espelhar(['proposta.pdf' => '%PDF-1.4 a', 'demo.mp4' => 'mp4!', 'foto.png' => self::png(10, 10)]);
        $this->assertEqualsCanonicalizing(['proposta.pdf', 'demo.mp4'], $r->json('miniaturas'), 'só PDF e vídeo; imagem o servidor faz');
        $pasta = $r->json('pasta_id');

        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $this->post(route('documentos.runner.miniatura', $pasta), ['maquina' => 'wsl', 'caminho' => 'proposta.pdf', 'sha256' => hash('sha256', '%PDF-1.4 a'),
            'arquivo' => UploadedFile::fake()->createWithContent('miniatura.jpg', self::png(600, 800))], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('ok', true);
        $this->postJson(route('documentos.runner.miniatura', $pasta), ['maquina' => 'wsl', 'caminho' => 'demo.mp4', 'sha256' => hash('sha256', 'mp4!')])->assertOk()->assertJsonPath('ok', false);
        $this->postJson(route('documentos.runner.miniatura', $pasta), ['maquina' => 'wsl', 'caminho' => 'demo.mp4', 'sha256' => str_repeat('b', 64)])->assertNotFound();
        $pdf = Documento::where('nome', 'proposta.pdf')->sole();
        $this->assertSame(['ok', 'sem'], [$pdf->miniatura, Documento::where('nome', 'demo.mp4')->value('miniatura')]);
        Storage::disk(Documento::DISCO)->assertExists($pdf->caminhoMiniatura());
        $this->assertSame([], $this->espelhar(['proposta.pdf' => '%PDF-1.4 a', 'demo.mp4' => 'mp4!', 'foto.png' => self::png(10, 10)])->json('miniaturas'), 'não pede de novo');

        // Arquivo alterado na origem: miniatura nova.
        $this->espelhar(['proposta.pdf' => '%PDF-1.4 versão 2', 'demo.mp4' => 'mp4!', 'foto.png' => self::png(10, 10)]);
        $this->assertNull($pdf->fresh()->miniatura);
    }
}
