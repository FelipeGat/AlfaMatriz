<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Travar a tarefa pela porta do agente. Na tela o mesmo botão trava e destrava;
 * aqui são duas ferramentas, porque o agente que "alterna" sem olhar o estado
 * destrava o que queria travar.
 */
class BloquearTarefa extends Ferramenta
{
    protected string $name = 'bloquear_tarefa';

    protected string $title = 'Bloquear tarefa';

    protected string $description = 'Marca a tarefa como travada, dizendo o que a está travando. Ela continua na etapa em que está. O motivo é obrigatório.';

    protected array $permissao = ['tarefas', 'editar'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tarefa' => $schema->string()->required()->description('O código, como "#128".'),
            'motivo' => $schema->string()->required()->max(2000)->description('O que está travando e de quem depende.'),
        ];
    }

    protected function executar(Request $request, User $usuario): Response
    {
        $dados = $request->validate([
            'tarefa' => 'required|string|max:20',
            'motivo' => 'required|string|max:2000',
        ]);

        $tarefa = $this->tarefaPeloCodigo($dados['tarefa']);

        if (! $tarefa) {
            return Response::error('Não há tarefa '.$dados['tarefa'].'.');
        }

        app(FluxoTarefaService::class)->bloquear($tarefa, $dados['motivo']);

        return Response::text('Tarefa '.$tarefa->codigo().' bloqueada: '.trim($dados['motivo']));
    }
}
