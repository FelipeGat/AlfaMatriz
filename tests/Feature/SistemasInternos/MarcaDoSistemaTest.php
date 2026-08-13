<?php

namespace Tests\Feature\SistemasInternos;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A marca de um sistema, enviada pela tela.
 *
 * O ícone já era achado por convenção — `public/marcas/<slug>` —, e isso
 * resolvia as seis marcas da casa, que viajam no repositório. Não resolvia
 * NENHUM sistema cadastrado pela tela: dar ícone a ele exigia acesso ao
 * servidor, e o card de tarefa dele ficava nas iniciais para sempre.
 *
 * O destino é o disco `public`, e não a pasta da aplicação. Em produção a
 * publicação é azul/verde: a pasta troca a cada versão, e a marca gravada lá
 * dentro sumiria no deploy seguinte sem erro nenhum — só um ícone que um dia
 * deixa de aparecer.
 *
 * O QUE ESTES TESTES NÃO PROVAM, para ninguém concluir que provaram: a regra
 * `image` do controller confere o CONTEÚDO do arquivo, e é ela que barraria um
 * script renomeado para `.png`. `UploadedFile::fake()` declara o mime em vez de
 * derivá-lo do conteúdo, então um dublê rotulado `image/png` passa na regra por
 * definição, sem exercitar a checagem. O que fica guardado aqui é a lista de
 * tipos aceitos — a metade testável. A outra exigiria um arquivo de verdade num
 * navegador de verdade, e por isso não está sendo alegada.
 */
class MarcaDoSistemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('public');
    }

    /** @return array<string, mixed> */
    private function campos(array $extra = []): array
    {
        return array_merge([
            'nome' => 'Painel de suporte',
            'natureza' => 'interno',
            'ativo' => '1',
        ], $extra);
    }

    public function test_a_marca_enviada_no_cadastro_vai_para_o_disco_compartilhado(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('sistemas.store'), $this->campos([
                'marca' => UploadedFile::fake()->image('logo.png', 64, 64),
            ]))
            ->assertRedirect();

        $sistema = Sistema::sole();

        // Nomeada pelo SLUG: é assim que o componente a acha, sem coluna no
        // banco e pelo mesmo caminho das marcas que vêm no repositório.
        Storage::disk('public')->assertExists("marcas/{$sistema->slug}.png");
    }

    public function test_a_marca_aparece_no_card_da_tarefa(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->post(route('sistemas.store'), $this->campos([
            'marca' => UploadedFile::fake()->image('logo.png', 64, 64),
        ]));

        $sistema = Sistema::sole();

        Tarefa::factory()->create([
            'criado_por_id' => $usuario->id,
            'status' => 'backlog',
            'sistema_id' => $sistema->id,
            'titulo' => 'Tarefa do sistema interno',
        ]);

        // O ponto inteiro da funcionalidade: sem isto o card do sistema interno
        // fica nas iniciais para sempre, porque não havia como pôr o arquivo no
        // lugar sem acesso ao servidor.
        $this->actingAs($usuario)->get(route('tarefas.index'))
            ->assertOk()
            ->assertSee("marcas/{$sistema->slug}.png", escape: false);
    }

    public function test_trocar_a_marca_nao_deixa_a_anterior_para_tras(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->post(route('sistemas.store'), $this->campos([
            'marca' => UploadedFile::fake()->image('logo.png', 64, 64),
        ]));

        $sistema = Sistema::sole();

        $this->actingAs($usuario)->put(route('sistemas.update', $sistema), $this->campos([
            'marca' => UploadedFile::fake()->image('logo.webp', 64, 64),
        ]));

        // As duas no disco fariam a ORDEM DE BUSCA do componente decidir qual
        // aparece — o sistema mostraria a marca velha e ninguém saberia por quê.
        Storage::disk('public')->assertExists("marcas/{$sistema->slug}.webp");
        Storage::disk('public')->assertMissing("marcas/{$sistema->slug}.png");
    }

    public function test_da_para_remover_a_marca(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->post(route('sistemas.store'), $this->campos([
            'marca' => UploadedFile::fake()->image('logo.png', 64, 64),
        ]));

        $sistema = Sistema::sole();

        $this->actingAs($usuario)->put(route('sistemas.update', $sistema), $this->campos([
            'remover_marca' => '1',
        ]));

        Storage::disk('public')->assertMissing("marcas/{$sistema->slug}.png");
    }

    public function test_salvar_sem_mexer_na_marca_nao_apaga_a_que_havia(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->post(route('sistemas.store'), $this->campos([
            'marca' => UploadedFile::fake()->image('logo.png', 64, 64),
        ]));

        $sistema = Sistema::sole();

        // A tela é a mesma para corrigir a versão. Se o campo vazio apagasse a
        // marca, ela sumiria no primeiro salvamento que não a reenviasse — e o
        // formulário não tem como reenviar um arquivo que o navegador não
        // guarda.
        $this->actingAs($usuario)->put(route('sistemas.update', $sistema), $this->campos([
            'versao' => 'v2',
        ]));

        Storage::disk('public')->assertExists("marcas/{$sistema->slug}.png");
    }

    public function test_svg_e_recusado(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('sistemas.store'), $this->campos([
                'marca' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
            ]))
            ->assertSessionHasErrors('marca');

        $this->assertSame(0, Sistema::count());
    }

    public function test_o_que_nao_e_imagem_e_recusado(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('sistemas.store'), $this->campos([
                'marca' => UploadedFile::fake()->create('planilha.pdf', 4, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('marca');

        $this->assertSame(0, Sistema::count());
    }

    public function test_a_marca_do_repositorio_continua_valendo_para_quem_nao_enviou(): void
    {
        $usuario = User::factory()->create();

        $gym = Sistema::factory()->alfagym()->create();

        Tarefa::factory()->create([
            'criado_por_id' => $usuario->id,
            'status' => 'backlog',
            'sistema_id' => $gym->id,
            'titulo' => 'Tarefa do AlfaGym',
        ]);

        // Nada foi enviado para o AlfaGym: ele continua na marca que veio no
        // repositório. A busca no disco compartilhado não pode ter atropelado
        // o caminho antigo.
        $this->actingAs($usuario)->get(route('tarefas.index'))
            ->assertOk()
            ->assertSee('/marcas/alfagym.svg', escape: false);
    }
}
