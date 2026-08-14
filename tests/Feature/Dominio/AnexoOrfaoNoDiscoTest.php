<?php

namespace Tests\Feature\Dominio;

use App\Models\Cobranca;
use App\Models\CobrancaAnexo;
use App\Models\ContaPagar;
use App\Models\ContaPagarAnexo;
use App\Models\Tarefa;
use App\Models\TarefaAnexo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * O arquivo não sobrevive à linha que o apontava.
 *
 * As três tabelas de anexo — tarefa, receita e despesa — nascem com
 * `cascadeOnDelete` no dono, e o cascade é do BANCO: ele apaga linhas, que é
 * tudo o que ele sabe fazer. Arquivo em disco não tem chave estrangeira, então
 * excluir o dono levava as linhas e deixava para trás cada print, cada log,
 * cada boleto e cada nota fiscal — para sempre.
 *
 * O que torna isso caro é o silêncio: não há erro, não há linha que aponte para
 * o arquivo, não há tela que o mostre e ninguém vai procurá-lo. A conta só se
 * apresenta como disco cheio anos depois, com um diretório de nomes aleatórios
 * que já não se sabe de quem são — e aí não há como distinguir o órfão do que
 * ainda está em uso sem cruzar o disco inteiro com o banco.
 *
 * Um dono por teste, e não um teste só, porque cada um erra de um jeito
 * diferente — as duas financeiras somem de vez, e a tarefa tem exclusão
 * reversível, onde o anexo precisa sobreviver à lixeira. O caso da tarefa mora
 * com a feature, no `AnexosDaTarefaTest`; aqui ficam os dois financeiros e a
 * regra comum às três tabelas.
 */
class AnexoOrfaoNoDiscoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * A receita apagada leva boleto e nota fiscal do disco junto.
     *
     * Aqui o que ficava para trás não era só espaço: boleto e nota trazem conta
     * bancária e CNPJ, e um arquivo desses sobrando num disco sem nada que o
     * aponte é pior do que ocupar lugar.
     */
    public function test_apagar_receita_apaga_os_arquivos_dos_anexos(): void
    {
        $cobranca = Cobranca::create([
            'descricao' => 'Mensalidade agosto',
            'valor' => 500,
            'status' => 'pendente',
            'data_vencimento' => '2026-08-15',
        ]);

        $caminhos = collect(['boleto-agosto.pdf', 'nf-agosto.pdf'])->map(function (string $nome) use ($cobranca) {
            Storage::disk('public')->put('anexos/cobrancas/'.$nome, 'conteudo');

            CobrancaAnexo::create([
                'cobranca_id' => $cobranca->id,
                'tipo' => 'boleto',
                'nome_original' => $nome,
                'nome_arquivo' => $nome,
                'caminho' => 'anexos/cobrancas/'.$nome,
                'tamanho' => 8,
            ]);

            return 'anexos/cobrancas/'.$nome;
        });

        $cobranca->delete();

        $this->assertDatabaseCount('cobranca_anexos', 0);
        $caminhos->each(fn (string $caminho) => Storage::disk('public')->assertMissing($caminho));
    }

    /** A despesa segue a mesma regra da receita — ver o `booted()` do `ContaPagar`. */
    public function test_apagar_despesa_apaga_os_arquivos_dos_anexos(): void
    {
        $conta = ContaPagar::create([
            'descricao' => 'Hospedagem agosto',
            'valor' => 320,
            'status' => 'pendente',
            'data_vencimento' => '2026-08-20',
        ]);

        Storage::disk('public')->put('anexos/contas/nf-hospedagem.pdf', 'conteudo');

        ContaPagarAnexo::create([
            'conta_pagar_id' => $conta->id,
            'tipo' => 'nf',
            'nome_original' => 'nf-hospedagem.pdf',
            'nome_arquivo' => 'nf-hospedagem.pdf',
            'caminho' => 'anexos/contas/nf-hospedagem.pdf',
            'tamanho' => 8,
        ]);

        $conta->delete();

        $this->assertDatabaseCount('conta_pagar_anexos', 0);
        Storage::disk('public')->assertMissing('anexos/contas/nf-hospedagem.pdf');
    }

    /**
     * Apagar UM anexo pelo Eloquent leva o arquivo, sem ninguém pedir.
     *
     * A regra mora no evento, e não na rota: as três rotas de remoção faziam as
     * duas metades na mão, então cada caminho novo que apagasse a linha sem
     * repetir o gesto voltaria a deixar arquivo para trás. Esta é a garantia de
     * que não é preciso lembrar.
     */
    public function test_apagar_a_linha_do_anexo_apaga_o_arquivo_sem_ajuda_da_rota(): void
    {
        $tarefa = Tarefa::factory()->create(['criado_por_id' => User::factory()]);

        Storage::disk('public')->put('imagens/tarefas/print.png', 'conteudo');

        $anexo = TarefaAnexo::create([
            'tarefa_id' => $tarefa->id,
            'autor_id' => User::factory()->create()->id,
            'nome_original' => 'print.png',
            'nome_arquivo' => 'print.png',
            'mime' => 'image/png',
            'caminho' => 'imagens/tarefas/print.png',
            'tamanho' => 8,
        ]);

        $anexo->delete();

        Storage::disk('public')->assertMissing('imagens/tarefas/print.png');
    }

    /**
     * Arquivo que já não está no disco não derruba a exclusão.
     *
     * O caso acontece de verdade: restaurar só o banco, sem a metade dos
     * anexos, deixa toda linha apontando para um arquivo que não existe. Apagar
     * a tarefa depois disso precisa continuar funcionando — a alternativa é uma
     * exclusão que estoura por causa de um arquivo que ninguém sente falta.
     */
    public function test_arquivo_ja_ausente_nao_derruba_a_exclusao(): void
    {
        $tarefa = Tarefa::factory()->create(['criado_por_id' => User::factory()]);

        TarefaAnexo::create([
            'tarefa_id' => $tarefa->id,
            'autor_id' => User::factory()->create()->id,
            'nome_original' => 'sumiu.png',
            'nome_arquivo' => 'sumiu.png',
            'mime' => 'image/png',
            'caminho' => 'imagens/tarefas/sumiu.png',
            'tamanho' => 8,
        ]);

        $tarefa->forceDelete();

        $this->assertDatabaseCount('tarefa_anexos', 0);
    }
}
