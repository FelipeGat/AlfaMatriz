<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Revenda;
use App\Models\Tarefa;
use App\Models\TarefaAnexo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Anexos na tarefa (US-064).
 *
 * A feature nasceu da revisão: "o botão saiu do lugar" é uma frase que só quem
 * viu a tela entende, e descrever um defeito por escrito custa rodadas que uma
 * captura encerra de uma vez. O log do erro e a planilha do cliente entraram
 * depois, pelo mesmo motivo e no mesmo gesto.
 */
class AnexosDaTarefaTest extends TestCase
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
            ->post(route('tarefas.anexos.store', $tarefa), [
                'anexos' => [UploadedFile::fake()->image('botao-torto.png', 800, 600)],
            ])
            ->assertOk();

        $anexo = $tarefa->fresh()->anexos->sole();

        $this->assertSame('botao-torto.png', $anexo->nome_original);
        $this->assertSame($usuario->id, $anexo->autor_id);
        $this->assertTrue($anexo->eh_imagem);
        Storage::disk('public')->assertExists($anexo->caminho);

        // O que a seção desenha sai daqui: sem estes campos, ela teria de
        // consultar o autor por anexo e formatar o tamanho por conta. E
        // `eh_imagem` é o que decide miniatura ou linha.
        $resposta->assertJsonPath('anexos.0.nome_original', 'botao-torto.png');
        $resposta->assertJsonPath('anexos.0.autor_nome', 'Rossini Santos');
        $resposta->assertJsonPath('anexos.0.eh_imagem', true);
        $resposta->assertJsonStructure(['anexos' => [['id', 'url', 'tamanho_formatado', 'autor_id']]]);

        // O caminho no disco NÃO viaja para o navegador: o arquivo se pede pela
        // rota, que passa por `auth` e `permissao:tarefas`.
        $resposta->assertJsonMissingPath('anexos.0.caminho');
        $resposta->assertJsonMissingPath('anexos.0.nome_arquivo');
    }

    /**
     * @spec:AC-232 O que não é figura entra pela mesma porta e no mesmo gesto: o log do
     * erro, a planilha do cliente, o PDF do boleto. Eles estavam no "Fora de escopo" do
     * spec por não serem imagem — o que nunca foi a razão pela qual se anexa alguma coisa.
     */
    public function test_anexa_log_planilha_e_pdf(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $resposta = $this->actingAs($usuario)
            ->post(route('tarefas.anexos.store', $tarefa), [
                'anexos' => [
                    UploadedFile::fake()->create('erro-console.log', 40, 'text/plain'),
                    UploadedFile::fake()->create('contrato.pdf', 60, 'application/pdf'),
                    UploadedFile::fake()->create('clientes.csv', 20, 'text/csv'),
                ],
            ])
            ->assertOk();

        $anexos = $tarefa->fresh()->anexos;

        $this->assertCount(3, $anexos);
        $this->assertSame(
            ['erro-console.log', 'contrato.pdf', 'clientes.csv'],
            $anexos->pluck('nome_original')->all()
        );

        // Nenhum deles é figura: os três saem como linha, e não como miniatura.
        $this->assertTrue($anexos->every(fn ($anexo) => $anexo->eh_imagem === false));
        $resposta->assertJsonPath('anexos.0.eh_imagem', false);

        // O `.log` entra sem `log` estar na lista de extensões validadas: texto
        // puro é `text/plain`, que deduz `txt`. Se um dia alguém "consertar" a
        // lista acrescentando `log`, este teste continua passando — e é por isso
        // que ele existe: o que não pode é o `.log` deixar de entrar.
        $this->assertStringEndsWith('.log', $anexos->first()->nome_original);
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
            ->post(route('tarefas.anexos.store', $tarefa), [
                'anexos' => [UploadedFile::fake()->image('image.png', 400, 300)],
            ])
            ->assertOk();

        $this->assertMatchesRegularExpression(
            '/^captura-\d{4}-\d{2}-\d{2}-\d{6}\.png$/',
            $tarefa->fresh()->anexos->sole()->nome_original
        );
    }

    /**
     * @spec:AC-233 O NOME NO DISCO sai do conteúdo, nunca do nome que o navegador mandou.
     *
     * São duas travas, e a de fora não é nossa: a regra `mimes:` do Laravel recusa por
     * conta própria qualquer arquivo cuja extensão ENVIADA seja `php`, `phtml` ou `phar`,
     * mesmo que o conteúdo seja um PNG impecável. Ela é o motivo de o caso mais óbvio
     * nunca ter sido alcançável — e é exatamente por isso que este teste a fixa: uma trava
     * de que se depende sem saber é uma trava que some no dia de uma atualização.
     *
     * A de dentro é nossa: a extensão do disco vem de `guessExtension()`, o mesmo valor que
     * a validação aprovou. Ela cobre o que a de fora não olha — toda extensão que não está
     * na lista curta do Laravel.
     */
    public function test_o_nome_no_disco_nao_herda_a_extensao_enviada(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();
        $png = UploadedFile::fake()->image('inofensivo.png', 40, 30);

        // A trava de fora: PNG de verdade com nome de script não passa, e quem
        // o recusa é o Laravel, antes de esta rota ter qualquer opinião.
        $comoScript = new UploadedFile($png->getPathname(), 'payload.php', 'image/png', null, true);

        $this->actingAs($usuario)
            ->post(route('tarefas.anexos.store', $tarefa), ['anexos' => [$comoScript]])
            ->assertSessionHasErrors('anexos.0');

        // A trava de dentro: extensão que o Laravel não vigia, e que mesmo
        // assim não decide nada. O conteúdo é PNG, então o disco recebe `.png`
        // — o `.xlsx` do nome fica só na legenda que a tela mostra.
        $comoPlanilha = new UploadedFile($png->getPathname(), 'relatorio.xlsx', 'image/png', null, true);

        $this->actingAs($usuario)
            ->post(route('tarefas.anexos.store', $tarefa), ['anexos' => [$comoPlanilha]])
            ->assertOk();

        $anexo = $tarefa->fresh()->anexos->sole();

        $this->assertStringEndsWith('.png', $anexo->nome_arquivo);
        $this->assertStringEndsWith('.png', $anexo->caminho);
        $this->assertSame('relatorio.xlsx', $anexo->nome_original);

        // E o disco não guarda extensão que ninguém aprovou.
        $this->assertEmpty(
            array_filter(
                Storage::disk('public')->allFiles(),
                fn ($caminho) => ! str_ends_with($caminho, '.png')
            )
        );
    }

    /**
     * @spec:AC-224 Os limites são os do PHP de produção, não gosto: 12 MB por arquivo
     * (`upload_max_filesize`) e três por envio. O que não está na lista de tipos não entra —
     * e SVG e HTML são documento com script dentro, não figura nem texto.
     */
    public function test_recusa_o_que_nao_cabe_e_o_tipo_que_nao_entra(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        // SVG, HTML e ZIP: os dois primeiros porque esta rota os devolveria no
        // mesmo domínio da sessão de quem abre a tarefa; o ZIP porque a
        // validação não alcança o que está dentro dele.
        foreach (['script.svg' => 'image/svg+xml', 'pagina.html' => 'text/html', 'pacote.zip' => 'application/zip'] as $nome => $mime) {
            $this->actingAs($usuario)
                ->post(route('tarefas.anexos.store', $tarefa), [
                    'anexos' => [UploadedFile::fake()->create($nome, 10, $mime)],
                ])
                ->assertSessionHasErrors('anexos.0');
        }

        // Acima de 12 MB o arquivo não chegaria ao PHP em produção; a recusa é
        // dita aqui, com a frase que diz o que mudar.
        $this->actingAs($usuario)
            ->post(route('tarefas.anexos.store', $tarefa), [
                'anexos' => [UploadedFile::fake()->create('dump.csv', 12500, 'text/csv')],
            ])
            ->assertSessionHasErrors(['anexos.0' => 'Cada arquivo precisa ter até 12 MB.']);

        $this->actingAs($usuario)
            ->post(route('tarefas.anexos.store', $tarefa), [
                'anexos' => collect(range(1, 4))
                    ->map(fn ($n) => UploadedFile::fake()->image("print-{$n}.png"))
                    ->all(),
            ])
            ->assertSessionHasErrors(['anexos' => 'Até três arquivos por vez.']);

        $this->assertSame(0, $tarefa->fresh()->anexos->count());
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
            ->post(route('tarefas.anexos.store', $tarefa), [
                'anexos' => [UploadedFile::fake()->image('print.png')],
            ])
            ->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->assertSessionMissing('tarefa-aberta');
    }

    /**
     * @spec:AC-226 Só quem anexou apaga. Mesma regra do comentário, e não a do checklist:
     * o item é combinado do time, mas o anexo é o que ALGUÉM mostrou para sustentar um
     * argumento — apagar a prova alheia é reescrever a conversa de outra pessoa.
     */
    public function test_so_quem_anexou_apaga_o_proprio_anexo(): void
    {
        $autor = User::factory()->create();
        $outro = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $this->actingAs($autor)->post(route('tarefas.anexos.store', $tarefa), [
            'anexos' => [UploadedFile::fake()->image('print.png')],
        ])->assertOk();

        $anexo = $tarefa->fresh()->anexos->sole();

        $this->actingAs($outro)
            ->delete(route('tarefas.anexos.destroy', $anexo))
            ->assertForbidden();

        $this->assertDatabaseHas('tarefa_anexos', ['id' => $anexo->id]);
        Storage::disk('public')->assertExists($anexo->caminho);

        // O autor apaga, e o arquivo sai do disco junto: linha removida com
        // arquivo órfão é custo que ninguém volta para cobrar.
        $this->actingAs($autor)
            ->delete(route('tarefas.anexos.destroy', $anexo))
            ->assertOk();

        $this->assertDatabaseMissing('tarefa_anexos', ['id' => $anexo->id]);
        Storage::disk('public')->assertMissing($anexo->caminho);
    }

    /**
     * @spec:AC-226 O anexo morre com a tarefa: sem o cascade, excluir uma tarefa deixaria
     * linhas apontando para um `tarefa_id` que não existe mais.
     */
    public function test_anexo_morre_com_a_tarefa(): void
    {
        $tarefa = $this->criarTarefa();

        $this->actingAs(User::factory()->create())->post(route('tarefas.anexos.store', $tarefa), [
            'anexos' => [UploadedFile::fake()->image('print.png')],
        ])->assertOk();

        // `forceDelete`, e não `delete`: a tarefa tem exclusão reversível, e o
        // anexo só some quando a linha some de verdade.
        $tarefa->forceDelete();

        $this->assertDatabaseCount('tarefa_anexos', 0);
    }

    /**
     * @spec:AC-226 O anexo sobrevive à saída de quem o anexou: quem mandou o print pode
     * deixar a empresa, e a prova do defeito continua sendo o que a tarefa tem de mais
     * caro. Sem autor, a legenda diz "Autor removido".
     */
    public function test_anexo_sobrevive_a_saida_de_quem_anexou(): void
    {
        $autor = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $this->actingAs($autor)->post(route('tarefas.anexos.store', $tarefa), [
            'anexos' => [UploadedFile::fake()->image('print.png')],
        ])->assertOk();

        // Direto na tabela, e não por `forceDelete()`: o que se prova aqui é o
        // `nullOnDelete` da chave estrangeira, e o `forceDelete` do usuário
        // dispara o rastro de auditoria — que tenta gravar uma linha apontando
        // para o usuário recém-apagado e morre na própria restrição.
        DB::table('users')->where('id', $autor->id)->delete();

        $anexo = $tarefa->fresh()->anexos->sole();

        $this->assertNull($anexo->autor_id);
        $this->assertSame('Autor removido', $anexo->autor_nome);
    }

    /**
     * @spec:AC-227 O card anuncia o anexo por contagem, e não por miniatura: a faixa com
     * o primeiro print deixaria o card mais alto, e a coluna caberia menos cards por tela
     * justamente na etapa em que mais se anexa arquivo. Um selo só para print e log — a
     * distinção entre eles não muda nada de fora do card.
     */
    public function test_o_card_traz_o_selo_de_anexos_e_a_secao_abre_no_modal(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa(['titulo' => 'Corrigir layout do modal']);

        $this->actingAs($usuario)->post(route('tarefas.anexos.store', $tarefa), [
            'anexos' => [
                UploadedFile::fake()->image('antes.png'),
                UploadedFile::fake()->create('erro.log', 12, 'text/plain'),
            ],
        ])->assertOk();

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        $this->assertStringContainsString('title="2 anexos"', $html);

        // A seção do modal é desenhada a partir desta semente: sem ela, a tela
        // pediria a lista de novo a cada card aberto.
        $this->assertStringContainsString('anexosDaTarefa('.$tarefa->id, $html);
        $this->assertStringContainsString('antes.png', $html);
        $this->assertStringContainsString('erro.log', $html);
    }

    /**
     * @spec:AC-227 O anexo é entregue pela rota, e não pelo `/storage` do disco: o arquivo
     * mora no disco `public` por uma razão de deploy (é o único que sobrevive ao azul/verde),
     * não porque a captura de um defeito deva ser pública. Arquivo sumido responde 404, e
     * não uma imagem quebrada sem explicação.
     *
     * @spec:AC-233 Só figura sai embutida — a grade precisa dela dentro de um `<img>`. Todo
     * o resto sai como download, para que ampliar a lista de tipos aceitos um dia não
     * transforme esta rota em hospedagem de script no domínio da sessão.
     */
    public function test_o_anexo_e_entregue_pela_rota_e_so_figura_sai_embutida(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $this->actingAs($usuario)->post(route('tarefas.anexos.store', $tarefa), [
            'anexos' => [
                UploadedFile::fake()->image('print.png', 120, 90),
                UploadedFile::fake()->create('erro.log', 12, 'text/plain'),
            ],
        ])->assertOk();

        [$imagem, $arquivo] = $tarefa->fresh()->anexos->all();

        $daImagem = $this->actingAs($usuario)
            ->get(route('tarefas.anexos.ver', $imagem))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringStartsWith('inline', $daImagem->headers->get('content-disposition'));

        $doArquivo = $this->actingAs($usuario)
            ->get(route('tarefas.anexos.ver', $arquivo))
            ->assertOk();

        $this->assertStringStartsWith('attachment', $doArquivo->headers->get('content-disposition'));

        // Deslogado, o anexo não sai: a rota herda o `auth` do resto do quadro.
        $this->post(route('logout'));
        $this->get(route('tarefas.anexos.ver', $imagem))->assertRedirect(route('login'));

        Storage::disk('public')->delete($imagem->caminho);

        $this->actingAs($usuario)
            ->get(route('tarefas.anexos.ver', $imagem))
            ->assertNotFound();
    }

    /**
     * @spec:AC-232 O histórico abre os anexos em leitura, e nas duas formas.
     *
     * Tarefa encerrada se lê, não se anexa: lá a lista sai pronta do Blade, sem Alpine
     * nenhum. É um SEGUNDO desenho da mesma seção — o do modal é JavaScript —, e o que
     * ninguém renderiza ninguém vê quebrar: a versão em leitura nunca foi exercitada desde
     * que nasceu, de manhã. Aqui ela é, com uma figura e um arquivo, que são os dois
     * caminhos que o Blade separa.
     */
    public function test_o_historico_abre_os_anexos_em_leitura(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa(['titulo' => 'Erro no fechamento', 'status' => 'pronta_producao']);

        $this->actingAs($usuario)->post(route('tarefas.anexos.store', $tarefa), [
            'anexos' => [
                UploadedFile::fake()->image('tela-do-erro.png', 200, 150),
                UploadedFile::fake()->create('stacktrace.log', 30, 'text/plain'),
            ],
        ])->assertOk();

        // Encerra pelo caminho da tela, que é o que manda a tarefa ao histórico.
        $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'concluida',
            'versao_producao' => 'v1.4.2',
        ])->assertSessionMissing('erro');

        $html = $this->actingAs($usuario)->get(route('tarefas.historico'))->assertOk()->getContent();

        // O botão anuncia o que há para ler — sem os anexos no rótulo, a tarefa
        // encerrada que só tinha prints não abriria botão nenhum.
        $this->assertStringContainsString('2 anexos', $html);

        // As duas formas, desenhadas pelo Blade e não pelo Alpine: a miniatura
        // da figura e a linha do arquivo.
        $this->assertStringContainsString('tela-do-erro.png', $html);
        $this->assertStringContainsString('stacktrace.log', $html);
        $this->assertStringNotContainsString('anexosDaTarefa(', $html);
    }

    /**
     * @spec:AC-228 Revenda não alcança os anexos: as rotas seguem a mesma trava do quadro
     * (AC-095), senão o backlog interno vazaria por uma porta lateral — e desta vez levando
     * capturas de tela e log de cliente junto.
     */
    public function test_revenda_nao_alcanca_as_rotas_de_anexo(): void
    {
        $tarefa = $this->criarTarefa();

        $this->actingAs(User::factory()->create())->post(route('tarefas.anexos.store', $tarefa), [
            'anexos' => [UploadedFile::fake()->image('interno.png')],
        ])->assertOk();

        $anexo = $tarefa->fresh()->anexos->sole();

        $revenda = Revenda::create(['nome' => 'Alpha Rev', 'ativo' => true]);
        $daRevenda = User::factory()->create(['revenda_id' => $revenda->id]);

        $this->actingAs($daRevenda)
            ->post(route('tarefas.anexos.store', $tarefa), [
                'anexos' => [UploadedFile::fake()->image('x.png')],
            ])
            ->assertForbidden();

        $this->actingAs($daRevenda)->get(route('tarefas.anexos.ver', $anexo))->assertForbidden();
        $this->actingAs($daRevenda)->delete(route('tarefas.anexos.destroy', $anexo))->assertForbidden();

        $this->assertDatabaseHas('tarefa_anexos', ['id' => $anexo->id]);
        $this->assertSame(1, TarefaAnexo::count());
    }
}
