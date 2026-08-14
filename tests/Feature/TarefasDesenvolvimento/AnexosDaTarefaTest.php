<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Revenda;
use App\Models\Tarefa;
use App\Models\TarefaAnexo;
use App\Models\User;
use App\Services\MiniaturaDeAnexo;
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
     *
     * E morre INTEIRO — linha e arquivo. O cascade é do banco, que só sabe de
     * linhas: até 14/08/2026 excluir uma tarefa deixava cada print e cada log no
     * disco para sempre, sem erro nenhum e sem nada que os apontasse.
     */
    public function test_anexo_morre_com_a_tarefa(): void
    {
        $tarefa = $this->criarTarefa();

        $this->actingAs(User::factory()->create())->post(route('tarefas.anexos.store', $tarefa), [
            'anexos' => [
                UploadedFile::fake()->image('print.png'),
                UploadedFile::fake()->create('erro.log', 12, 'text/plain'),
            ],
        ])->assertOk();

        $caminhos = $tarefa->fresh()->anexos->pluck('caminho');
        $this->assertCount(2, $caminhos);

        // `forceDelete`, e não `delete`: a tarefa tem exclusão reversível, e o
        // anexo só some quando a linha some de verdade — uma tarefa que ainda
        // pode voltar precisa voltar com as provas.
        $tarefa->delete();
        $caminhos->each(fn (string $caminho) => Storage::disk('public')->assertExists($caminho));

        $tarefa->forceDelete();

        $this->assertDatabaseCount('tarefa_anexos', 0);
        $caminhos->each(fn (string $caminho) => Storage::disk('public')->assertMissing($caminho));
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

        // O selo é do CARD, e o card está no quadro.
        $quadro = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        $this->assertStringContainsString('title="2 anexos"', $quadro);

        // A seção é do MODAL, que é buscado à parte desde que o quadro parou de
        // imprimir um modal por tarefa. Ela é desenhada a partir desta semente:
        // sem ela, a tela pediria a lista de novo a cada card aberto.
        $html = $this->actingAs($usuario)->get(route('tarefas.modal', $tarefa))->assertOk()->getContent();

        $this->assertStringContainsString('anexosDaTarefa('.$tarefa->id, $html);
        $this->assertStringContainsString('antes.png', $html);
        $this->assertStringContainsString('erro.log', $html);

        // A semente carrega o endereço da MINIATURA, que é o que a grade pinta.
        // O nome do campo é o contrato entre o servidor e o `x-for`: errá-lo
        // não dá erro nenhum, dá `<img src="">` — doze molduras vazias onde
        // deveriam estar as provas, e nada na tela dizendo por quê.
        $this->assertStringContainsString('url_miniatura', $html);
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
     * @spec:AC-235 A figura grande ganha uma versão pequena, e é ELA que a grade pinta.
     *
     * A caixa da grade tem ~140×105 e o `<img>` apontava para o original, de até 12 MB.
     * Nenhuma camada de fora resolve: a rota responde `private`, então a borda não guarda
     * a figura, e PNG e JPEG não encolhem com `gzip` no caminho.
     *
     * O ORIGINAL continua intacto e continua sendo o que o clique abre — a miniatura é só
     * o que a grade desenha.
     */
    public function test_a_figura_grande_ganha_miniatura_e_a_grade_aponta_para_ela(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $this->actingAs($usuario)->post(route('tarefas.anexos.store', $tarefa), [
            'anexos' => [UploadedFile::fake()->image('print-grande.png', 1600, 1200)],
        ])->assertOk();

        $anexo = $tarefa->fresh()->anexos->sole();

        $this->assertNotNull($anexo->caminho_miniatura);
        Storage::disk('public')->assertExists($anexo->caminho_miniatura);
        Storage::disk('public')->assertExists($anexo->caminho);

        // Menor que o original, que é a razão de ela existir.
        $this->assertLessThan(
            Storage::disk('public')->size($anexo->caminho),
            Storage::disk('public')->size($anexo->caminho_miniatura),
            'Uma miniatura que não é menor que o original não economiza nada.'
        );

        // 320 no maior lado, e a proporção preservada: recortar aqui decidiria
        // pela grade um enquadramento que o CSS já faz, e erraria no dia em que
        // a caixa deixasse de ser 4/3.
        [$largura, $altura] = getimagesize(Storage::disk('public')->path($anexo->caminho_miniatura));
        $this->assertSame(320, $largura);
        $this->assertSame(240, $altura);

        // Os dois endereços são diferentes, e o do card continua sendo o original.
        $this->assertStringContainsString('min=1', $anexo->url_miniatura);
        $this->assertStringNotContainsString('min=1', $anexo->url);
    }

    /**
     * @spec:AC-235 Nulo é resposta normal, e a tela cai no original sem saber da diferença.
     *
     * Três casos legítimos de não haver miniatura: o anexo não é figura, a figura já é
     * menor que a grade (reduzir 200px para 320px produziria uma segunda cópia no disco
     * para não economizar nada) e o formato que o GD não lê. Nos três, `url_miniatura`
     * devolve o original — é o comportamento de antes desta coluna existir.
     */
    public function test_o_que_nao_precisa_de_miniatura_cai_no_original(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $this->actingAs($usuario)->post(route('tarefas.anexos.store', $tarefa), [
            'anexos' => [
                UploadedFile::fake()->image('print-pequeno.png', 200, 150),
                UploadedFile::fake()->create('erro.log', 30, 'text/plain'),
            ],
        ])->assertOk();

        [$pequena, $arquivo] = $tarefa->fresh()->anexos->all();

        foreach ([$pequena, $arquivo] as $anexo) {
            $this->assertNull($anexo->caminho_miniatura);
            $this->assertSame($anexo->url, $anexo->url_miniatura);
        }

        // E o `?min=1` copiado à mão não vira 404: cai no original.
        $this->actingAs($usuario)
            ->get(route('tarefas.anexos.ver', ['anexo' => $pequena, 'min' => 1]))
            ->assertOk();
    }

    /**
     * @spec:AC-235 A miniatura sai pela MESMA rota, com os mesmos cuidados.
     *
     * Uma rota ao lado seria uma segunda cópia de quatro decisões — `nosniff`, embutido só
     * para figura, `private` e o cache imutável —, e a cópia que ninguém relê é a que fica
     * para trás. Aqui o `?min=1` só troca qual arquivo sai.
     */
    public function test_a_miniatura_sai_pela_mesma_rota_com_os_mesmos_cuidados(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $this->actingAs($usuario)->post(route('tarefas.anexos.store', $tarefa), [
            'anexos' => [UploadedFile::fake()->image('print.png', 1600, 1200)],
        ])->assertOk();

        $anexo = $tarefa->fresh()->anexos->sole();

        $resposta = $this->actingAs($usuario)
            ->get(route('tarefas.anexos.ver', ['anexo' => $anexo, 'min' => 1]))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        // Por diretiva e não por texto inteiro, como o teste do cache do
        // original logo abaixo: o Symfony reordena as diretivas por conta.
        $cache = $resposta->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('max-age=31536000', $cache);
        $this->assertStringContainsString('immutable', $cache);
        $this->assertStringNotContainsString('public', $cache);

        // Sempre JPEG, venha o original de que formato vier — e embutida, que é
        // como a grade a coloca dentro de um `<img>`.
        $this->assertSame('image/jpeg', $resposta->headers->get('content-type'));
        $this->assertStringStartsWith('inline', $resposta->headers->get('content-disposition'));

        // E o que sai é menos byte do que o original pela mesma rota.
        $original = $this->actingAs($usuario)->get(route('tarefas.anexos.ver', $anexo))->assertOk();

        $this->assertLessThan(
            strlen($original->streamedContent()),
            strlen($resposta->streamedContent()),
            'A rota devolveu a miniatura do mesmo tamanho do original — o ?min não pegou.'
        );

        // Deslogado não sai, como o original: a versão pequena de um print de
        // cliente é o print de um cliente.
        $this->post(route('logout'));
        $this->get(route('tarefas.anexos.ver', ['anexo' => $anexo, 'min' => 1]))
            ->assertRedirect(route('login'));
    }

    /**
     * @spec:AC-235 Os anexos que já estavam no disco ganham miniatura sem ninguém pedir.
     *
     * É o que a migração faz ao subir. O laço mora no serviço justamente para caber aqui:
     * migração roda uma vez, sobre uma tabela vazia no ambiente de teste, e o que ela faz
     * nunca seria exercitado — o backfill que ninguém testa é o que se descobre torto em
     * produção, onde já não dá para rodar de novo.
     *
     * São três figuras grandes de propósito. O laço PAGINA, e o filtro (`caminho_miniatura`
     * nula) muda a cada linha que ele preenche: paginando por deslocamento, a segunda
     * página pularia tantas quantas a primeira preencheu, e o backfill deixaria metade do
     * acervo para trás sem reclamar de nada.
     */
    public function test_o_acervo_antigo_ganha_miniatura_e_o_laco_nao_pula_linha(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $this->actingAs($usuario)->post(route('tarefas.anexos.store', $tarefa), [
            'anexos' => [
                UploadedFile::fake()->image('um.png', 900, 700),
                UploadedFile::fake()->image('dois.png', 900, 700),
                UploadedFile::fake()->image('tres.png', 900, 700),
            ],
        ])->assertOk();

        // Volta o acervo ao estado de antes da coluna existir: linhas com o
        // original no disco e nenhuma miniatura.
        $anexos = $tarefa->fresh()->anexos;
        Storage::disk('public')->delete($anexos->pluck('caminho_miniatura')->filter()->all());
        DB::table('tarefa_anexos')->update(['caminho_miniatura' => null]);

        // Uma linha por página, que é o que obriga o laço a virar de página
        // duas vezes com o filtro mudando debaixo dele. No tamanho de verdade
        // (500) as três caberiam numa página só e o erro de deslocamento
        // passaria despercebido: com `each` no lugar de `eachById`, isto aqui
        // devolve 2 e a do meio fica para trás em silêncio.
        $this->assertSame(3, MiniaturaDeAnexo::gerarAsQueFaltam(porVez: 1));

        $tarefa->fresh()->anexos->each(function (TarefaAnexo $anexo): void {
            $this->assertNotNull($anexo->caminho_miniatura, 'O laço pulou uma linha do acervo.');
            Storage::disk('public')->assertExists($anexo->caminho_miniatura);
        });

        // Rodar de novo não refaz o que já está feito — a migração pode ser
        // reaplicada numa restauração, e regerar tudo custaria uma volta de GD
        // por anexo para chegar ao mesmo lugar.
        $this->assertSame(0, MiniaturaDeAnexo::gerarAsQueFaltam());
    }

    /**
     * @spec:AC-235 Apagar o anexo leva as DUAS metades do disco.
     *
     * A miniatura é o segundo arquivo de uma linha só, e é o tipo de coisa que se esquece:
     * quem apagasse só o original deixaria para trás um órfão que nem aparece na pasta ao
     * lado do irmão, porque o irmão já não está lá.
     */
    public function test_apagar_o_anexo_leva_a_miniatura_junto(): void
    {
        $autor = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $this->actingAs($autor)->post(route('tarefas.anexos.store', $tarefa), [
            'anexos' => [UploadedFile::fake()->image('print.png', 1600, 1200)],
        ])->assertOk();

        $anexo = $tarefa->fresh()->anexos->sole();
        $miniatura = $anexo->caminho_miniatura;

        $this->assertNotNull($miniatura);

        $this->actingAs($autor)
            ->delete(route('tarefas.anexos.destroy', $anexo))
            ->assertOk();

        Storage::disk('public')->assertMissing($anexo->caminho);
        Storage::disk('public')->assertMissing($miniatura);
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

        // A figura vai GRANDE aqui de propósito: é o que faz nascer miniatura, e
        // sem ela a conferência lá embaixo passaria por acaso.
        $this->actingAs($usuario)->post(route('tarefas.anexos.store', $tarefa), [
            'anexos' => [
                UploadedFile::fake()->image('tela-do-erro.png', 1400, 900),
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

        // Este é o SEGUNDO desenho da grade, em Blade puro, e ele também pinta
        // a miniatura — uma tarefa encerrada tem tantos prints quanto uma
        // aberta, e a tela do histórico não tem por que baixar os originais.
        $this->assertStringContainsString('min=1', $html);
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

    /**
     * @spec:AC-235 A miniatura não é baixada de novo a cada abertura.
     *
     * O anexo sai por rota com `auth` (AC-227), e por isso herdava o `no-cache, private`
     * que o Laravel dá a toda página com sessão — sem `ETag` nem `Last-Modified` para uma
     * revalidação responder 304. Abrir a mesma tarefa dez vezes baixava os mesmos prints
     * dez vezes, cada um por um pedido de PHP inteiro. E tudo chegava no pior momento: a
     * grade só pede as figuras quando o modal ABRE, porque `loading="lazy"` dentro de um
     * modal fechado não pede nada.
     *
     * Guardar é seguro porque o arquivo é imutável: cada envio cria linha e nome novos, e o
     * id nunca passa a apontar para outro conteúdo. `private` é o que mantém isso no
     * navegador de quem abriu, e fora de cache compartilhado — o teste fixa os dois juntos,
     * porque `public` aqui seria vazar anexo de cliente para quem passar pelo caminho.
     */
    public function test_a_imagem_pode_ser_guardada_pelo_navegador_de_quem_abriu(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $this->actingAs($usuario)->post(route('tarefas.anexos.store', $tarefa), [
            'anexos' => [UploadedFile::fake()->image('tela.png', 1200, 800)],
        ])->assertOk();

        $cache = $this->actingAs($usuario)
            ->get(route('tarefas.anexos.ver', $tarefa->fresh()->anexos->sole()))
            ->assertOk()
            ->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('max-age=31536000', $cache);
        $this->assertStringNotContainsString('no-cache', $cache);
        $this->assertStringNotContainsString('no-store', $cache);

        // `public` seria pôr anexo de cliente em qualquer cache do caminho.
        $this->assertStringNotContainsString('public', $cache);
    }

    /**
     * @spec:AC-234 A prova entra JUNTO com a tarefa: quem abre uma tarefa quase sempre
     * está olhando para o print que a motivou, e pedir para salvar primeiro e anexar
     * depois é pedir um segundo gesto — que se deixa para depois, e aí a tarefa nasce
     * descrevendo por escrito o que já estava na tela.
     */
    public function test_a_tarefa_nasce_com_os_anexos_junto(): void
    {
        $usuario = User::factory()->create(['name' => 'Rossini Santos']);

        $this->actingAs($usuario)
            ->post(route('tarefas.store'), [
                'titulo' => 'Botão de salvar saiu do lugar no Chrome',
                'anexos' => [
                    UploadedFile::fake()->image('tela-do-erro.png', 800, 600),
                    UploadedFile::fake()->create('console.log', 40, 'text/plain'),
                ],
            ])
            ->assertRedirect(route('tarefas.index'));

        $tarefa = Tarefa::sole();
        $anexos = $tarefa->anexos;

        $this->assertCount(2, $anexos);
        $this->assertSame(['tela-do-erro.png', 'console.log'], $anexos->pluck('nome_original')->all());
        $this->assertTrue($anexos->every(fn ($anexo) => $anexo->autor_id === $usuario->id));

        // Os dois passam pela mesma gravação da tarefa já aberta: o arquivo vai
        // parar no disco, e a separação entre miniatura e linha continua saindo
        // do conteúdo, não do nome.
        $this->assertSame([true, false], $anexos->pluck('eh_imagem')->all());
        $anexos->each(fn ($anexo) => Storage::disk('public')->assertExists($anexo->caminho));
    }

    /**
     * @spec:AC-234 O anexo recusado leva a criação inteira junto, em vez de a tarefa
     * nascer sem ele: a recusa acontece com o modal aberto e o texto todo ainda na tela
     * (o envio é parcial, sem recarga), então corrigir o arquivo custa um clique. Nascer
     * sem o print seria a pior das duas: a tarefa fica, a prova some, e ninguém é avisado.
     */
    public function test_anexo_recusado_recusa_a_criacao_inteira(): void
    {
        $usuario = User::factory()->create();
        $png = UploadedFile::fake()->image('inofensivo.png', 40, 30);

        $this->actingAs($usuario)
            ->post(route('tarefas.store'), [
                'titulo' => 'Erro no fechamento do mês',
                'anexos' => [new UploadedFile($png->getPathname(), 'payload.php', 'image/png', null, true)],
            ])
            ->assertSessionHasErrors('anexos.0');

        $this->assertSame(0, Tarefa::count());
        $this->assertSame(0, TarefaAnexo::count());
    }

    /**
     * @spec:AC-234 O teto de três é do envio, e na criação o envio é um só: os arquivos
     * viajam no mesmo POST do formulário, então não existe "próxima leva" antes de a
     * tarefa existir. O quarto arquivo entra com ela já aberta.
     */
    public function test_a_criacao_aceita_no_maximo_tres_anexos(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('tarefas.store'), [
                'titulo' => 'Migrar o relatório antigo',
                'anexos' => [
                    UploadedFile::fake()->image('um.png'),
                    UploadedFile::fake()->image('dois.png'),
                    UploadedFile::fake()->image('tres.png'),
                    UploadedFile::fake()->image('quatro.png'),
                ],
            ])
            ->assertSessionHasErrors('anexos');

        $this->assertSame(0, Tarefa::count());
    }

    /**
     * @spec:AC-234
     *
     * @spec:AC-137 O clique duplo não duplica o anexo, e por isso a gravação mora DENTRO
     * da trava de reenvio: os dois envios carregam os mesmos arquivos, e gravá-los ao lado
     * dela deixaria o print repetido justamente na tarefa que a trava preservou.
     */
    public function test_clique_duplo_na_criacao_nao_duplica_o_anexo(): void
    {
        $usuario = User::factory()->create();
        $envio = ['titulo' => 'Corrigir o boleto vencido', 'prioridade' => 'media'];

        // Arquivos novos no segundo envio, e não os mesmos objetos: o navegador
        // manda o conteúdo de novo, e o `UploadedFile` do primeiro já foi movido
        // para o disco — reaproveitá-lo testaria outra coisa.
        $this->actingAs($usuario)->post(route('tarefas.store'), $envio + [
            'anexos' => [UploadedFile::fake()->image('boleto.png')],
        ]);

        $this->actingAs($usuario)->post(route('tarefas.store'), $envio + [
            'anexos' => [UploadedFile::fake()->image('boleto.png')],
        ]);

        $this->assertSame(1, Tarefa::count());
        $this->assertSame(1, TarefaAnexo::count());
    }

    /**
     * @spec:AC-234 O formulário de criação DECLARA que carrega arquivo, e o de edição não.
     *
     * As duas linhas somem em silêncio: sem `name`, o campo não entra no envio e a tarefa
     * nasce sem os anexos que a tela acabou de mostrar; sem `enctype`, o navegador manda os
     * NOMES e deixa o conteúdo para trás quando o envio não passa pelo JavaScript. Nenhuma
     * das duas dá erro — por isso o teste as fixa.
     *
     * Na edição o campo continua SEM nome: lá o anexo vai por `fetch` próprio, e um campo
     * nomeado entraria em todo Salvar como um upload vazio.
     */
    public function test_so_o_formulario_de_criacao_carrega_arquivo(): void
    {
        $this->criarTarefa();

        $html = $this->actingAs(User::factory()->create())
            ->get(route('tarefas.index'))
            ->assertOk()
            ->getContent();

        // Uma vez cada: o modal de criação é único, e o de edição — que existe
        // um por card — não pode ter nenhum dos dois.
        $this->assertSame(1, substr_count($html, 'name="anexos[]"'));
        $this->assertSame(1, substr_count($html, 'enctype="multipart/form-data"'));
    }
}
