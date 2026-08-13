<?php

namespace Tests\Feature\Auditoria;

use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\CobrancaAnexo;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\TarefaComentario;
use App\Models\TarefaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * As escritas que escapavam do registro automático.
 *
 * Todas pelo mesmo motivo: o trait de auditoria vive nos EVENTOS do Eloquent, e
 * há três formas de escrever no banco sem disparar evento nenhum — o pivô
 * (`attach`, `syncWithoutDetaching`, `updateExistingPivot`), a query em massa
 * (`Model::where(...)->update(...)`) e o SQL cru (`DB::table`). O modelo que
 * simplesmente não tinha o trait é a quarta, e a mais fácil de não perceber.
 *
 * Cada teste aqui existe porque a lacuna correspondente foi encontrada em
 * produção, olhando a tela e não achando o que deveria estar lá.
 */
class LacunasFechadasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function ator(): User
    {
        return User::factory()->create(['name' => 'Camila']);
    }

    /**
     * O corpo mínimo que `clientes.update` aceita.
     *
     * Existe porque a primeira versão destes testes enviava um corpo inválido:
     * a validação recusava, o controller nem chegava ao trecho auditado, e o
     * teste reprovava dizendo "não registrou" quando o que não aconteceu foi a
     * gravação inteira. Payload incompleto em teste de auditoria mente sobre
     * qual das duas coisas quebrou.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function fichaValida(Cliente $cliente, array $extra = []): array
    {
        return array_merge([
            'revenda_id' => $cliente->revenda_id,
            'tipo_pessoa' => 'PJ',
            'razao_social' => $cliente->razao_social ?? $cliente->nome,
            'tipo_cliente' => 'AVULSO',
        ], $extra);
    }

    private function clienteComRevenda(): Cliente
    {
        $revenda = Revenda::create(['nome' => 'Invest Soluções', 'ativo' => true]);

        return Cliente::create([
            'nome' => 'Academia Corpo e Ação',
            'razao_social' => 'Academia Corpo e Ação',
            'revenda_id' => $revenda->id,
            'ativo' => true,
        ]);
    }

    public function test_apagar_anexo_de_receita_deixa_rastro(): void
    {
        Storage::fake('public');
        $this->actingAs($this->ator());

        $cobranca = Cobranca::create([
            'descricao' => 'Mensalidade agosto', 'valor' => 500,
            'status' => 'pendente', 'data_vencimento' => '2026-08-15',
        ]);

        $anexo = CobrancaAnexo::create([
            'cobranca_id' => $cobranca->id, 'tipo' => 'boleto',
            'nome_original' => 'boleto-agosto.pdf', 'nome_arquivo' => 'x.pdf',
            'caminho' => 'anexos/x.pdf', 'tamanho' => 8,
        ]);

        $anexo->delete();

        $linha = Auditoria::where('recurso', 'cobrancas')->where('acao', 'excluiu')->sole();

        // Dava para ver quem BAIXOU um boleto e não quem o apagou — a metade
        // pior das duas, porque o download deixa o documento existindo e a
        // exclusão o desfaz.
        $this->assertSame('boleto-agosto.pdf', $linha->descricao);
        $this->assertSame('Camila', $linha->usuario_nome);
    }

    public function test_trocar_os_sistemas_do_cliente_deixa_rastro(): void
    {
        $this->actingAs($this->ator());

        $gym = Sistema::factory()->alfagym()->create(['nome' => 'AlfaGym']);
        $control = Sistema::factory()->alfacontrol()->create(['nome' => 'AlfaControl']);

        $cliente = $this->clienteComRevenda();
        $cliente->sistemas()->attach($gym->id, ['ativo' => true]);

        $this->put(route('clientes.update', $cliente), $this->fichaValida($cliente, [
            'sistemas' => [$control->id],
        ]))->assertRedirect();

        $linha = Auditoria::where('recurso', 'clientes')
            ->whereNotNull('alteracoes')
            ->get()
            ->first(fn ($l) => isset($l->alteracoes['sistemas']));

        // O vínculo mora no pivô `cliente_sistema`, e nem `attach` nem
        // `updateExistingPivot` disparam evento — nem com `ClienteSistema`
        // sendo um `Pivot`. Sem registro explícito, tirar um sistema do cliente
        // derrubava a receita dele no mês seguinte sem deixar quem decidiu.
        $this->assertNotNull($linha, 'A troca de sistemas do cliente não deixou rastro.');
        $this->assertSame('AlfaGym', $linha->alteracoes['sistemas']['de']);
        $this->assertSame('AlfaControl', $linha->alteracoes['sistemas']['para']);
    }

    public function test_trocar_o_email_do_cliente_deixa_uma_linha_e_nao_seis(): void
    {
        $this->actingAs($this->ator());

        $cliente = $this->clienteComRevenda();
        $cliente->emails()->create(['email' => 'antigo@academia.com', 'principal' => true]);

        $this->put(route('clientes.update', $cliente), $this->fichaValida($cliente, [
            'emails' => [['email' => 'novo@academia.com', 'principal' => '1']],
        ]))->assertRedirect();

        $linhas = Auditoria::where('recurso', 'clientes')->get()
            ->filter(fn ($l) => isset($l->alteracoes['e-mails']));

        // UMA linha. A lista é regravada do zero a cada salvamento, então o
        // trait nos modelos produziria uma exclusão e uma criação por contato
        // toda vez que alguém salvasse a ficha para corrigir a cidade.
        $this->assertCount(1, $linhas);
        $this->assertSame('antigo@academia.com', $linhas->first()->alteracoes['e-mails']['de']);
        $this->assertSame('novo@academia.com', $linhas->first()->alteracoes['e-mails']['para']);
    }

    public function test_salvar_a_ficha_sem_mexer_nos_contatos_nao_registra_contato(): void
    {
        $this->actingAs($this->ator());

        $cliente = $this->clienteComRevenda();
        $cliente->emails()->create(['email' => 'contato@academia.com', 'principal' => true]);

        $this->put(route('clientes.update', $cliente), $this->fichaValida($cliente, [
            'cidade' => 'Vitória',
            'emails' => [['email' => 'contato@academia.com', 'principal' => '1']],
        ]))->assertRedirect();

        $comContato = Auditoria::where('recurso', 'clientes')->get()
            ->filter(fn ($l) => isset($l->alteracoes['e-mails']));

        $this->assertCount(0, $comContato, 'Salvar sem mexer nos contatos registrou mudança de contato.');
    }

    public function test_a_tarefa_o_comentario_e_o_item_deixam_rastro(): void
    {
        $ator = $this->ator();
        $this->actingAs($ator);

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $ator->id,
            'status' => 'backlog',
            'titulo' => 'Corrigir boleto duplicado',
        ]);

        TarefaComentario::create([
            'tarefa_id' => $tarefa->id,
            'autor_id' => $ator->id,
            'corpo' => 'Reproduzi na conta da Invest.',
        ]);

        TarefaItem::create(['tarefa_id' => $tarefa->id, 'texto' => 'Conferir o log', 'ordem' => 1]);

        $linhas = Auditoria::where('recurso', 'tarefas')->get();

        $this->assertSame(3, $linhas->count());
        $this->assertTrue($linhas->contains('descricao', 'Corrigir boleto duplicado'));
        $this->assertTrue($linhas->contains('descricao', 'Conferir o log'));
        $this->assertTrue($linhas->contains('descricao', 'Reproduzi na conta da Invest.'));
    }

    public function test_reordenar_o_card_nao_polui_o_rastro(): void
    {
        $ator = $this->ator();
        $this->actingAs($ator);

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $ator->id, 'status' => 'backlog', 'titulo' => 'Uma tarefa',
        ]);

        Auditoria::query()->delete();

        // Arrastar card é arrumação de mesa, não fato de negócio — e acontece
        // dezenas de vezes por dia. Registrada, empurraria para fora da
        // primeira página a mudança de etapa que aconteceu no meio.
        $tarefa->update(['ordem' => 7]);

        $this->assertSame(0, Auditoria::where('recurso', 'tarefas')->count());
    }
}
