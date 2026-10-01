<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class AtualizarItem extends Ferramenta
{
    protected string $name = 'atualizar_item';

    protected string $title = 'Marcar ou reescrever item do checklist';

    protected string $description = 'Marca um item do checklist como feito (ou desfaz a marca), ou reescreve o texto dele. O número do item é o que ver_tarefa mostra ("item 12").';

    // `permissao:tarefas` num PUT: `editar`.
    protected array $permissao = ['tarefas', 'editar'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'item' => $schema->integer()->required()->min(1)->description('O número do item, como aparece em ver_tarefa.'),
            'feito' => $schema->boolean()->description('true marca como feito, false desmarca.'),
            'texto' => $schema->string()->max(255)->description('Novo texto do item.'),
        ];
    }

    protected function executar(Request $request, User $usuario): Response
    {
        $dados = $request->validate([
            'item' => 'required|integer|min:1',
            'feito' => 'sometimes|required|boolean',
            'texto' => 'sometimes|required|string|max:255',
        ]);

        $item = $this->itemPeloNumero($dados['item']);

        if (! $item) {
            return Response::error('Não há item '.$dados['item'].'. Veja os números em ver_tarefa.');
        }

        $mudancas = [];

        if (array_key_exists('texto', $dados)) {
            $mudancas['texto'] = trim($dados['texto']);
        }

        if (array_key_exists('feito', $dados)) {
            $mudancas['feito'] = filter_var($dados['feito'], FILTER_VALIDATE_BOOLEAN);
        }

        if ($mudancas === []) {
            return Response::error('Diga o que muda no item: "feito" ou "texto".');
        }

        $item->update($mudancas);

        return Response::text('Checklist da #'.$item->tarefa_id.': '.$this->linhaDoItem($item));
    }
}
