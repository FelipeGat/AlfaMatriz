<?php

namespace App\Mcp\Tools;

use App\Models\Tarefa;
use App\Models\User;
use App\Services\DuplicidadeDeTarefas;
use App\Services\TarefaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * A porta do agente para abrir tarefa — a mesma do `TarefaController::store`,
 * menos os anexos, que não viajam por texto.
 */
class CriarTarefa extends Ferramenta
{
    use RelatoDoBug;

    protected string $name = 'criar_tarefa';

    protected string $title = 'Criar tarefa';

    protected string $description = 'Abre uma tarefa no quadro em seu nome. Título e tipo são obrigatórios. Com responsável ela nasce no Backlog; sem, na fila Aberta. Quem não faz triagem não define prioridade nem responsável — a resposta diz o que ficou para a triagem.';

    protected array $permissao = ['tarefas', 'incluir'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'titulo' => $schema->string()->required()->max(255)
                ->description('O que precisa ser feito, em uma linha.'),
            'resumo' => $schema->string()->max(500)
                ->description('O contexto: o que é e por quê. Num bug, o que aconteceu, o que deveria ter acontecido e a mensagem de erro, se houve. Até 500 caracteres.'),
            // Obrigatório e sem padrão desde o segundo ajuste da #204, como na
            // tela: com padrão, o bug ditado às pressas nascia desenvolvimento.
            'tipo' => $schema->string()->required()->enum(array_keys(Tarefa::TIPOS))
                ->description('Obrigatório. "desenvolvimento" passa por revisão, staging e produção; "bug" é o mesmo fluxo, para algo que quebrou, e exige quem e quando — o que aconteceu vai no resumo; "operacional" fecha direto de Em andamento.'),
            'sistema' => $schema->string()
                ->description('Nome do sistema a que a tarefa pertence (ver referencias).'),
            'responsavel' => $schema->string()
                ->description('Nome de quem vai fazer, ou "eu".'),
            'prioridade' => $schema->string()->enum(array_keys(Tarefa::PRIORIDADES))
                ->description('baixa, media (padrão), alta ou critica.'),
            'prazo' => $schema->string()
                ->description('Data combinada de entrega, AAAA-MM-DD.'),
            'itens' => $schema->array()->items($schema->string())
                ->description('Checklist: um passo por item.'),
            'tarefa_pai' => $schema->string()
                ->description('Código da tarefa-mãe ("#128"), para esta nascer como subtarefa dela.'),
            ...self::esquemaDoRelato($schema),
        ];
    }

    protected function executar(Request $request, User $usuario): Response
    {
        $dados = $request->validate([
            'titulo' => 'required|string|max:255',
            'resumo' => 'nullable|string|max:500',
            'tipo' => 'nullable|in:'.implode(',', array_keys(Tarefa::TIPOS)),
            'sistema' => 'nullable|string|max:255',
            'responsavel' => 'nullable|string|max:255',
            'prioridade' => 'nullable|in:'.implode(',', array_keys(Tarefa::PRIORIDADES)),
            'prazo' => 'nullable|date',
            'itens' => 'nullable|array',
            'itens.*' => 'nullable|string|max:255',
            'tarefa_pai' => 'nullable|string|max:20',
            ...self::regrasDoRelato(),
        ]);

        // `nullable` acima, e não `required`: a recusa sem tipo é do
        // `TarefaService`, com a frase que lista os tipos — a mesma da tela.
        if ($recusa = self::relatoForaDoBug($dados, $dados['tipo'] ?? null)) {
            return Response::error($recusa);
        }

        if (filled($dados['sistema'] ?? null)) {
            $sistema = $this->sistema($dados['sistema']);

            if (is_string($sistema)) {
                return Response::error($sistema);
            }

            $dados['sistema_id'] = $sistema->id;
        }

        if (filled($dados['responsavel'] ?? null)) {
            $pessoa = $this->pessoa($dados['responsavel'], $usuario);

            if (is_string($pessoa)) {
                return Response::error($pessoa);
            }

            $dados['responsavel_id'] = $pessoa->id;
        }

        $paiId = null;

        if (filled($dados['tarefa_pai'] ?? null)) {
            $pai = $this->tarefaPeloCodigo($dados['tarefa_pai']);

            if (! $pai) {
                return Response::error('Não há tarefa '.$dados['tarefa_pai'].' para ser a mãe desta.');
            }

            $paiId = $pai->id;
        }

        $tarefa = app(TarefaService::class)->criar(
            Arr::only($dados, ['titulo', 'resumo', 'tipo', 'sistema_id', 'responsavel_id', 'prioridade', 'prazo'])
                + self::relatoDoEnvio($dados),
            $usuario,
            $dados['itens'] ?? [],
            $paiId,
        );

        if (! $tarefa) {
            return Response::text('Uma tarefa idêntica foi criada há menos de um minuto; nada foi gravado de novo.');
        }

        $tarefa->load(['responsavel', 'sistema']);

        $avisos = ['Tarefa '.$tarefa->codigo().' criada em '.Tarefa::rotuloDaEtapa($tarefa->status).'.'];

        // O mesmo silêncio da tela (`semTriagemDeQuemNaoTriaga`), só que dito:
        // a tela esconde os campos de quem não triaga, e o agente não tem tela
        // — sem esta frase ele acharia que direcionou e a tarefa está na fila.
        if (! $usuario->podeTriarTarefas() && (filled($dados['responsavel'] ?? null) || filled($dados['prioridade'] ?? null) || filled($dados['prazo'] ?? null))) {
            $avisos[] = 'Prioridade, responsável e prazo ficaram para a triagem, porque você não faz triagem.';
        }

        if ($paiId && $tarefa->tarefa_pai_id === null) {
            $avisos[] = 'A tarefa '.$dados['tarefa_pai'].' não pode receber subtarefa (está encerrada ou já é subtarefa); esta nasceu solta.';
        }

        return Response::text(implode(' ', $avisos)."\n".$this->linhaDaTarefa($tarefa).$this->parecidasEmCurso($tarefa));
    }

    /**
     * O aviso de parecidas da tela, dito na resposta (#205).
     *
     * Depois de criar, e não antes: a tela avisa enquanto se digita e não
     * barra o Salvar, e o agente não tem "enquanto se digita". Recusar a
     * criação seria a trava que a tela não tem; avisar na resposta deixa o
     * agente — e quem o comanda — decidir se marca a nova como duplicada.
     */
    private function parecidasEmCurso(Tarefa $tarefa): string
    {
        $parecidas = app(DuplicidadeDeTarefas::class)
            ->parecidas($tarefa->titulo, $tarefa->resumo, $tarefa->sistema_id, ignorarId: $tarefa->id);

        if ($parecidas->isEmpty()) {
            return '';
        }

        return "\n\nAtenção: já existem tarefas parecidas em curso. Se for o mesmo pedido, use marcar_duplicada.\n"
            .$parecidas->map(fn (Tarefa $parecida) => '- '.$this->linhaDaTarefa($parecida))->implode("\n");
    }
}
