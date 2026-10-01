<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\TarefaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Excluir a tarefa — o único gesto do quadro sem desfazer.
 *
 * A tela pede uma confirmação antes. A daqui é repetir o TÍTULO exato da
 * tarefa: obriga o agente a ter lido a tarefa que vai apagar, e faz um número
 * trocado ("apaga a #46" ouvido como "#64") bater no título errado em vez de
 * apagar a tarefa errada.
 */
class ExcluirTarefa extends Ferramenta
{
    protected string $name = 'excluir_tarefa';

    protected string $title = 'Excluir tarefa';

    protected string $description = 'Apaga uma tarefa do quadro para sempre, com histórico, conversa e anexos. Não tem desfazer. Só quem faz triagem pode, e nunca uma tarefa com subtarefa aberta. Para encerrar sem apagar, mova para cancelada. Exige repetir o título exato da tarefa, como confirmação.';

    // A rota é `permissao:tarefas` num DELETE, que o middleware lê como `excluir`.
    protected array $permissao = ['tarefas', 'excluir'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tarefa' => $schema->string()->required()->description('O código, como "#128".'),
            'titulo' => $schema->string()->required()->description('O título EXATO da tarefa, como ver_tarefa mostra. É a confirmação.'),
        ];
    }

    protected function executar(Request $request, User $usuario): Response
    {
        $dados = $request->validate([
            'tarefa' => 'required|string|max:20',
            'titulo' => 'required|string|max:255',
        ]);

        $tarefa = $this->tarefaPeloCodigo($dados['tarefa']);

        if (! $tarefa) {
            return Response::error('Não há tarefa '.$dados['tarefa'].'.');
        }

        if (trim($dados['titulo']) !== trim($tarefa->titulo)) {
            return Response::error('O título não confere com o da tarefa '.$tarefa->codigo().' ("'.$tarefa->titulo.'"). Nada foi apagado.');
        }

        $descricao = $tarefa->codigo().' "'.$tarefa->titulo.'"';

        app(TarefaService::class)->excluir($tarefa, $usuario);

        return Response::text('Tarefa '.$descricao.' excluída do quadro.');
    }
}
