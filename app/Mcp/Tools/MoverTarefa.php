<?php

namespace App\Mcp\Tools;

use App\Models\Tarefa;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Mover pela porta do agente — as mesmas três perguntas do
 * `TarefaController::mover`, na mesma ordem: pode mover? ainda está onde
 * viu? pode encerrar? Só então o motor do fluxo responde.
 *
 * `de` é obrigatório aqui, e opcional na rota. A rota tolera a ausência
 * porque nem todo caminho da tela sabe a etapa de origem; o agente sempre
 * sabe, porque acabou de ler a tarefa — e um agente que move sem conferir é
 * exatamente a mão que sobrescreve o movimento alheio em silêncio.
 */
class MoverTarefa extends Ferramenta
{
    protected string $name = 'mover_tarefa';

    protected string $title = 'Mover tarefa';

    protected string $description = 'Move uma tarefa de etapa com as regras do quadro. Passe em "de" a etapa em que você a viu: se alguém já moveu, a ferramenta recusa. Devolver para Em andamento e cancelar exigem motivo. O veredito de teste (Em staging → Em produção) é registrado pela tela, não por aqui.';

    protected array $permissao = ['tarefas', 'editar'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tarefa' => $schema->string()->required()
                ->description('O código, como "#128".'),
            'para' => $schema->string()->required()->enum(array_keys(Tarefa::STATUS))
                ->description('A chave da etapa de destino (ver referencias).'),
            'de' => $schema->string()->required()->enum(array_keys(Tarefa::STATUS))
                ->description('A chave da etapa em que a tarefa está AGORA, como você a leu.'),
            'motivo' => $schema->string()
                ->description('Obrigatório ao devolver para correção ou cancelar: o que faltou.'),
            'versao_producao' => $schema->string()->max(60)
                ->description('Ao entrar em produção: a tag ou versão publicada.'),
            'interlocutor' => $schema->string()
                ->description('Quem revisa ou testa, nos portões de exame. Opcional.'),
        ];
    }

    protected function executar(Request $request, User $usuario): Response
    {
        $dados = $request->validate([
            'tarefa' => 'required|string|max:20',
            'para' => 'required|in:'.implode(',', array_keys(Tarefa::STATUS)),
            'de' => 'required|in:'.implode(',', array_keys(Tarefa::STATUS)),
            'motivo' => 'nullable|string|max:2000',
            'versao_producao' => 'nullable|string|max:60',
            'interlocutor' => 'nullable|string|max:255',
        ]);

        $tarefa = $this->tarefaPeloCodigo($dados['tarefa']);

        if (! $tarefa) {
            return Response::error('Não há tarefa '.$dados['tarefa'].'.');
        }

        if ($impedimento = $tarefa->motivoParaNaoMover($usuario)) {
            return Response::error($impedimento);
        }

        if ($dados['de'] !== $tarefa->status) {
            return Response::error('Alguém já moveu esta tarefa para '.Tarefa::rotuloDaEtapa($tarefa->status)
                .'. Leia-a de novo antes de mover.');
        }

        if ($dados['para'] === 'concluida'
            && in_array('concluida', FluxoTarefaService::transicoesDe($tarefa), true)
            && ($impedimento = $tarefa->motivoParaNaoConcluir($usuario))) {
            return Response::error($impedimento);
        }

        $interlocutorId = null;

        if (filled($dados['interlocutor'] ?? null)) {
            $pessoa = $this->pessoa($dados['interlocutor'], $usuario);

            if (is_string($pessoa)) {
                return Response::error($pessoa);
            }

            $interlocutorId = $pessoa->id;
        }

        $origem = Tarefa::rotuloDaEtapa($tarefa->status);

        app(FluxoTarefaService::class)->mover($tarefa, $dados['para'], [
            'motivo' => $dados['motivo'] ?? null,
            'versao_producao' => $dados['versao_producao'] ?? null,
            'interlocutor_id' => $interlocutorId,
        ], livre: $usuario->podeTriarTarefas());

        $tarefa->refresh()->load(['responsavel', 'sistema', 'perguntaPara']);

        return Response::text(
            'Tarefa '.$tarefa->codigo().' movida de '.$origem.' para '.Tarefa::rotuloDaEtapa($tarefa->status).'.'
            ."\n".$this->linhaDaTarefa($tarefa)
        );
    }
}
