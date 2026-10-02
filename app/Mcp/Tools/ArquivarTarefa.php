<?php

namespace App\Mcp\Tools;

use App\Models\Tarefa;
use App\Models\User;
use App\Services\ArquivoDeTarefas;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Arquivar a tarefa (#208) — a porta do agente para o "Arquivar" do modal
 * (`tarefas.arquivar`). Duas ferramentas, e não uma que alterna, pelo mesmo
 * motivo do bloqueio: o agente que alterna sem olhar o estado desarquiva o
 * que queria arquivar.
 */
class ArquivarTarefa extends Ferramenta
{
    protected string $name = 'arquivar_tarefa';

    protected string $title = 'Arquivar tarefa';

    protected string $description = 'Tira do quadro uma tarefa que não vai andar agora, sem encerrá-la: ela guarda a etapa, o responsável e o histórico, e volta para o mesmo lugar ao ser desarquivada — ou sozinha, quando quem a abriu comenta nela. Quem abriu recebe o aviso. Só quem faz triagem arquiva. Para "não vamos fazer", use cancelar (mover_tarefa); para "é o mesmo pedido de outra", marcar_duplicada.';

    protected array $permissao = ['tarefas', 'editar'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tarefa' => $schema->string()->required()->description('O código, como "#128".'),
            'motivo' => $schema->string()->required()->enum(array_keys(Tarefa::MOTIVOS_DE_ARQUIVAMENTO))
                ->description('depois (Fica para depois), sem_retorno (quem abriu não respondeu) ou nao_confirmado (não deu para reproduzir, ou talvez já resolvido).'),
            'nota' => $schema->string()->max(2000)->description('Opcional: o contexto que quem reabrir vai querer ler.'),
        ];
    }

    protected function executar(Request $request, User $usuario): Response
    {
        $dados = $request->validate([
            'tarefa' => 'required|string|max:20',
            // Sem `in:` aqui: a recusa do motivo é do `ArquivoDeTarefas`, que
            // diz em português quais existem — a do validador sai em inglês.
            'motivo' => 'required|string|max:20',
            'nota' => 'nullable|string|max:2000',
        ]);

        $tarefa = $this->tarefaPeloCodigo($dados['tarefa']);

        if (! $tarefa) {
            return Response::error('Não há tarefa '.$dados['tarefa'].'.');
        }

        $tarefa = app(ArquivoDeTarefas::class)->arquivar($tarefa, $dados['motivo'], $dados['nota'] ?? null, $usuario);

        return Response::text('Tarefa '.$tarefa->codigo().' arquivada ('.$tarefa->rotuloDoArquivamento().').'
            ."\n".$this->linhaDaTarefa($tarefa));
    }
}
