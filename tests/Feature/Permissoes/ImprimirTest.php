<?php

namespace Tests\Feature\Permissoes;

use App\Models\Cobranca;
use App\Models\CobrancaAnexo;
use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\Revenda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A ação `imprimir` passa a recusar alguma coisa.
 *
 * Ela existia na grade desde o começo e nenhuma rota a consultava: o
 * administrador marcava e desmarcava a caixa e nada mudava. Uma permissão que
 * não recusa nada é pior que uma ausente — ela faz a tela de perfis afirmar um
 * controle que não existe.
 *
 * O que ela guarda é o que TIRA dado de dentro do sistema: a planilha do
 * faturamento e o anexo financeiro (boleto, nota fiscal, comprovante). Ver a
 * tela continua sendo `ler`.
 */
class ImprimirTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Alguém que enxerga as duas telas e não leva nada embora.
     */
    private function quemSoOlha(): User
    {
        $perfil = Perfil::create(['slug' => 'so-olha', 'nome' => 'Só olha']);

        foreach (['faturamento', 'cobrancas'] as $recurso) {
            $permissao = Permissao::firstOrCreate(['recurso' => $recurso], ['descricao' => $recurso]);

            $perfil->permissoes()->syncWithoutDetaching([
                $permissao->id => [
                    'ler' => true,
                    'incluir' => false,
                    'editar' => false,
                    'imprimir' => false,
                    'excluir' => false,
                ],
            ]);
        }

        $usuario = User::factory()->semPerfil()->create();
        $usuario->perfis()->attach($perfil->id);

        return $usuario;
    }

    private function anexoDeCobranca(): CobrancaAnexo
    {
        Storage::fake('public');

        $revenda = Revenda::create(['nome' => 'Revenda de teste', 'ativo' => true]);

        $cobranca = Cobranca::create([
            'descricao' => 'Mensalidade',
            'valor' => 100.00,
            'revenda_id' => $revenda->id,
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'status' => 'pendente',
            'tipo' => 'direta',
        ]);

        $caminho = UploadedFile::fake()->create('boleto.pdf', 10, 'application/pdf')
            ->storeAs('anexos/cobrancas', 'boleto.pdf', 'public');

        return CobrancaAnexo::create([
            'cobranca_id' => $cobranca->id,
            'tipo' => 'boleto',
            'caminho' => $caminho,
            'nome_arquivo' => 'boleto.pdf',
            'nome_original' => 'boleto.pdf',
            'tamanho' => 10240,
        ]);
    }

    /**
     * @spec:AC-272 Sem `imprimir`, a exportação do faturamento é recusada — e a
     * tela em si continua abrindo.
     */
    public function test_sem_imprimir_a_exportacao_do_faturamento_e_recusada(): void
    {
        $usuario = $this->quemSoOlha();

        $this->actingAs($usuario)->get(route('faturamento.index'))->assertOk();

        $this->actingAs($usuario)
            ->get(route('faturamento.index', ['exportar' => 'csv']))
            ->assertForbidden();
    }

    /**
     * @spec:AC-273 Sem `imprimir`, baixar boleto e nota fiscal é recusado — e a
     * lista de anexos continua sendo exibida.
     */
    public function test_sem_imprimir_baixar_anexo_financeiro_e_recusado(): void
    {
        $anexo = $this->anexoDeCobranca();
        $usuario = $this->quemSoOlha();

        $this->actingAs($usuario)
            ->get(route('cobrancas.anexos.listar', $anexo->cobranca))
            ->assertOk();

        $this->actingAs($usuario)
            ->get(route('cobrancas.anexos.download', $anexo))
            ->assertForbidden();
    }

    /**
     * @spec:AC-274 Ligar a permissão não tira acesso de ninguém: os perfis que
     * o seeder entrega têm `imprimir`, e continuam exportando e baixando.
     *
     * É o critério que separa "a permissão passou a valer" de "o time perdeu o
     * boleto na publicação".
     */
    public function test_quem_tem_imprimir_continua_exportando_e_baixando(): void
    {
        $anexo = $this->anexoDeCobranca();

        // A factory entrega a conta com o perfil Administrador, que o seeder
        // preenche com as cinco ações em todos os recursos.
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->get(route('faturamento.index', ['exportar' => 'csv']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->actingAs($admin)
            ->get(route('cobrancas.anexos.download', $anexo))
            ->assertOk();
    }
}
