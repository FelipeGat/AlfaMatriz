<?php

namespace Tests\Feature\Mcp;

use App\Models\Tarefa;
use App\Models\TarefaAnexo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * O agente consegue OLHAR o anexo, e não só saber que ele existe.
 *
 * Pela porta HTTP, e não pelo `actingAs` do servidor, porque o que importa
 * aqui é o que de fato viaja na resposta: uma figura tem de chegar como bloco
 * de imagem, e isso só se vê no JSON.
 */
class AnexosTest extends TestCase
{
    use RefreshDatabase;

    private string $limiteDeMemoriaAntes;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        // A redução da figura pergunta antes se ela cabe na memória que SOBRA
        // do processo. No fim da suíte inteira sobra pouco, e a ferramenta faz
        // o que deve — entrega o original —, só que aí o teste da redução
        // mediria a ordem da suíte e não a ferramenta. Sem teto, ele mede a
        // ferramenta.
        $this->limiteDeMemoriaAntes = (string) ini_get('memory_limit');
        ini_set('memory_limit', '-1');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->limiteDeMemoriaAntes);

        parent::tearDown();
    }

    private function anexar(Tarefa $tarefa, UploadedFile $arquivo): TarefaAnexo
    {
        $caminho = $arquivo->storeAs('imagens/tarefas', uniqid().'.'.$arquivo->guessExtension(), 'public');

        return $tarefa->anexos()->create([
            'autor_id' => $tarefa->criado_por_id,
            'nome_original' => $arquivo->getClientOriginalName(),
            'nome_arquivo' => basename($caminho),
            'mime' => $arquivo->getMimeType(),
            'caminho' => $caminho,
            'tamanho' => $arquivo->getSize(),
        ]);
    }

    /** @return array<string, mixed> */
    private function chamar(string $ferramenta, array $argumentos): array
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $ferramenta, 'arguments' => $argumentos],
        ], ['Accept' => 'application/json, text/event-stream'])->assertOk()->json('result');
    }

    public function test_ver_tarefa_lista_os_anexos_com_o_numero_que_ver_anexo_recebe(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['mcp']);
        $tarefa = Tarefa::factory()->create();
        $anexo = $this->anexar($tarefa, UploadedFile::fake()->image('botao-torto.png', 400, 300));

        $resultado = $this->chamar('ver_tarefa', ['tarefa' => '#'.$tarefa->id]);

        $this->assertStringContainsString('anexo '.$anexo->id.': botao-torto.png · imagem', $resultado['content'][0]['text']);
    }

    public function test_figura_grande_chega_como_imagem_reduzida_a_um_lado_legivel(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['mcp']);
        $anexo = $this->anexar(Tarefa::factory()->create(), UploadedFile::fake()->image('tela-5k.png', 3200, 1800));

        $bloco = $this->chamar('ver_anexo', ['anexo' => $anexo->id])['content'][0];

        $this->assertSame('image', $bloco['type']);
        $this->assertSame('image/jpeg', $bloco['mimeType']);

        [$largura, $altura] = getimagesizefromstring(base64_decode($bloco['data']));

        // O lado maior cai para 1568 e a proporção se mantém.
        $this->assertSame(1568, $largura);
        $this->assertSame(882, $altura);
    }

    public function test_figura_pequena_chega_como_esta(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['mcp']);
        $anexo = $this->anexar(Tarefa::factory()->create(), UploadedFile::fake()->image('icone.png', 200, 120));

        $bloco = $this->chamar('ver_anexo', ['anexo' => $anexo->id])['content'][0];

        $this->assertSame('image', $bloco['type']);
        $this->assertSame('image/png', $bloco['mimeType']);
        $this->assertSame([200, 120], array_slice(getimagesizefromstring(base64_decode($bloco['data'])), 0, 2));
    }

    public function test_log_chega_como_texto_e_e_cortado_no_teto(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['mcp']);
        $tarefa = Tarefa::factory()->create();

        $curto = $this->anexar($tarefa, UploadedFile::fake()->createWithContent('erro.log', "SQLSTATE[23000]: chave duplicada\nlinha 2"));
        $longo = $this->anexar($tarefa, UploadedFile::fake()->createWithContent('enorme.log', str_repeat("linha de log\n", 6000)));

        $texto = $this->chamar('ver_anexo', ['anexo' => $curto->id])['content'][0]['text'];
        $this->assertStringContainsString('erro.log', $texto);
        $this->assertStringContainsString('SQLSTATE[23000]: chave duplicada', $texto);
        $this->assertStringNotContainsString('[cortado', $texto);

        $this->assertStringContainsString('[cortado: só os primeiros 60.000 caracteres]',
            $this->chamar('ver_anexo', ['anexo' => $longo->id])['content'][0]['text']);
    }

    public function test_pdf_e_recusado_dizendo_o_que_e_e_o_que_fazer(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['mcp']);
        $anexo = $this->anexar(Tarefa::factory()->create(), UploadedFile::fake()->create('contrato.pdf', 60, 'application/pdf'));

        $resultado = $this->chamar('ver_anexo', ['anexo' => $anexo->id]);

        $this->assertTrue($resultado['isError']);
        $this->assertStringContainsString('contrato.pdf', $resultado['content'][0]['text']);
        $this->assertStringContainsString('Só imagem e texto', $resultado['content'][0]['text']);
    }

    public function test_anexo_que_nao_existe_e_arquivo_que_sumiu_do_disco_sao_ditos(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['mcp']);

        $this->assertTrue($this->chamar('ver_anexo', ['anexo' => 999])['isError']);

        $anexo = $this->anexar(Tarefa::factory()->create(), UploadedFile::fake()->image('some.png', 100, 100));
        Storage::disk('public')->delete($anexo->caminho);

        $resultado = $this->chamar('ver_anexo', ['anexo' => $anexo->id]);

        $this->assertTrue($resultado['isError']);
        $this->assertStringContainsString('não foi encontrado no servidor', $resultado['content'][0]['text']);
    }

    public function test_quem_nao_enxerga_o_quadro_nao_ve_anexo(): void
    {
        Sanctum::actingAs(User::factory()->semPerfil()->create(), ['mcp']);
        $anexo = $this->anexar(Tarefa::factory()->create(), UploadedFile::fake()->image('print.png', 100, 100));

        $resultado = $this->chamar('ver_anexo', ['anexo' => $anexo->id]);

        $this->assertTrue($resultado['isError']);
        $this->assertStringContainsString('não tem permissão', $resultado['content'][0]['text']);
    }
}
