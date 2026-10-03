<?php

namespace Tests\Feature\Vigia;

use App\Models\Perfil;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use App\Models\VigiaErro;
use App\Models\VigiaErroHora;
use App\Models\VigiaIgnorado;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A aba Erros da tela de Manutenção (#224): o que o vigia de logs agrupou,
 * por sistema, e o botão que faz pela tela o mesmo do `alfa:vigia-ignorar`.
 */
class TelaDeErrosTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $gym;

    private Sistema $matriz;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-03 15:30:00'));

        $this->gym = Sistema::factory()->create(['nome' => 'AlfaGym', 'slug' => 'alfagym']);
        $this->matriz = Sistema::factory()->create(['nome' => 'AlfaMatriz', 'slug' => 'alfamatriz']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function erro(Sistema $sistema, array $extra = []): VigiaErro
    {
        static $n = 0;
        $n++;

        return VigiaErro::create(array_merge([
            'sistema_id' => $sistema->id,
            'ambiente' => 'producao',
            'assinatura' => hash('sha256', 'erro-'.$n),
            'padrao' => 'Acesso negado ao recurso {n}',
            'nivel' => 'ERROR',
            'excecao' => 'org.springframework.security.access.AccessDeniedException',
            'mensagem' => 'Acesso negado ao recurso 42',
            'primeira_vez' => '2026-09-28 08:00:00',
            'ultima_vez' => '2026-10-03 14:10:00',
            'total' => 1234,
        ], $extra));
    }

    public function test_lista_por_sistema_com_contagem_datas_tarefa_e_hora_mais_cheia(): void
    {
        $tarefa = Tarefa::factory()->create(['status' => 'em_desenvolvimento', 'sistema_id' => $this->gym->id]);
        $erro = $this->erro($this->gym, ['tarefa_id' => $tarefa->id, 'pico_avisado_em' => '2026-10-02 11:05:00']);
        $this->erro($this->matriz, ['padrao' => 'Undefined index {n}', 'mensagem' => 'Undefined index 7', 'excecao' => null, 'total' => 3]);

        VigiaErroHora::create(['vigia_erro_id' => $erro->id, 'hora' => '2026-10-02 11:00:00', 'total' => 400]);
        VigiaErroHora::create(['vigia_erro_id' => $erro->id, 'hora' => '2026-10-03 09:00:00', 'total' => 12]);
        // Fora da semana: não entra na conta.
        VigiaErroHora::create(['vigia_erro_id' => $erro->id, 'hora' => '2026-09-20 09:00:00', 'total' => 999]);

        $resposta = $this->actingAs(User::factory()->create())->get(route('manutencao.index'));

        $resposta->assertOk()
            ->assertSeeInOrder(['AlfaGym', 'Acesso negado ao recurso 42', 'AlfaMatriz', 'Undefined index 7'])
            ->assertSee('1.234')
            ->assertSee('412 na semana')
            ->assertSee('28/09/2026 08:00')
            ->assertSee('03/10/2026 14:10')
            ->assertSee('hora mais cheia: 400 às 02/10 11h')
            ->assertSee('AccessDeniedException')
            ->assertSee($tarefa->codigo())
            ->assertSee('Em andamento')
            ->assertSee('pico');
    }

    public function test_o_ignorado_sai_da_lista_padrao_e_aparece_no_filtro(): void
    {
        $this->erro($this->gym, ['ignorado' => true, 'mensagem' => 'Broken pipe ao escrever resposta']);

        $this->actingAs(User::factory()->create());

        $this->get(route('manutencao.index'))->assertDontSee('Broken pipe ao escrever resposta');
        $this->get(route('manutencao.index', ['situacao' => 'ignorados']))->assertSee('Broken pipe ao escrever resposta');
    }

    public function test_ignorar_pela_linha_cala_o_erro_na_hora_e_so_no_sistema_dele(): void
    {
        $doGym = $this->erro($this->gym);
        $doMatriz = $this->erro($this->matriz);

        $this->actingAs(User::factory()->create())
            ->post(route('manutencao.erros.ignorar', $doGym))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('vigia_ignorados', ['padrao' => 'Acesso negado ao recurso {n}', 'sistema_id' => $this->gym->id]);
        $this->assertTrue($doGym->fresh()->ignorado, 'A marca acerta na hora, sem esperar o próximo lote.');
        $this->assertFalse($doMatriz->fresh()->ignorado, 'O mesmo padrão em outro sistema continua vigiado.');

        // Quem calou fica no rastro de auditoria.
        $this->assertDatabaseHas('auditorias', ['recurso' => 'manutencao', 'acao' => 'criou']);

        // Ignorar de novo não duplica a regra.
        $this->post(route('manutencao.erros.ignorar', $doGym));
        $this->assertSame(1, VigiaIgnorado::count());
    }

    public function test_padrao_com_cara_de_regex_entra_escapado(): void
    {
        $erro = $this->erro($this->gym, ['padrao' => '/var/log/app/', 'mensagem' => 'Sem espaço em /var/log/app/']);

        $this->actingAs(User::factory()->create())->post(route('manutencao.erros.ignorar', $erro));

        $regra = VigiaIgnorado::sole();
        $this->assertSame('/\/var\/log\/app\//', $regra->padrao);
        $this->assertTrue($erro->fresh()->ignorado);
    }

    public function test_o_formulario_ignora_em_todos_os_sistemas_e_recusa_regex_invalida(): void
    {
        $doGym = $this->erro($this->gym);
        $doMatriz = $this->erro($this->matriz);

        $this->actingAs(User::factory()->create());

        $this->post(route('manutencao.ignorados.store'), ['padrao' => '/(sem fechar/'])
            ->assertSessionHasErrors(['padrao' => 'Expressão regular inválida: /(sem fechar/']);
        $this->assertSame(0, VigiaIgnorado::count());

        $this->post(route('manutencao.ignorados.store'), ['padrao' => 'acesso negado'])->assertSessionHas('status');

        $this->assertDatabaseHas('vigia_ignorados', ['padrao' => 'acesso negado', 'sistema_id' => null]);
        $this->assertTrue($doGym->fresh()->ignorado);
        $this->assertTrue($doMatriz->fresh()->ignorado);

        $this->get(route('manutencao.index'))->assertSee('acesso negado')->assertSee('Todos');
    }

    public function test_voltar_a_vigiar_tira_a_regra_e_desfaz_a_marca(): void
    {
        $erro = $this->erro($this->gym);
        $this->actingAs(User::factory()->create())->post(route('manutencao.erros.ignorar', $erro));
        $regra = VigiaIgnorado::sole();

        $this->delete(route('manutencao.ignorados.destroy', $regra))->assertSessionHas('status');

        $this->assertSame(0, VigiaIgnorado::count());
        $this->assertFalse($erro->fresh()->ignorado);
    }

    public function test_o_comando_tambem_acerta_a_marca_na_hora(): void
    {
        $erro = $this->erro($this->gym);

        $this->artisan('alfa:vigia-ignorar', ['padrao' => 'Acesso negado', '--sistema' => 'alfagym'])->assertSuccessful();
        $this->assertTrue($erro->fresh()->ignorado);

        $this->artisan('alfa:vigia-ignorar', ['padrao' => 'Acesso negado', '--sistema' => 'alfagym', '--remover' => true])->assertSuccessful();
        $this->assertFalse($erro->fresh()->ignorado);
    }

    public function test_membro_do_time_ve_e_calibra(): void
    {
        $erro = $this->erro($this->gym);
        $this->actingAs(User::factory()->membro()->create());

        $this->get(route('manutencao.index'))->assertOk()->assertSee('Ignorar este erro');
        $this->post(route('manutencao.erros.ignorar', $erro))->assertRedirect();
        $this->assertTrue($erro->fresh()->ignorado);
    }

    /**
     * O monitor da parede lê o quadro e não lê o log dos sistemas — a mesma
     * régua da Agenda.
     */
    public function test_perfil_de_exibicao_e_revenda_nao_entram(): void
    {
        $erro = $this->erro($this->gym);
        (new PerfilPermissaoSeeder)->run();

        $exibicao = User::factory()->semPerfil()->create();
        $exibicao->perfis()->attach(Perfil::where('slug', 'exibicao')->value('id'));

        $this->actingAs($exibicao)->get(route('manutencao.index'))->assertForbidden();
        $this->actingAs($exibicao)->post(route('manutencao.erros.ignorar', $erro))->assertForbidden();

        $revenda = Revenda::create(['nome' => 'Alpha Rev', 'ativo' => true]);
        $this->actingAs(User::factory()->create(['revenda_id' => $revenda->id]))
            ->get(route('manutencao.index'))->assertForbidden();

        $this->assertFalse($erro->fresh()->ignorado);
    }

    public function test_sistema_com_vigia_ligado_e_sem_erro_aparece_vazio(): void
    {
        $this->gym->forceFill(['vigia_token_hash' => hash('sha256', 'x')])->save();

        $this->actingAs(User::factory()->create())
            ->get(route('manutencao.index'))
            ->assertSee('AlfaGym')
            ->assertSee('o vigia está ligado e não trouxe nada')
            ->assertDontSee('AlfaMatriz</h2>', false);
    }
}
