<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Revenda;
use App\Models\Tarefa;
use App\Models\TarefaImagem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Imagens na tarefa (US-064).
 *
 * A feature nasceu da revisão: "o botão saiu do lugar" é uma frase que só quem
 * viu a tela entende, e descrever um defeito por escrito custa rodadas que uma
 * captura encerra de uma vez.
 */
class ImagensDaTarefaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // O disco `public` é o de verdade em produção — é o único que sobrevive
        // à publicação azul/verde. Aqui ele é falso para os arquivos do teste
        // não caírem no `storage/` do repositório.
        Storage::fake('public');
    }

    private function criarTarefa(array $atributos = []): Tarefa
    {
        return Tarefa::factory()->create(array_merge([
            'criado_por_id' => User::factory(),
            'status' => 'em_revisao',
        ], $atributos));
    }

    /**
     * @spec:AC-223 A imagem entra por arquivo escolhido, fica presa à TAREFA (não ao
     * comentário) e volta pronta para a tela: nome, tamanho legível, autor e endereço.
     */
    public function test_anexa_imagem_por_arquivo(): void
    {
        $usuario = User::factory()->create(['name' => 'Rossini Santos']);
        $tarefa = $this->criarTarefa();

        $resposta = $this->actingAs($usuario)
            ->post(route('tarefas.imagens.store', $tarefa), [
                'imagens' => [UploadedFile::fake()->image('botao-torto.png', 800, 600)],
            ])
            ->assertOk();

        $imagem = $tarefa->fresh()->imagens->sole();

        $this->assertSame('botao-torto.png', $imagem->nome_original);
        $this->assertSame($usuario->id, $imagem->autor_id);
        Storage::disk('public')->assertExists($imagem->caminho);

        // O que a galeria desenha sai daqui: sem estes campos, ela teria de
        // consultar o autor por miniatura e formatar o tamanho por conta.
        $resposta->assertJsonPath('imagens.0.nome_original', 'botao-torto.png');
        $resposta->assertJsonPath('imagens.0.autor_nome', 'Rossini Santos');
        $resposta->assertJsonStructure(['imagens' => [['id', 'url', 'tamanho_formatado', 'autor_id']]]);

        // O caminho no disco NÃO viaja para o navegador: o arquivo se pede pela
        // rota, que passa por `auth` e `permissao:tarefas`.
        $resposta->assertJsonMissingPath('imagens.0.caminho');
        $resposta->assertJsonMissingPath('imagens.0.nome_arquivo');
    }

    /**
     * @spec:AC-223 Colar da área de transferência não traz nome — o navegador entrega
     * "image.png". Três prints colados viravam três legendas idênticas; a data é a única
     * coisa que a área de transferência carrega de verdade.
     */
    public function test_imagem_colada_ganha_nome_datado(): void
    {
        $tarefa = $this->criarTarefa();

        $this->actingAs(User::factory()->create())
            ->post(route('tarefas.imagens.store', $tarefa), [
                'imagens' => [UploadedFile::fake()->image('image.png', 400, 300)],
            ])
            ->assertOk();

        $this->assertMatchesRegularExpression(
            '/^captura-\d{4}-\d{2}-\d{2}-\d{6}\.png$/',
            $tarefa->fresh()->imagens->sole()->nome_original
        );
    }

    /**
     * @spec:AC-224 Os limites são os do PHP de produção, não gosto: 2 MB por arquivo
     * (`upload_max_filesize`) e três por envio (o quarto faria o PHP descartar o corpo
     * inteiro do POST e chegar aqui como erro de CSRF). O que não é imagem não entra.
     */
    public function test_recusa_o_que_nao_cabe_e_o_que_nao_e_imagem(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        // Um PDF não vira anexo de tarefa por esta porta.
        $this->actingAs($usuario)
            ->post(route('tarefas.imagens.store', $tarefa), [
                'imagens' => [UploadedFile::fake()->create('contrato.pdf', 40, 'application/pdf')],
            ])
            ->assertSessionHasErrors('imagens.0');

        // Acima de 2 MB o arquivo não chegaria ao PHP em produção; a recusa é
        // dita aqui, com a frase que diz o que mudar.
        $this->actingAs($usuario)
            ->post(route('tarefas.imagens.store', $tarefa), [
                'imagens' => [UploadedFile::fake()->image('print.png')->size(2100)],
            ])
            ->assertSessionHasErrors(['imagens.0' => 'Cada imagem precisa ter até 2 MB.']);

        $this->actingAs($usuario)
            ->post(route('tarefas.imagens.store', $tarefa), [
                'imagens' => collect(range(1, 4))
                    ->map(fn ($n) => UploadedFile::fake()->image("print-{$n}.png"))
                    ->all(),
            ])
            ->assertSessionHasErrors(['imagens' => 'Até três imagens por vez.']);

        $this->assertSame(0, $tarefa->fresh()->imagens->count());
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    /**
     * @spec:AC-225 Anexar responde JSON e NÃO redireciona: o gesto é colar um print no
     * meio de escrever o comentário que o explica, e recarregar a tela ali descartaria o
     * texto ainda não publicado — ele mora no mesmo modal e nada dele foi enviado.
     */
    public function test_anexar_responde_json_em_vez_de_recarregar_a_tela(): void
    {
        $tarefa = $this->criarTarefa();

        $this->actingAs(User::factory()->create())
            ->post(route('tarefas.imagens.store', $tarefa), [
                'imagens' => [UploadedFile::fake()->image('print.png')],
            ])
            ->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->assertSessionMissing('tarefa-aberta');
    }

    /**
     * @spec:AC-226 Só quem anexou apaga. Mesma regra do comentário, e não a do checklist:
     * o item é combinado do time, mas a imagem é o que ALGUÉM mostrou para sustentar um
     * argumento — apagar a prova alheia é reescrever a conversa de outra pessoa.
     */
    public function test_so_quem_anexou_apaga_a_propria_imagem(): void
    {
        $autor = User::factory()->create();
        $outro = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $this->actingAs($autor)->post(route('tarefas.imagens.store', $tarefa), [
            'imagens' => [UploadedFile::fake()->image('print.png')],
        ])->assertOk();

        $imagem = $tarefa->fresh()->imagens->sole();

        $this->actingAs($outro)
            ->delete(route('tarefas.imagens.destroy', $imagem))
            ->assertForbidden();

        $this->assertDatabaseHas('tarefa_imagens', ['id' => $imagem->id]);
        Storage::disk('public')->assertExists($imagem->caminho);

        // O autor apaga, e o arquivo sai do disco junto: linha removida com
        // arquivo órfão é custo que ninguém volta para cobrar.
        $this->actingAs($autor)
            ->delete(route('tarefas.imagens.destroy', $imagem))
            ->assertOk();

        $this->assertDatabaseMissing('tarefa_imagens', ['id' => $imagem->id]);
        Storage::disk('public')->assertMissing($imagem->caminho);
    }

    /**
     * @spec:AC-226 A imagem morre com a tarefa: sem o cascade, excluir uma tarefa deixaria
     * linhas apontando para um `tarefa_id` que não existe mais.
     */
    public function test_imagem_morre_com_a_tarefa(): void
    {
        $tarefa = $this->criarTarefa();

        $this->actingAs(User::factory()->create())->post(route('tarefas.imagens.store', $tarefa), [
            'imagens' => [UploadedFile::fake()->image('print.png')],
        ])->assertOk();

        // `forceDelete`, e não `delete`: a tarefa tem exclusão reversível, e a
        // imagem só some quando a linha some de verdade.
        $tarefa->forceDelete();

        $this->assertDatabaseCount('tarefa_imagens', 0);
    }

    /**
     * @spec:AC-226 A imagem sobrevive à saída de quem a anexou: quem mandou o print pode
     * deixar a empresa, e a prova do defeito continua sendo o que a tarefa tem de mais
     * caro. Sem autor, a legenda diz "Autor removido".
     */
    public function test_imagem_sobrevive_a_saida_de_quem_anexou(): void
    {
        $autor = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $this->actingAs($autor)->post(route('tarefas.imagens.store', $tarefa), [
            'imagens' => [UploadedFile::fake()->image('print.png')],
        ])->assertOk();

        // Direto na tabela, e não por `forceDelete()`: o que se prova aqui é o
        // `nullOnDelete` da chave estrangeira, e o `forceDelete` do usuário
        // dispara o rastro de auditoria — que tenta gravar uma linha apontando
        // para o usuário recém-apagado e morre na própria restrição.
        DB::table('users')->where('id', $autor->id)->delete();

        $imagem = $tarefa->fresh()->imagens->sole();

        $this->assertNull($imagem->autor_id);
        $this->assertSame('Autor removido', $imagem->autor_nome);
    }

    /**
     * @spec:AC-227 O card anuncia a imagem por contagem, e não por miniatura: a faixa com
     * o primeiro print deixaria o card mais alto, e a coluna caberia menos cards por tela
     * justamente na etapa em que mais se anexa imagem.
     */
    public function test_o_card_traz_o_selo_de_imagens_e_a_galeria_abre_no_modal(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa(['titulo' => 'Corrigir layout do modal']);

        $this->actingAs($usuario)->post(route('tarefas.imagens.store', $tarefa), [
            'imagens' => [
                UploadedFile::fake()->image('antes.png'),
                UploadedFile::fake()->image('depois.png'),
            ],
        ])->assertOk();

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        $this->assertStringContainsString('title="2 imagens"', $html);

        // A galeria do modal é desenhada a partir desta semente: sem ela, a
        // tela pediria a lista de novo a cada card aberto.
        $this->assertStringContainsString('imagensDaTarefa('.$tarefa->id, $html);
        $this->assertStringContainsString('antes.png', $html);
        $this->assertStringContainsString('depois.png', $html);
    }

    /**
     * @spec:AC-227 A imagem é entregue pela rota, e não pelo `/storage` do disco: o arquivo
     * mora no disco `public` por uma razão de deploy (é o único que sobrevive ao azul/verde),
     * não porque a captura de um defeito deva ser pública. Arquivo sumido responde 404, e
     * não uma imagem quebrada sem explicação.
     */
    public function test_a_imagem_e_entregue_pela_rota_e_arquivo_sumido_responde_404(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $this->actingAs($usuario)->post(route('tarefas.imagens.store', $tarefa), [
            'imagens' => [UploadedFile::fake()->image('print.png', 120, 90)],
        ])->assertOk();

        $imagem = $tarefa->fresh()->imagens->sole();

        $this->actingAs($usuario)
            ->get(route('tarefas.imagens.ver', $imagem))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        // Deslogado, a imagem não sai: a rota herda o `auth` do resto do quadro.
        $this->post(route('logout'));
        $this->get(route('tarefas.imagens.ver', $imagem))->assertRedirect(route('login'));

        Storage::disk('public')->delete($imagem->caminho);

        $this->actingAs($usuario)
            ->get(route('tarefas.imagens.ver', $imagem))
            ->assertNotFound();
    }

    /**
     * @spec:AC-228 Revenda não alcança as imagens: as rotas seguem a mesma trava do quadro
     * (AC-095), senão o backlog interno vazaria por uma porta lateral — e desta vez levando
     * capturas de tela junto.
     */
    public function test_revenda_nao_alcanca_as_rotas_de_imagem(): void
    {
        $tarefa = $this->criarTarefa();

        $this->actingAs(User::factory()->create())->post(route('tarefas.imagens.store', $tarefa), [
            'imagens' => [UploadedFile::fake()->image('interno.png')],
        ])->assertOk();

        $imagem = $tarefa->fresh()->imagens->sole();

        $revenda = Revenda::create(['nome' => 'Alpha Rev', 'ativo' => true]);
        $daRevenda = User::factory()->create(['revenda_id' => $revenda->id]);

        $this->actingAs($daRevenda)
            ->post(route('tarefas.imagens.store', $tarefa), [
                'imagens' => [UploadedFile::fake()->image('x.png')],
            ])
            ->assertForbidden();

        $this->actingAs($daRevenda)->get(route('tarefas.imagens.ver', $imagem))->assertForbidden();
        $this->actingAs($daRevenda)->delete(route('tarefas.imagens.destroy', $imagem))->assertForbidden();

        $this->assertDatabaseHas('tarefa_imagens', ['id' => $imagem->id]);
        $this->assertSame(1, TarefaImagem::count());
    }
}
