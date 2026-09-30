<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * A mesma decisão do `TarefaController::conversar`: se a bola está com quem
 * fala, a mensagem é resposta; senão é pergunta. A ferramenta não escolhe —
 * o quadro sabe de quem é a vez.
 */
class ConversarNaTarefa extends Ferramenta
{
    protected string $name = 'conversar_na_tarefa';

    protected string $title = 'Perguntar ou responder';

    protected string $description = 'Pergunta ou responde na conversa da tarefa. Se há uma pergunta esperando por você, a mensagem é a resposta e devolve a vez; senão é uma pergunta e passa a vez ao outro lado — o responsável, ou quem você indicar em "para" quando a tarefa é sua e ninguém entrou na conversa ainda.';

    protected array $permissao = ['tarefas', 'editar'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tarefa' => $schema->string()->required()
                ->description('O código, como "#128".'),
            'mensagem' => $schema->string()->required()->max(2000)
                ->description('A pergunta ou a resposta.'),
            'para' => $schema->string()
                ->description('Nome de quem deve responder, só quando a tarefa é sua e ainda não há outro lado.'),
        ];
    }

    protected function executar(Request $request, User $usuario): Response
    {
        $dados = $request->validate([
            'tarefa' => 'required|string|max:20',
            'mensagem' => 'required|string|max:2000',
            'para' => 'nullable|string|max:255',
        ]);

        $tarefa = $this->tarefaPeloCodigo($dados['tarefa']);

        if (! $tarefa) {
            return Response::error('Não há tarefa '.$dados['tarefa'].'.');
        }

        $fluxo = app(FluxoTarefaService::class);

        if ($tarefa->esperaRespostaDe($usuario)) {
            $fluxo->responder($tarefa, $usuario, $dados['mensagem']);

            return Response::text('Resposta registrada em '.$tarefa->codigo().'; a vez voltou para quem perguntou.');
        }

        $paraId = null;

        if (filled($dados['para'] ?? null)) {
            $pessoa = $this->pessoa($dados['para'], $usuario);

            if (is_string($pessoa)) {
                return Response::error($pessoa);
            }

            $paraId = $pessoa->id;
        }

        $fluxo->perguntar($tarefa, $usuario, $dados['mensagem'], $paraId);

        $tarefa->refresh()->load('perguntaPara');

        return Response::text('Pergunta registrada em '.$tarefa->codigo().' para '
            .($tarefa->perguntaPara?->name ?? 'o outro lado').'. Rodada '.$tarefa->rodadas.'.');
    }
}
