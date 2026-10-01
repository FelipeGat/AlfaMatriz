<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class DestravarTarefa extends Ferramenta
{
    protected string $name = 'destravar_tarefa';

    protected string $title = 'Destravar tarefa';

    protected string $description = 'Tira a marca de travada de uma tarefa bloqueada e avisa o responsável.';

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

        // O motor devolve a tarefa em silêncio quando ela não está travada, que
        // é o certo para o botão da tela. Para o agente o silêncio seria "feito".
        if (! $tarefa->estaBloqueada()) {
            return Response::error('A tarefa '.$tarefa->codigo().' não está bloqueada.');
        }

        app(FluxoTarefaService::class)->destravar($tarefa);

        return Response::text('Tarefa '.$tarefa->codigo().' destravada.');
    }
}
