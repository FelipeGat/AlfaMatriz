<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaMensagem;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\ConversaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A conversa da Central de IA: quem entra, o que cada um vê e o envio que não
 * dobra.
 */
class ConversaTest extends TestCase
{
    use RefreshDatabase;

    private function comCentral(?User $user = null): User
    {
        $user ??= User::factory()->create();
        $user->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));

        return $user;
    }

    /**
     * O Rossini também é admin. Se o Administrador levasse a Central de
     * fábrica, a exclusividade da homologação acabaria no primeiro login dele.
     */
    public function test_administrador_sem_o_perfil_nao_entra_nem_ve_o_menu(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->get(route('central-ia.index'))->assertForbidden();
        $this->actingAs($admin)->getJson(route('central-ia.mensagens'))->assertForbidden();
        $this->actingAs($admin)->get(route('agenda.index'))->assertDontSee('Central de IA');
    }

    public function test_quem_tem_o_perfil_abre_a_tela_e_ve_o_menu(): void
    {
        $this->actingAs($this->comCentral())->get(route('central-ia.index'))
            ->assertOk()
            ->assertSee('Central de IA')
            ->assertSee('data-central-ia', false);
    }

    /**
     * O `conta-ativa` do grupo encerra a sessão e manda ao login — a mesma
     * régua de toda tela, sem porta própria da Central.
     */
    public function test_conta_desativada_nao_entra(): void
    {
        $user = $this->comCentral(User::factory()->desativado()->create());

        $this->actingAs($user)->getJson(route('central-ia.mensagens'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_envio_grava_mensagem_do_proprio_usuario_e_aparece_no_historico(): void
    {
        $user = $this->comCentral();
        $id = (string) Str::uuid();

        $this->actingAs($user)->postJson(route('central-ia.enviar'), ['conteudo' => 'Olá, Central', 'client_id' => $id])
            ->assertCreated();

        $this->actingAs($user)->getJson(route('central-ia.mensagens'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('mensagens.0.conteudo', 'Olá, Central')
            ->assertJsonPath('mensagens.0.autor_tipo', 'usuario')
            ->assertJsonPath('mensagens.0.origem', 'web')
            ->assertJsonPath('mensagens.0.client_id', $id)
            ->assertJsonMissingPath('mensagens.0.user_id');
    }

    /**
     * O navegador que perdeu a resposta reenvia com o mesmo id. A segunda
     * chamada devolve a primeira mensagem, e o banco continua com uma só.
     */
    public function test_reenvio_com_o_mesmo_id_nao_dobra_a_mensagem(): void
    {
        $user = $this->comCentral();
        $corpo = ['conteudo' => 'Analise o AlfaControl', 'client_id' => (string) Str::uuid()];

        $primeira = $this->actingAs($user)->postJson(route('central-ia.enviar'), $corpo)->assertCreated()->json('id');
        $segunda = $this->actingAs($user)->postJson(route('central-ia.enviar'), $corpo)->assertOk()->json('id');

        $this->assertSame($primeira, $segunda);
        $this->assertSame(1, CentralIaMensagem::count());
    }

    public function test_mesmo_id_com_outro_texto_e_recusado(): void
    {
        $user = $this->comCentral();
        $id = (string) Str::uuid();

        $this->actingAs($user)->postJson(route('central-ia.enviar'), ['conteudo' => 'Primeiro', 'client_id' => $id])->assertCreated();
        $this->actingAs($user)->postJson(route('central-ia.enviar'), ['conteudo' => 'Outro', 'client_id' => $id])
            ->assertUnprocessable();

        $this->assertSame(1, CentralIaMensagem::count());
    }

    /**
     * Sem exceção de administrador: dois admins com a Central, cada um vê só
     * a própria conversa — e o mesmo `client_id` em contas diferentes são
     * mensagens diferentes.
     */
    public function test_cada_conta_ve_so_a_propria_conversa(): void
    {
        $felipe = $this->comCentral();
        $rossini = $this->comCentral();
        $id = (string) Str::uuid();

        $this->actingAs($felipe)->postJson(route('central-ia.enviar'), ['conteudo' => 'Do Felipe', 'client_id' => $id])->assertCreated();
        $this->actingAs($rossini)->postJson(route('central-ia.enviar'), ['conteudo' => 'Do Rossini', 'client_id' => $id])->assertCreated();

        $this->actingAs($rossini)->getJson(route('central-ia.mensagens'))
            ->assertJsonCount(1, 'mensagens')
            ->assertJsonPath('mensagens.0.conteudo', 'Do Rossini');
        $this->actingAs($rossini)->getJson(route('central-ia.mensagens', ['depois' => 0]))
            ->assertJsonCount(1, 'mensagens');
    }

    public function test_paginacao_para_tras_e_poll_para_frente(): void
    {
        $user = $this->comCentral();
        $servico = app(ConversaService::class);
        $total = ConversaService::POR_PAGINA + 5;

        foreach (range(1, $total) as $n) {
            $servico->registrarDoUsuario($user, "m{$n}", CentralIaMensagem::ORIGEM_WEB);
        }

        $ultimas = $this->actingAs($user)->getJson(route('central-ia.mensagens'))
            ->assertJsonPath('ha_mais', true)
            ->assertJsonCount(ConversaService::POR_PAGINA, 'mensagens')
            ->json('mensagens');
        $this->assertSame("m{$total}", end($ultimas)['conteudo']);

        $this->actingAs($user)->getJson(route('central-ia.mensagens', ['antes' => $ultimas[0]['id']]))
            ->assertJsonPath('ha_mais', false)
            ->assertJsonCount(5, 'mensagens')
            ->assertJsonPath('mensagens.0.conteudo', 'm1');

        $this->actingAs($user)->getJson(route('central-ia.mensagens', ['depois' => end($ultimas)['id']]))
            ->assertJsonCount(0, 'mensagens')
            ->assertJsonPath('ha_mais', false);
    }

    public function test_validacao_do_envio_e_da_leitura(): void
    {
        $user = $this->comCentral();

        $this->actingAs($user)->postJson(route('central-ia.enviar'), ['conteudo' => '   ', 'client_id' => (string) Str::uuid()])
            ->assertUnprocessable();
        $this->actingAs($user)->postJson(route('central-ia.enviar'), ['conteudo' => 'oi', 'client_id' => 'nao-e-uuid'])
            ->assertUnprocessable();
        $this->actingAs($user)->postJson(route('central-ia.enviar'), ['conteudo' => str_repeat('a', 3001), 'client_id' => (string) Str::uuid()])
            ->assertUnprocessable();
        $this->actingAs($user)->getJson(route('central-ia.mensagens', ['antes' => 5, 'depois' => 1]))
            ->assertUnprocessable();

        $this->assertSame(0, CentralIaMensagem::count());
    }

    /**
     * Quem só lê não escreve. O perfil de fábrica dá os dois; aqui a grade é
     * ajustada para só `ler`, como a tela de perfis permite.
     */
    public function test_permissao_so_de_leitura_nao_envia(): void
    {
        $user = User::factory()->semPerfil()->create();
        $perfil = Perfil::create(['nome' => 'Leitor da Central', 'slug' => 'leitor-central']);
        $perfil->permissoes()->attach(\App\Models\Permissao::where('recurso', 'central_ia')->value('id'), ['ler' => true]);
        $user->perfis()->attach($perfil->id);

        $this->actingAs($user)->getJson(route('central-ia.mensagens'))->assertOk();
        $this->actingAs($user)->postJson(route('central-ia.enviar'), ['conteudo' => 'oi', 'client_id' => (string) Str::uuid()])
            ->assertForbidden();
    }
}
