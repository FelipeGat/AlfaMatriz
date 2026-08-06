<?php

namespace Tests\Feature\FluxoDeploy;

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CopiaEmbaralhadaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-069 A preparação do staging troca nome, e-mail, telefone e CNPJ
     * dos clientes por dados falsos e aplica uma senha de teste — mas NÃO mexe
     * nos valores do financeiro, que são o motivo de o staging existir.
     */
    public function test_troca_dado_pessoal_e_preserva_os_valores(): void
    {
        $cliente = Cliente::create([
            'nome' => 'Padaria do João',
            'razao_social' => 'João Panificação LTDA',
            'cpf_cnpj' => '12.345.678/0001-99',
            'cidade' => 'Vitória',
            'uf' => 'ES',
            'ativo' => true,
        ]);

        // Contato mora em tabela própria desde a migração de agosto.
        DB::table('cliente_emails')->insert([
            'cliente_id' => $cliente->id, 'email' => 'joao@padariadojoao.com.br',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cliente_telefones')->insert([
            'cliente_id' => $cliente->id, 'telefone' => '(27) 99988-7766',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $usuario = User::factory()->create(['email' => 'pessoa.real@alfatecnologia.com.br']);

        // Um valor financeiro para provar que dinheiro não é tocado.
        $conta = DB::table('contas_financeiras')->insertGetId([
            'nome' => 'Caixa', 'saldo' => 12345.67, 'ativo' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('alfa:embaralhar-dados')->assertSuccessful();

        $depois = $cliente->fresh();

        // Nada de dado real sobrou — nem no cadastro, nem nos contatos.
        $tudo = json_encode([
            $depois->toArray(),
            DB::table('cliente_emails')->get()->toArray(),
            DB::table('cliente_telefones')->get()->toArray(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['Padaria do João', 'joao@padariadojoao.com.br', '12.345.678/0001-99', '(27) 99988-7766'] as $real) {
            $this->assertStringNotContainsString(
                $real,
                $tudo,
                "O dado real \"{$real}\" continua na base de staging."
            );
        }

        $this->assertStringContainsString(
            'exemplo.invalido',
            DB::table('cliente_emails')->where('cliente_id', $cliente->id)->value('email')
        );
        $this->assertNotSame('pessoa.real@alfatecnologia.com.br', $usuario->fresh()->email);

        // O dinheiro fica.
        $this->assertEquals(
            12345.67,
            DB::table('contas_financeiras')->where('id', $conta)->value('saldo'),
            'Os valores do financeiro não podem ser alterados: são o motivo do staging existir.'
        );

        // E o vínculo do cliente também: faturamento depende dele.
        $this->assertSame($cliente->id, $depois->id);
        $this->assertSame('Vitória', $depois->cidade, 'Cidade não é dado pessoal e serve para testar relatório.');
    }

    /**
     * @spec:AC-069 Em produção o comando se recusa a rodar: ele apaga dados
     * reais de clientes, e um engano de terminal custaria a base da empresa.
     */
    public function test_recusa_rodar_em_producao_sem_confirmacao(): void
    {
        $cliente = Cliente::create(['nome' => 'Padaria do João', 'ativo' => true]);

        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('alfa:embaralhar-dados')->assertFailed();

        $this->assertSame('Padaria do João', $cliente->fresh()->nome, 'Nada pode ter sido alterado.');
    }
}
