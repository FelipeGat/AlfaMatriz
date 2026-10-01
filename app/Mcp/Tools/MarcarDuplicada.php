<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\DuplicidadeDeTarefas;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Marcar a tarefa como duplicada de outra (#205) — a porta do agente para o
 * "Cancelar como duplicada" do modal (`tarefas.duplicada`).
 *
 * Existe por causa do aviso de `criar_tarefa`: ele diz ao agente que há uma
 * parecida, e sem esta ferramenta a única saída seria cancelar com o número
 * escrito no motivo — que é exatamente o vínculo que só uma das pontas via.
 */
class MarcarDuplicada extends Ferramenta
{
    protected string $name = 'marcar_duplicada';

    protected string $title = 'Marcar como duplicada';

    protected string $description = 'Cancela uma tarefa por ser o mesmo pedido de outra, gravando o vínculo com a original — que aparece nas duas. Use quando criar_tarefa ou a triagem apontarem uma parecida que é de fato a mesma. Só quem pode mover a tarefa marca; a original não pode estar cancelada nem ser ela mesma duplicada.';

    // Marcar é cancelar: a rota pede o mesmo par de `mover_tarefa`.
    protected array $permissao = ['tarefas', 'editar'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tarefa' => $schema->string()->required()->description('A tarefa repetida, que vai ser cancelada ("#191").'),
            'original' => $schema->string()->required()->description('A tarefa que fica ("#185").'),
        ];
    }

    protected function executar(Request $request, User $usuario): Response
    {
        $dados = $request->validate([
            'tarefa' => 'required|string|max:20',
            'original' => 'required|string|max:20',
        ]);

        $tarefa = $this->tarefaPeloCodigo($dados['tarefa']);
        $original = $this->tarefaPeloCodigo($dados['original']);

        if (! $tarefa) {
            return Response::error('Não há tarefa '.$dados['tarefa'].'.');
        }

        if (! $original) {
            return Response::error('Não há tarefa '.$dados['original'].'.');
        }

        app(DuplicidadeDeTarefas::class)->marcar($tarefa, $original, $usuario);

        return Response::text('Tarefa '.$tarefa->codigo().' cancelada como duplicada de '.$original->codigo().'.'
            ."\n".$this->linhaDaTarefa($original->fresh()));
    }
}
