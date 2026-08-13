<?php

namespace Tests\Feature\Auditoria;

use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\ContaPagar;
use App\Models\Perfil;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A tela do rastro e a linha do tempo dentro de cada registro.
 *
 * A porta é estreita de propósito: a mesma tela mostra receita, cliente,
 * salário em despesa e mudança de permissão. Quem tem só um pedaço do sistema
 * não passa a ver o resto por causa dela.
 */
class TelaDeAuditoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        (new PerfilPermissaoSeeder)->run();
    }

    private function admin(): User
    {
        return User::factory()->create(['name' => 'Camila']);
    }

    private function comPerfil(string $slug): User
    {
        $usuario = User::factory()->semPerfil()->create();
        $usuario->perfis()->sync([Perfil::where('slug', $slug)->value('id')]);

        return $usuario;
    }

    public function test_a_tela_abre_para_quem_tem_a_permissao(): void
    {
        $this->actingAs($this->admin())
            ->get(route('auditoria.index'))
            ->assertOk()
            ->assertSee('Auditoria');
    }

    public function test_quem_nao_tem_a_permissao_toma_403(): void
    {
        // `operacao` alcança clientes, revendas e os painéis — e nem por isso
        // pode ler o que o financeiro fez com uma despesa.
        $this->actingAs($this->comPerfil('operacao'))
            ->get(route('auditoria.index'))
            ->assertForbidden();
    }

    public function test_a_revenda_nao_alcanca_a_tela_nem_com_perfil_administrativo(): void
    {
        $revenda = Revenda::create(['nome' => 'Revenda Sul']);

        $usuario = User::factory()->create(['revenda_id' => $revenda->id]);

        // O `revenda_id` limita o que ela enxerga em toda tela, e aqui não
        // haveria como aplicá-lo: a linha guarda o alvo como tipo + id, sem a
        // revenda dele. Entrar com um filtro que não filtra é pior que a porta
        // fechada.
        $this->actingAs($usuario)
            ->get(route('auditoria.index'))
            ->assertForbidden();
    }

    public function test_o_menu_nao_oferece_a_porta_a_quem_ela_nao_abre(): void
    {
        // Item que leva a 403 é pior que item ausente: quem clica conclui que o
        // sistema quebrou.
        $this->actingAs($this->comPerfil('operacao'))
            ->get(route('clientes.index'))
            ->assertOk()
            ->assertDontSee(route('auditoria.index'));

        $this->actingAs($this->admin())
            ->get(route('clientes.index'))
            ->assertOk()
            ->assertSee(route('auditoria.index'));
    }

    public function test_os_filtros_recortam_a_lista(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        Cliente::create(['nome' => 'Academia Corpo e Ação']);
        Cliente::create(['nome' => 'Studio Pilates Norte'])->delete();

        $porAcao = $this->get(route('auditoria.index', ['acao' => 'excluiu']));
        $porAcao->assertOk();
        $porAcao->assertSee('Studio Pilates Norte');
        $porAcao->assertDontSee('Academia Corpo e Ação');

        $porArea = $this->get(route('auditoria.index', ['recurso' => 'acesso']));
        $porArea->assertOk();
        $porArea->assertSee('Nenhum registro com esse recorte.');

        $porBusca = $this->get(route('auditoria.index', ['busca' => 'Corpo e Ação']));
        $porBusca->assertOk();
        $porBusca->assertSee('Academia Corpo e Ação');
        $porBusca->assertDontSee('Studio Pilates Norte');
    }

    public function test_o_recorte_por_data_deixa_de_fora_o_que_esta_fora_dele(): void
    {
        $this->actingAs($this->admin());

        Cliente::create(['nome' => 'Academia Corpo e Ação']);

        // A linha é de hoje; o recorte pede a semana passada.
        $resposta = $this->get(route('auditoria.index', [
            'de' => now()->subDays(9)->toDateString(),
            'ate' => now()->subDays(2)->toDateString(),
        ]));

        $resposta->assertOk();
        $resposta->assertDontSee('Academia Corpo e Ação');
    }

    public function test_a_linha_do_tempo_aparece_dentro_do_registro(): void
    {
        $this->actingAs($this->admin());

        $cliente = Cliente::create(['nome' => 'Academia Corpo e Ação', 'valor_mensal' => 800]);
        $cliente->update(['valor_mensal' => 400]);

        $resposta = $this->get(route('clientes.edit', $cliente))->assertOk();

        // A pergunta "quem mexeu nisto?" nasce olhando para o cliente. Obrigar
        // a sair daqui e reconstruir o filtro certo é o que faz uma tela de
        // auditoria existir e ninguém usar.
        $resposta->assertSee('Histórico');
        $resposta->assertSee('Alterou');
        $resposta->assertSee('valor mensal');
    }

    public function test_a_linha_do_tempo_nao_aparece_para_quem_nao_pode_ler_o_rastro(): void
    {
        $cliente = Cliente::create(['nome' => 'Academia Corpo e Ação', 'valor_mensal' => 800]);
        $cliente->update(['valor_mensal' => 400]);

        // O perfil `comercial` edita clientes — e o rastro mostra valores
        // anteriores que ele nunca teve acesso a ver.
        $resposta = $this->actingAs($this->comPerfil('comercial'))
            ->get(route('clientes.edit', $cliente))
            ->assertOk();

        $resposta->assertDontSee('Histórico');
    }

    public function test_a_linha_do_tempo_monta_nas_quatro_telas_que_a_recebem(): void
    {
        $this->actingAs($this->admin());

        $cobranca = Cobranca::create([
            'descricao' => 'Mensalidade agosto',
            'valor' => 500,
            'status' => 'pendente',
            'data_vencimento' => '2026-08-15',
        ]);

        $despesa = ContaPagar::create([
            'descricao' => 'Hospedagem',
            'valor' => 300,
            'status' => 'pendente',
            'data_vencimento' => '2026-08-20',
        ]);

        $sistema = Sistema::factory()->create();

        // O componente busca o próprio dado e é embutido em telas com
        // variáveis de nomes diferentes (`$cobranca`, `$contaPagar`,
        // `$sistema`). Um nome errado só apareceria ao abrir a tela — e três
        // destas quatro não eram renderizadas por teste nenhum.
        $this->get(route('cobrancas.show', $cobranca))->assertOk()->assertSee('Histórico');
        $this->get(route('contas-pagar.edit', $despesa))->assertOk()->assertSee('Histórico');
        $this->get(route('sistemas.edit', $sistema))->assertOk()->assertSee('Histórico');
    }

    public function test_a_ordem_nao_depende_do_acaso_quando_o_segundo_e_o_mesmo(): void
    {
        $this->actingAs($this->admin());

        // O fechamento do faturamento grava dezenas de linhas no MESMO segundo.
        // Sem o desempate por id, a ordem delas mudaria a cada carregamento — a
        // mesma linha aparecendo em duas páginas e outra em nenhuma.
        $instante = now();

        foreach (['Primeiro', 'Segundo', 'Terceiro'] as $nome) {
            Auditoria::create([
                'usuario_nome' => 'Camila',
                'recurso' => 'clientes',
                'acao' => 'criou',
                'descricao' => $nome,
                'created_at' => $instante,
            ]);
        }

        $ordem = Auditoria::where('recurso', 'clientes')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->pluck('descricao')->all();

        $this->assertSame(['Terceiro', 'Segundo', 'Primeiro'], $ordem);
    }
}
