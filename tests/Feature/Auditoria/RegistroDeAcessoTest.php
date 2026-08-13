<?php

namespace Tests\Feature\Auditoria;

use App\Models\Auditoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O rastro de quem entrou, saiu e tentou.
 *
 * O registro pende dos eventos do Laravel, e não do controller de login, porque
 * a entrada tem mais de uma porta — o formulário, o cookie de "lembrar de mim"
 * e o `Auth::login()` de um comando. Estes testes usam o formulário porque é a
 * porta que dá para bater; o que eles guardam é o registro, não a porta.
 */
class RegistroDeAcessoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function conta(): User
    {
        return User::factory()->create([
            'name' => 'Camila',
            'email' => 'camila@alfa.com.br',
        ]);
    }

    public function test_entrar_deixa_linha(): void
    {
        $this->conta();

        $this->post(route('login'), [
            'email' => 'camila@alfa.com.br',
            'password' => 'password',
        ]);

        $linha = Auditoria::where('recurso', 'acesso')->where('acao', 'entrou')->sole();

        $this->assertSame('Camila', $linha->usuario_nome);
        $this->assertSame('camila@alfa.com.br', $linha->descricao);
    }

    public function test_sair_deixa_linha_com_o_nome_de_quem_saiu(): void
    {
        $conta = $this->conta();

        $this->actingAs($conta)->post(route('logout'));

        $linha = Auditoria::where('recurso', 'acesso')->where('acao', 'saiu')->sole();

        // O nome vem do EVENTO, e não do `auth()`: quando o registro acontece,
        // o guard já desmontou a sessão. Lendo dali, esta linha sairia como
        // "Sistema" — sem dizer quem saiu, que é a única coisa que ela tem a
        // dizer.
        $this->assertSame('Camila', $linha->usuario_nome);
        $this->assertSame($conta->id, $linha->user_id);
    }

    public function test_senha_recusada_deixa_linha_e_a_senha_nao_entra_nela(): void
    {
        $this->conta();

        $this->post(route('login'), [
            'email' => 'camila@alfa.com.br',
            'password' => 'senha-de-outro-sistema',
        ]);

        $linha = Auditoria::where('recurso', 'acesso')->where('acao', 'recusado')->sole();

        // Senha errada aqui é quase sempre a senha CERTA de outro lugar. Ela
        // não entra em campo nenhum — nem no antes/depois, nem na descrição.
        $this->assertStringNotContainsString('senha-de-outro-sistema', json_encode($linha->toArray()));

        // O e-mail entra: é ele que distingue "erraram a senha do Camila" de
        // "estão varrendo endereços no escuro".
        $this->assertSame('camila@alfa.com.br', $linha->usuario_nome);
        $this->assertSame('conta existente', $linha->descricao);
    }

    public function test_tentativa_em_conta_inexistente_tambem_deixa_linha(): void
    {
        $this->post(route('login'), [
            'email' => 'ninguem@lugar-nenhum.com',
            'password' => 'chute',
        ]);

        $linha = Auditoria::where('recurso', 'acesso')->where('acao', 'recusado')->sole();

        // Sem conta por trás, `user_id` fica nulo e o e-mail digitado é toda a
        // identidade que existe — é exatamente por isso que `usuario_nome` é
        // coluna própria, e não algo que se busque na tabela de usuários.
        $this->assertNull($linha->user_id);
        $this->assertSame('ninguem@lugar-nenhum.com', $linha->usuario_nome);
        $this->assertSame('nenhuma conta com este e-mail', $linha->descricao);
    }

    public function test_o_acesso_nao_se_mistura_com_as_mudancas_de_conta(): void
    {
        $this->conta();

        $this->post(route('login'), [
            'email' => 'camila@alfa.com.br',
            'password' => 'password',
        ]);

        // Login é o evento mais frequente do sistema. Sob o recurso `usuarios`,
        // ele empurraria para fora da primeira página toda mudança de conta
        // que acontecesse no meio — que é o que se vai procurar ali.
        $this->assertSame(0, Auditoria::where('recurso', 'usuarios')->where('acao', 'entrou')->count());
        $this->assertSame(1, Auditoria::where('recurso', 'acesso')->count());
    }
}
