<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class RemoverItem extends Ferramenta
{
    protected string $name = 'remover_item';

    protected string $title = 'Remover item do checklist';

    protected string $description = 'Remove um item do checklist de uma tarefa. O número do item é o que ver_tarefa mostra ("item 12").';

    // `permissao:tarefas` num DELETE: `excluir`.
    protected array $permissao = ['tarefas', 'excluir'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'item' => $schema->integer()->required()->min(1)->description('O número do item, como aparece em ver_tarefa.'),
        ];
    }

    protected function executar(Request $request, User $usuario): Response
    {
        $dados = $request->validate(['item' => 'required|integer|min:1']);

        $item = $this->itemPeloNumero($dados['item']);

        if (! $item) {
            return Response::error('Não há item '.$dados['item'].'. Veja os números em ver_tarefa.');
        }

        $texto = $item->texto;
        $tarefaId = $item->tarefa_id;

        $item->delete();

        return Response::text('Item "'.$texto.'" removido do checklist da #'.$tarefaId.'.');
    }
}
