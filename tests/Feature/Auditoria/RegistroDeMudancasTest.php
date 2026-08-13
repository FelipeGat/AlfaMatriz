<?php

namespace Tests\Feature\Auditoria;

use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O rastro automático das mudanças de dado.
 *
 * O que estes testes guardam é a promessa que o trait faz: nenhum caminho de
 * escrita precisa lembrar de registrar. Por isso quase todos escrevem pelo
 * MODELO, e não pela tela — se o registro dependesse do controller, o mesmo
 * cliente criado pelo sincronizador do AlfaGym passaria em branco.
 */
class RegistroDeMudancasTest extends TestCase
{
    use RefreshDatabase;

    private function ator(): User
    {
        // A conta em si já nasce deixando rastro (`criou usuário`). As
        // consultas abaixo filtram por recurso justamente por isso — contar
        // "todas as linhas" faria o teste medir a preparação, e não o que ele
        // veio verificar.
        return User::factory()->create(['name' => 'Camila']);
    }

    public function test_criar_registra_quem_criou_e_o_que_nasceu(): void
    {
        $this->actingAs($this->ator());

        $cliente = Cliente::create(['nome' => 'Academia Corpo e Ação', 'valor_mensal' => 800]);

        $linha = Auditoria::where('recurso', 'clientes')->sole();

        $this->assertSame('criou', $linha->acao);
        $this->assertSame('Camila', $linha->usuario_nome);
        $this->assertSame('Academia Corpo e Ação', $linha->descricao);
        $this->assertSame($cliente->id, $linha->auditavel_id);

        // Na criação não há "de": o campo não existia antes.
        $this->assertNull($linha->alteracoes['nome']['de']);
        $this->assertSame('Academia Corpo e Ação', $linha->alteracoes['nome']['para']);
    }

    public function test_alterar_registra_o_valor_antigo_e_o_novo(): void
    {
        $this->actingAs($this->ator());

        $cliente = Cliente::create(['nome' => 'Academia Corpo e Ação', 'valor_mensal' => 800]);
        $cliente->update(['valor_mensal' => 400]);

        $linha = Auditoria::where('recurso', 'clientes')->where('acao', 'alterou')->sole();

        // O valor ANTIGO é a razão de a tabela existir: ele não sobrevive em
        // nenhum outro lugar depois do `update`.
        //
        // `assertEquals` e não `assertSame`: o que se afirma é o VALOR, e o
        // tipo com que ele volta do JSON depende de o registro ter sido lido do
        // banco ou ainda estar em memória — prender o teste a isso o faria
        // quebrar sem nada ter quebrado.
        $this->assertEquals(800, $linha->alteracoes['valor_mensal']['de']);
        $this->assertEquals(400, $linha->alteracoes['valor_mensal']['para']);

        // Só o que mudou. O nome continuou o mesmo e não tem por que aparecer.
        $this->assertArrayNotHasKey('nome', $linha->alteracoes);
    }

    public function test_salvar_sem_mudar_nada_nao_registra(): void
    {
        $this->actingAs($this->ator());

        $cliente = Cliente::create(['nome' => 'Academia Corpo e Ação']);

        // Reenviar o formulário sem editar é o caso mais comum da tela de
        // edição. Sem esta saída, a auditoria se encheria de "Alterou" sem
        // nada alterado — e o ruído é o que faz uma tela dessas parar de ser
        // lida.
        $cliente->update(['nome' => 'Academia Corpo e Ação']);

        $this->assertSame(0, Auditoria::where('recurso', 'clientes')->where('acao', 'alterou')->count());
    }

    public function test_excluir_guarda_a_ultima_copia_do_que_havia(): void
    {
        $this->actingAs($this->ator());

        $cliente = Cliente::create(['nome' => 'Academia Corpo e Ação', 'valor_mensal' => 800]);
        $cliente->delete();

        $linha = Auditoria::where('recurso', 'clientes')->where('acao', 'excluiu')->sole();

        // O registro não existe mais para ser consultado: a linha é a última
        // testemunha do que foi perdido, e por isso guarda o cadastro inteiro
        // do lado do "de".
        $this->assertSame('Academia Corpo e Ação', $linha->alteracoes['nome']['de']);
        $this->assertEquals(800, $linha->alteracoes['valor_mensal']['de']);
        $this->assertNull($linha->alteracoes['nome']['para']);

        // E continua apontando para o registro que se foi — é assim que a
        // linha do tempo dele ainda se monta depois da exclusão.
        $this->assertSame($cliente->id, $linha->auditavel_id);
    }

    public function test_a_senha_nunca_entra_no_rastro(): void
    {
        $ator = $this->ator();
        $this->actingAs($ator);

        $alvo = User::factory()->create();
        $alvo->update(['password' => 'uma-senha-que-nao-pode-vazar']);

        $linha = Auditoria::where('recurso', 'usuarios')->where('acao', 'alterou')->sole();

        // A marca de que mudou, e não o valor: nem o texto puro, nem o hash.
        // Hash guardado numa tabela que ninguém apaga é material de quebra
        // offline envelhecendo em paz.
        $this->assertSame(Auditoria::OCULTO, $linha->alteracoes['password']['para']);
        $this->assertStringNotContainsString('uma-senha-que-nao-pode-vazar', json_encode($linha->alteracoes));
        $this->assertStringNotContainsString($alvo->fresh()->password, json_encode($linha->alteracoes));
    }

    public function test_carimbo_de_tempo_nao_conta_como_mudanca(): void
    {
        $this->actingAs($this->ator());

        $cobranca = Cobranca::create([
            'descricao' => 'Mensalidade agosto',
            'valor' => 500,
            'status' => 'pendente',
            'data_vencimento' => '2026-08-15',
        ]);
        $cobranca->update(['status' => 'pago']);

        $linha = Auditoria::where('recurso', 'cobrancas')->where('acao', 'alterou')->sole();

        // `updated_at` muda em TODO salvamento. Registrá-lo faria cada linha
        // carregar uma diferença que não interessa a ninguém, e no formato mais
        // ilegível possível.
        $this->assertSame(['status'], array_keys($linha->alteracoes));
    }

    public function test_a_semeadura_nao_deixa_rastro(): void
    {
        // Semear não é alguém fazendo alguma coisa: é o banco nascendo. Um
        // ambiente recém-montado abriria a tela de auditoria com centenas de
        // linhas de "Sistema criou" e nada mais.
        Auditoria::semRegistro(fn () => Cliente::create(['nome' => 'Semeado']));

        $this->assertSame(0, Auditoria::where('recurso', 'clientes')->count());

        // E a mordaça é temporária: o que vem depois volta a ser registrado.
        Cliente::create(['nome' => 'Depois da semeadura']);

        $this->assertSame(1, Auditoria::where('recurso', 'clientes')->count());
    }

    public function test_a_linha_gravada_nao_se_altera(): void
    {
        $this->actingAs($this->ator());

        Cliente::create(['nome' => 'Academia Corpo e Ação']);

        $linha = Auditoria::where('recurso', 'clientes')->sole();

        // A recusa é uma exceção, e não um `return false` silencioso: quem
        // tentasse corrigir veria o `save()` voltar sem erro e concluiria que
        // corrigiu.
        $this->expectException(\LogicException::class);

        $linha->update(['descricao' => 'outra coisa']);
    }
}
