<?php

namespace App\Mcp\Tools;

use App\Models\Compromisso;
use App\Models\User;
use App\Services\AgendaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * A porta do agente para a agenda — a mesma do `CompromissoController::store`,
 * com nomes no lugar de ids e quem comanda entrando como participante: uma
 * reunião marcada por alguém que não está nela é um lembrete para os outros,
 * e não é isso que "marca reunião com a Ana" quer dizer.
 */
class MarcarCompromisso extends Ferramenta
{
    protected string $name = 'marcar_compromisso';

    protected string $title = 'Marcar compromisso';

    protected string $description = 'Marca um compromisso na agenda do time, em seu nome, e avisa os participantes. Você entra como participante automaticamente. Informe a duração em horas (padrão 1) ou o término explícito. A resposta avisa se alguém já tem outro compromisso no horário.';

    protected array $permissao = ['agenda', 'incluir'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'titulo' => $schema->string()->required()->max(255)->description('O assunto.'),
            'data' => $schema->string()->required()->description('AAAA-MM-DD.'),
            'hora' => $schema->string()->required()->description('Início, HH:MM.'),
            'duracao_horas' => $schema->number()->description('Duração em horas (padrão 1; mínimo 0,25). Ignorada se hora_fim vier.'),
            'hora_fim' => $schema->string()->description('Término, HH:MM, quando preferir dizer o fim em vez da duração.'),
            'data_fim' => $schema->string()->description('Só se o término cair em outro dia, AAAA-MM-DD.'),
            'descricao' => $schema->string()->max(2000)->description('A pauta.'),
            'categoria' => $schema->string()->enum(array_keys(Compromisso::CATEGORIAS))
                ->description('interna (padrão), cliente, desenvolvimento, deploy, externo ou foco.'),
            'participantes' => $schema->array()->items($schema->string())
                ->description('Nomes das outras pessoas. Você já entra.'),
            'tarefa' => $schema->string()->description('Código da tarefa a que a reunião se refere ("#128"), se houver.'),
            'sistema' => $schema->string()
                ->description('Na categoria deploy: o sistema da janela de manutenção (ver referencias). Em outra categoria é ignorado.'),
        ];
    }

    protected function executar(Request $request, User $usuario): Response
    {
        $ids = [$usuario->id];

        foreach ($request->get('participantes') ?? [] as $nome) {
            $pessoa = $this->pessoa((string) $nome, $usuario);

            if (is_string($pessoa)) {
                return Response::error($pessoa);
            }

            $ids[] = $pessoa->id;
        }

        $tarefaId = null;

        if (filled($request->get('tarefa'))) {
            $tarefa = $this->tarefaPeloCodigo($request->get('tarefa'));

            if (! $tarefa) {
                return Response::error('Não há tarefa '.$request->get('tarefa').'.');
            }

            $tarefaId = $tarefa->id;
        }

        $sistemaId = null;

        // Só a janela de manutenção é de sistema — a mesma regra do modal da
        // Agenda, que só oferece o campo nessa categoria.
        if (filled($request->get('sistema')) && $request->get('categoria') === 'deploy') {
            $sistema = $this->sistema((string) $request->get('sistema'));

            if (is_string($sistema)) {
                return Response::error($sistema);
            }

            $sistemaId = $sistema->id;
        }

        // Os nomes resolvidos entram no lugar dos digitados, e o resto passa
        // pelas MESMAS regras do formulário (`regrasDoCompromisso`): a
        // ferramenta não valida por conta própria.
        $dados = $request->merge([
            'participantes' => array_values(array_unique($ids)),
            'tarefa_id' => $tarefaId,
            'sistema_id' => $sistemaId,
            'duracao_modo' => blank($request->get('hora_fim')),
        ])->validate(AgendaService::regrasDoCompromisso());

        $agenda = app(AgendaService::class);
        $compromisso = $agenda->marcar($dados, $usuario);
        $compromisso->load('participantes');

        $conflitos = $agenda->conflitos($compromisso->comecaEm(), $compromisso->terminaEm(), $ids, $compromisso->id);

        $texto = 'Compromisso #'.$compromisso->id.' marcado para '.$compromisso->comecaEm()->format('d/m/Y').', '
            .$compromisso->intervalo().($compromisso->viraODia() ? ' (termina no dia seguinte)' : '')
            .' com '.$compromisso->participantes->pluck('name')->implode(', ').'.';

        if ($conflitos !== []) {
            $texto .= "\nAtenção: ".$compromisso->participantes->whereIn('id', $conflitos)->pluck('name')->implode(', ')
                .' já tem outro compromisso nesse horário.';
        }

        return Response::text($texto);
    }
}
