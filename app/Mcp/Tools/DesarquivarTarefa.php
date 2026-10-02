<?php

namespace App\Mcp\Tools;

use App\Models\Tarefa;
use App\Models\User;
use App\Services\ArquivoDeTarefas;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class DesarquivarTarefa extends Ferramenta
{
    protected string $name = 'desarquivar_tarefa';

    protected string $title = 'Desarquivar tarefa';

    protected string $description = 'Devolve ao quadro uma tarefa arquivada, na etapa e com o responsável que ela tinha. Só quem faz triagem desarquiva.';

    protected array $permissao = ['tarefas', 'editar'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tarefa' => $schema->string()->required()->description('O código, como "#128".'),
        ];
    }

    protected function executar(Request $request, User $usuario): Response
    {
        $dados = $request->validate(['tarefa' => 'required|string|max:20']);

        $tarefa = $this->tarefaPeloCodigo($dados['tarefa']);

        if (! $tarefa) {
            return Response::error('Não há tarefa '.$dados['tarefa'].'.');
        }

        $tarefa = app(ArquivoDeTarefas::class)->desarquivar($tarefa, $usuario);

        return Response::text('Tarefa '.$tarefa->codigo().' desarquivada: voltou para '.Tarefa::rotuloDaEtapa($tarefa->status).'.'
            ."\n".$this->linhaDaTarefa($tarefa));
    }
}
