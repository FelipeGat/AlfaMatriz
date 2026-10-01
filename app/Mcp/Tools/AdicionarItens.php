<?php

namespace App\Mcp\Tools;

use App\Models\TarefaItem;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class AdicionarItens extends Ferramenta
{
    protected string $name = 'adicionar_itens';

    protected string $title = 'Adicionar itens ao checklist';

    protected string $description = 'Acrescenta um ou mais itens ao fim do checklist de uma tarefa, na ordem em que vierem.';

    // A rota de criar item é `permissao:tarefas` num POST: `incluir`.
    protected array $permissao = ['tarefas', 'incluir'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tarefa' => $schema->string()->required()->description('O código, como "#128".'),
            'itens' => $schema->array()->items($schema->string())->required()->description('Os passos, um por item.'),
        ];
    }

    protected function executar(Request $request, User $usuario): Response
    {
        $dados = $request->validate([
            'tarefa' => 'required|string|max:20',
            'itens' => 'required|array|min:1|max:30',
            'itens.*' => 'required|string|max:255',
        ]);

        $tarefa = $this->tarefaPeloCodigo($dados['tarefa']);

        if (! $tarefa) {
            return Response::error('Não há tarefa '.$dados['tarefa'].'.');
        }

        $criados = collect($dados['itens'])
            ->map(fn (string $texto) => $tarefa->itens()->create(['texto' => trim($texto)]));

        return Response::text(
            $criados->count().' item(ns) no checklist da '.$tarefa->codigo().":\n"
            .$criados->map(fn (TarefaItem $item) => $this->linhaDoItem($item))->implode("\n")
        );
    }
}
