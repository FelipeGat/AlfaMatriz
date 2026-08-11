<?php

namespace Tests\Feature\Deploy;

use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A carga de referência do deploy.
 *
 * Produção e staging rodaram meses no mesmo commit com telas diferentes:
 * `faturamento`, `leads` e `tarefas` existiam no código e não no cadastro de
 * permissões de produção, então sumiam do menu e devolviam 403. A causa era o
 * deploy aplicar só `migrate` — permissão é dado semeado, não migrado.
 *
 * Este comando fecha o buraco, e por rodar a cada publicação precisa provar
 * duas coisas: que traz o que falta, e que não estraga o que já está lá.
 */
class SemearReferenciaTest extends TestCase
{
    use RefreshDatabase;

    public function test_traz_o_recurso_que_faltava_no_ambiente(): void
    {
        Permissao::where('recurso', 'faturamento')->delete();
        $this->assertSame(0, Permissao::where('recurso', 'faturamento')->count());

        $this->artisan('alfa:semear-referencia')->assertSuccessful();

        $this->assertSame(1, Permissao::where('recurso', 'faturamento')->count());
    }

    public function test_o_perfil_admin_alcanca_todos_os_recursos_depois_da_carga(): void
    {
        $this->artisan('alfa:semear-referencia')->assertSuccessful();

        $admin = Perfil::where('slug', 'admin')->sole();

        $this->assertSame(
            Permissao::count(),
            $admin->permissoes()->count(),
            'Recurso novo sem permissão no admin é tela invisível para quem administra o sistema.'
        );
    }

    public function test_rodar_de_novo_nao_duplica_nem_multiplica_vinculos(): void
    {
        $this->artisan('alfa:semear-referencia')->assertSuccessful();
        $permissoes = Permissao::count();
        $vinculos = Perfil::where('slug', 'admin')->sole()->permissoes()->count();

        $this->artisan('alfa:semear-referencia')->assertSuccessful();

        $this->assertSame($permissoes, Permissao::count());
        $this->assertSame($vinculos, Perfil::where('slug', 'admin')->sole()->permissoes()->count());
    }

    /**
     * A carga é a FONTE DA VERDADE do que cada perfil padrão faz — ela não
     * preserva divergência, reafirma o declarado.
     *
     * Isso é seguro hoje porque nada além do próprio seeder escreve em
     * `perfil_permissao`: não há tela nem controller que edite permissão de
     * perfil. No dia em que existir, esta etapa do deploy precisa ser revista,
     * porque toda publicação vai desfazer o que a tela salvou. É a razão deste
     * teste existir com este nome.
     */
    public function test_a_carga_reafirma_o_que_declara_e_desfaz_divergencia_no_pivo(): void
    {
        $this->artisan('alfa:semear-referencia')->assertSuccessful();

        $operacao = Perfil::where('slug', 'operacao')->sole();
        $contasPagar = Permissao::where('recurso', 'contas_pagar')->sole();
        $operacao->permissoes()->syncWithoutDetaching([$contasPagar->id => ['excluir' => true]]);

        $this->artisan('alfa:semear-referencia')->assertSuccessful();

        $this->assertFalse(
            (bool) $operacao->permissoes()->where('recurso', 'contas_pagar')->sole()->pivot->excluir,
            'O seeder declara `excluir => false` para operação; a carga precisa restabelecer isso.'
        );
    }

    public function test_nao_cria_conta_de_administrador(): void
    {
        // O DadosIniciaisSeeder fica fora de propósito: ele recria o admin a
        // partir de ADMIN_EMAIL, e uma variável desatualizada ressuscitaria o
        // acesso antigo a cada publicação.
        $antes = User::count();

        $this->artisan('alfa:semear-referencia')->assertSuccessful();

        $this->assertSame($antes, User::count());
    }

    public function test_nao_reescreve_preco_de_sistema_editado_pela_tela(): void
    {
        $sistema = Sistema::create([
            'nome' => 'AlfaGym', 'slug' => 'alfagym', 'categoria' => 'saas',
            'unidade_cobranca' => 'academia ativa', 'ativo' => true,
        ]);

        $this->artisan('alfa:semear-referencia')->assertSuccessful();

        $this->assertSame(
            1,
            Sistema::where('slug', 'alfagym')->count(),
            'Carga de preço não entra no deploy: reaplicá-la desfaria o reajuste feito na tela.'
        );
        $this->assertTrue($sistema->fresh()->exists);
    }

    public function test_dry_run_nao_toca_no_banco(): void
    {
        Permissao::where('recurso', 'leads')->delete();

        $this->artisan('alfa:semear-referencia', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Permissao::where('recurso', 'leads')->count());
    }
}
