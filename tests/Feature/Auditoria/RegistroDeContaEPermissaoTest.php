<?php

namespace Tests\Feature\Auditoria;

use App\Models\Auditoria;
use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O rastro do que muda o ALCANCE das pessoas.
 *
 * Nada aqui é pego pelo trait de auditoria, e é essa a razão de os testes
 * existirem: perfil de usuário e grade de permissão moram em tabelas de ligação
 * — `perfil_user` e `perfil_permissao` —, que não são modelos e não disparam
 * evento nenhum do Eloquent. Sem gravação explícita, as duas mudanças mais
 * consequentes do painel seriam as únicas sem rastro.
 */
class RegistroDeContaEPermissaoTest extends TestCase
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

    public function test_trocar_os_perfis_de_alguem_deixa_os_nomes_no_rastro(): void
    {
        $alvo = User::factory()->create(['name' => 'Beltrano', 'email' => 'beltrano@alfa.com.br']);
        $alvo->perfis()->sync([Perfil::where('slug', 'operacao')->value('id')]);

        $this->actingAs($this->admin())->put(route('usuarios.update', $alvo), [
            'name' => 'Beltrano',
            'email' => 'beltrano@alfa.com.br',
            'perfis' => [Perfil::where('slug', 'admin')->value('id')],
        ]);

        $linha = Auditoria::where('acao', 'permissoes')->sole();

        $this->assertSame('Camila', $linha->usuario_nome);
        $this->assertSame($alvo->id, $linha->auditavel_id);

        // Os NOMES, e não os ids: a linha tem de continuar legível anos depois,
        // e "de [3] para [1]" exigiria consultar uma tabela que pode ter
        // mudado no meio do caminho.
        $this->assertSame('Operação', $linha->alteracoes['perfis']['de']);
        $this->assertSame('Administrador', $linha->alteracoes['perfis']['para']);
    }

    public function test_salvar_a_conta_sem_mexer_nos_perfis_nao_registra_permissao(): void
    {
        $alvo = User::factory()->create(['name' => 'Beltrano', 'email' => 'beltrano@alfa.com.br']);
        $perfil = Perfil::where('slug', 'admin')->value('id');
        $alvo->perfis()->sync([$perfil]);

        // A mesma tela corrige um e-mail e troca um perfil. Registrar sempre
        // encheria a auditoria de mudanças de permissão que não mudaram
        // permissão nenhuma.
        $this->actingAs($this->admin())->put(route('usuarios.update', $alvo), [
            'name' => 'Beltrano',
            'email' => 'outro@alfa.com.br',
            'perfis' => [$perfil],
        ]);

        $this->assertSame(0, Auditoria::where('acao', 'permissoes')->count());

        // Mas o e-mail mudou, e isso o trait pega sozinho.
        $this->assertSame(1, Auditoria::where('recurso', 'usuarios')->where('acao', 'alterou')->count());
    }

    public function test_a_grade_do_perfil_registra_so_as_caixas_que_viraram(): void
    {
        $operacao = Perfil::where('slug', 'operacao')->first();

        // A grade tem quinze recursos por quatro ações. O envio abaixo reproduz
        // a grade atual e liga UMA caixa a mais — se a linha guardasse a grade
        // inteira, essa caixa ficaria enterrada entre cinquenta e nove que não
        // mudaram.
        $grade = [];

        foreach ($operacao->permissoes as $permissao) {
            foreach (['ler', 'incluir', 'editar', 'imprimir', 'excluir'] as $acao) {
                if ($permissao->pivot->{$acao}) {
                    $grade[$permissao->recurso][$acao] = '1';
                }
            }
        }

        $grade['clientes']['excluir'] = '1';

        $this->actingAs($this->admin())
            ->put(route('perfis.permissoes', $operacao), ['grade' => $grade]);

        $linha = Auditoria::where('acao', 'permissoes')->sole();

        $this->assertSame('perfil Operação', $linha->descricao);
        $this->assertSame(['clientes · excluir'], array_keys($linha->alteracoes));
        $this->assertSame('não', $linha->alteracoes['clientes · excluir']['de']);
        $this->assertSame('sim', $linha->alteracoes['clientes · excluir']['para']);
    }

    public function test_salvar_a_grade_sem_mexer_em_nada_nao_registra(): void
    {
        $operacao = Perfil::where('slug', 'operacao')->first();

        $grade = [];

        foreach ($operacao->permissoes as $permissao) {
            foreach (['ler', 'incluir', 'editar', 'imprimir', 'excluir'] as $acao) {
                if ($permissao->pivot->{$acao}) {
                    $grade[$permissao->recurso][$acao] = '1';
                }
            }
        }

        $this->actingAs($this->admin())
            ->put(route('perfis.permissoes', $operacao), ['grade' => $grade]);

        $this->assertSame(0, Auditoria::where('acao', 'permissoes')->count());
    }

    public function test_redefinir_senha_deixa_uma_linha_so_e_ela_diz_o_que_foi(): void
    {
        $alvo = User::factory()->create(['name' => 'Beltrano', 'email' => 'beltrano@alfa.com.br']);

        $this->actingAs($this->admin())->post(route('usuarios.senha', $alvo));

        // UMA linha, e não duas. O automático do trait diria "alterou usuário:
        // password, primeiro_acesso" — verdade, e não a informação que se
        // procura. O controller cala o automático e escreve a linha certa.
        $linhas = Auditoria::where('recurso', 'usuarios')
            ->where('auditavel_id', $alvo->id)
            ->whereIn('acao', ['senha', 'alterou'])
            ->get();

        $this->assertCount(1, $linhas);
        $this->assertSame('senha', $linhas->first()->acao);
        $this->assertSame('Camila', $linhas->first()->usuario_nome);

        // A senha gerada é mostrada uma vez na tela de quem a gerou e some.
        // Guardá-la aqui desfaria isso.
        $this->assertNull($linhas->first()->alteracoes);
    }

    public function test_a_permissao_de_auditoria_nasce_so_para_o_administrador(): void
    {
        $permissao = Permissao::where('recurso', 'auditoria')->first();

        $this->assertNotNull($permissao, 'O recurso `auditoria` precisa existir para a tela ter porta.');

        // Ampliar depois é marcar uma caixa; estreitar depois é tirar de alguém
        // algo que já estava vendo.
        $this->assertTrue(User::factory()->create()->canPermissao('auditoria', 'ler'));

        $operacao = User::factory()->semPerfil()->create();
        $operacao->perfis()->sync([Perfil::where('slug', 'operacao')->value('id')]);

        $this->assertFalse($operacao->canPermissao('auditoria', 'ler'));
    }
}
