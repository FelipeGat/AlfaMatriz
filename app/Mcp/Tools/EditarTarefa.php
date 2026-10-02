<?php

namespace App\Mcp\Tools;

use App\Models\Tarefa;
use App\Models\User;
use App\Services\TarefaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Editar os campos da tarefa pela porta do agente — o `TarefaController::update`.
 *
 * PARCIAL, como `remarcar_compromisso`: o formulário manda a tarefa inteira a
 * cada Salvar, e quem fala "muda o prazo da #64 para sexta" diz só o prazo.
 * Campo que não vem fica como está. O que quem não triaga não decide —
 * prioridade, responsável e, fora das próprias tarefas, o prazo — continua
 * não decidindo por aqui: a regra é a do `TarefaService`, e a resposta diz o
 * que ficou como estava.
 */
class EditarTarefa extends Ferramenta
{
    use RelatoDoBug;

    private const SEM_VALOR = ['', 'sem', 'nenhum', 'nenhuma', 'ninguém', 'ninguem', 'remover'];

    protected string $name = 'editar_tarefa';

    protected string $title = 'Editar tarefa';

    protected string $description = 'Altera os campos de uma tarefa: título, resumo, tipo, sistema, responsável, prioridade, prazo ou o relato do bug (quem, quando). Informe só o que muda. Para limpar o responsável ou o prazo, passe "nenhum". Dar ou tirar o responsável move a tarefa entre Aberta e Backlog. Quem não faz triagem não muda prioridade nem responsável — a resposta diz o que ficou como estava. Para mudar de etapa use mover_tarefa.';

    // A rota de editar é `permissao:tarefas` num PUT, que o middleware lê como `editar`.
    protected array $permissao = ['tarefas', 'editar'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tarefa' => $schema->string()->required()->description('O código, como "#128".'),
            'titulo' => $schema->string()->max(255)->description('Novo título.'),
            'resumo' => $schema->string()->max(500)->description('Novo resumo (até 500 caracteres). Vazio apaga.'),
            'tipo' => $schema->string()->enum(array_keys(Tarefa::TIPOS))->description('desenvolvimento, bug ou operacional. Virar bug exige quem e quando (os já gravados valem).'),
            'sistema' => $schema->string()->description('Nome do sistema (ver referencias), ou "nenhum".'),
            'responsavel' => $schema->string()->description('Nome de quem vai fazer, "eu", ou "nenhum" para devolver à fila.'),
            'prioridade' => $schema->string()->enum(array_keys(Tarefa::PRIORIDADES))->description('baixa, media, alta, critica ou nao_definida.'),
            'prazo' => $schema->string()->description('Data de entrega, AAAA-MM-DD, ou "nenhum" para tirar.'),
            ...self::esquemaDoRelato($schema),
        ];
    }

    protected function executar(Request $request, User $usuario): Response
    {
        $entrada = $request->validate([
            'tarefa' => 'required|string|max:20',
            'titulo' => 'sometimes|required|string|max:255',
            'resumo' => 'sometimes|nullable|string|max:500',
            'tipo' => 'sometimes|required|in:'.implode(',', array_keys(Tarefa::TIPOS)),
            'sistema' => 'sometimes|nullable|string|max:255',
            'responsavel' => 'sometimes|nullable|string|max:255',
            'prioridade' => 'sometimes|required|in:'.implode(',', array_keys(Tarefa::PRIORIDADES)),
            'prazo' => 'sometimes|nullable|string|max:20',
            ...self::regrasDoRelato(),
        ]);

        $tarefa = $this->tarefaPeloCodigo($entrada['tarefa']);

        if (! $tarefa) {
            return Response::error('Não há tarefa '.$entrada['tarefa'].'.');
        }

        if ($recusa = self::relatoForaDoBug($entrada, $entrada['tipo'] ?? $tarefa->tipo)) {
            return Response::error($recusa);
        }

        $dados = array_intersect_key($entrada, array_flip(['titulo', 'tipo', 'prioridade']))
            + self::relatoDoEnvio($entrada);

        if (array_key_exists('resumo', $entrada)) {
            $dados['resumo'] = filled($entrada['resumo']) ? $entrada['resumo'] : null;
        }

        if (array_key_exists('sistema', $entrada)) {
            $dados['sistema_id'] = null;

            if (! $this->semValor($entrada['sistema'])) {
                $sistema = $this->sistema($entrada['sistema']);

                if (is_string($sistema)) {
                    return Response::error($sistema);
                }

                $dados['sistema_id'] = $sistema->id;
            }
        }

        if (array_key_exists('responsavel', $entrada)) {
            $dados['responsavel_id'] = null;

            if (! $this->semValor($entrada['responsavel'])) {
                $pessoa = $this->pessoa($entrada['responsavel'], $usuario);

                if (is_string($pessoa)) {
                    return Response::error($pessoa);
                }

                $dados['responsavel_id'] = $pessoa->id;
            }
        }

        if (array_key_exists('prazo', $entrada)) {
            $dados['prazo'] = null;

            if (! $this->semValor($entrada['prazo'])) {
                if (strtotime((string) $entrada['prazo']) === false) {
                    return Response::error('Não entendi o prazo "'.$entrada['prazo'].'". Use AAAA-MM-DD ou "nenhum".');
                }

                $dados['prazo'] = date('Y-m-d', strtotime((string) $entrada['prazo']));
            }
        }

        if ($dados === []) {
            return Response::error('Diga o que muda: título, resumo, tipo, sistema, responsável, prioridade, prazo ou o relato do bug.');
        }

        $etapaNova = app(TarefaService::class)->atualizar($tarefa, $dados, $usuario);

        $tarefa->refresh()->load(['responsavel', 'sistema', 'perguntaPara']);

        // O que foi pedido e NÃO ficou — a régua de quem não triaga, dita. A
        // tela esconde esses campos; o agente não tem tela.
        $ficaram = array_keys(array_filter([
            'prioridade' => array_key_exists('prioridade', $dados) && $tarefa->prioridade !== $dados['prioridade'],
            'responsável' => array_key_exists('responsavel_id', $dados) && $tarefa->responsavel_id !== $dados['responsavel_id'],
            'prazo' => array_key_exists('prazo', $dados) && $tarefa->prazo?->toDateString() !== $dados['prazo'],
        ]));

        return Response::text(implode(' ', array_filter([
            'Tarefa '.$tarefa->codigo().' atualizada.',
            $etapaNova ? 'Movida para '.Tarefa::rotuloDaEtapa($etapaNova).'.' : null,
            $ficaram ? 'Ficou como estava: '.implode(', ', $ficaram).' — só quem faz triagem (ou, no prazo, o responsável) define.' : null,
        ]))."\n".$this->linhaDaTarefa($tarefa));
    }

    private function semValor(?string $texto): bool
    {
        return in_array(mb_strtolower(trim((string) $texto)), self::SEM_VALOR, true);
    }
}
