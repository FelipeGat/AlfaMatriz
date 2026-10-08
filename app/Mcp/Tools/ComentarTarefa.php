<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\TarefaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class ComentarTarefa extends Ferramenta
{
    protected string $name = 'comentar_tarefa';

    protected string $title = 'Comentar tarefa';

    protected string $description = 'Publica um comentário na conversa da tarefa, sem passar a vez a ninguém. Para perguntar ou responder, use conversar_na_tarefa.';

    // A rota de comentar é `permissao:tarefas` num POST, que o middleware lê
    // como `incluir`: comentar não mexe no que existe, acrescenta.
    protected array $permissao = ['tarefas', 'incluir'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tarefa' => $schema->string()->required()
                ->description('O código, como "#128".'),
            'comentario' => $schema->string()->required()->max(4000)
                ->description('O texto do comentário.'),
        ];
    }

    protected function executar(Request $request, User $usuario): Response
    {
        $dados = $request->validate([
            'tarefa' => 'required|string|max:20',
            'comentario' => 'required|string|max:4000',
        ]);

        $tarefa = $this->tarefaPeloCodigo($dados['tarefa']);

        if (! $tarefa) {
            return Response::error('Não há tarefa '.$dados['tarefa'].'.');
        }

        $corpo = trim($dados['comentario']);

        // A mesma rede do clique duplo da tela (AC-137): o agente que tenta de
        // novo depois de um erro de rede repetiria a frase no mesmo minuto.
        $repetido = $tarefa->comentarios()
            ->where('autor_id', $usuario->id)
            ->where('corpo', $corpo)
            ->where('created_at', '>=', now()->subMinute())
            ->exists();

        if ($repetido) {
            return Response::text('Este comentário acabou de ser publicado em '.$tarefa->codigo().'; não repeti.');
        }

        // Pelo serviço, como a tela: é ele que avisa quem a tarefa envolve.
        app(TarefaService::class)->comentar($tarefa, $corpo, $usuario);

        return Response::text('Comentário publicado em '.$tarefa->codigo().'.');
    }
}
