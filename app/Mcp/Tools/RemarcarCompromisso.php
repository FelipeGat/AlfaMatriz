<?php

namespace App\Mcp\Tools;

use App\Models\Compromisso;
use App\Models\User;
use App\Services\AgendaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Alterar um compromisso pela porta do agente — o `CompromissoController::update`.
 *
 * A diferença para a tela é uma só: aqui a alteração é PARCIAL. O formulário
 * manda o compromisso inteiro de volta a cada Salvar; quem fala "passa a
 * reunião para as 15h" diz só o que muda. O que não vier fica como está, e o
 * conjunto completo passa pelas MESMAS regras do formulário antes de gravar.
 */
class RemarcarCompromisso extends Ferramenta
{
    protected string $name = 'remarcar_compromisso';

    protected string $title = 'Alterar compromisso';

    protected string $description = 'Altera um compromisso já marcado: horário, data, duração, título, pauta, categoria, participantes ou tarefa vinculada. Informe só o que muda; o resto fica como está. Só quem marcou, ou quem faz triagem, pode. Avisa os participantes e, se o início mudar, rearma o lembrete.';

    protected array $permissao = ['agenda', 'editar'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'compromisso' => $schema->string()->required()->description('O número, como "#7" ou 7.'),
            'titulo' => $schema->string()->max(255)->description('Novo assunto.'),
            'data' => $schema->string()->description('Nova data, AAAA-MM-DD.'),
            'hora' => $schema->string()->description('Novo início, HH:MM.'),
            'duracao_horas' => $schema->number()->description('Nova duração em horas. Sem ela e sem hora_fim, a duração atual é mantida.'),
            'hora_fim' => $schema->string()->description('Novo término, HH:MM, quando preferir dizer o fim em vez da duração.'),
            'data_fim' => $schema->string()->description('Só se o término cair em outro dia, AAAA-MM-DD.'),
            'descricao' => $schema->string()->max(2000)->description('Nova pauta.'),
            'categoria' => $schema->string()->enum(array_keys(Compromisso::CATEGORIAS))
                ->description('interna, cliente, desenvolvimento, deploy, externo ou foco.'),
            'participantes' => $schema->array()->items($schema->string())
                ->description('A lista COMPLETA das outras pessoas, pelo nome — substitui a atual. Você continua nela.'),
            'tarefa' => $schema->string()->description('Código da tarefa a vincular ("#128").'),
        ];
    }

    protected function executar(Request $request, User $usuario): Response
    {
        $compromisso = $this->compromissoPeloNumero((string) $request->get('compromisso'));

        if (! $compromisso) {
            return Response::error('Não há compromisso '.$request->get('compromisso').'. Veja os números em ver_agenda.');
        }

        // Antes de resolver nomes e horários: quem não pode mexer não precisa
        // ouvir que o participante não existe. É a ordem da tela.
        if (! $compromisso->podeSerEditadoPor($usuario)) {
            return Response::error(AgendaService::RECUSA_AO_ALTERAR);
        }

        $participantes = $compromisso->participantes->pluck('id')->all();

        if ($request->get('participantes') !== null) {
            $participantes = [$usuario->id];

            foreach ($request->get('participantes') as $nome) {
                $pessoa = $this->pessoa((string) $nome, $usuario);

                if (is_string($pessoa)) {
                    return Response::error($pessoa);
                }

                $participantes[] = $pessoa->id;
            }
        }

        $tarefaId = $compromisso->tarefa_id;

        if (filled($request->get('tarefa'))) {
            $tarefa = $this->tarefaPeloCodigo($request->get('tarefa'));

            if (! $tarefa) {
                return Response::error('Não há tarefa '.$request->get('tarefa').'.');
            }

            $tarefaId = $tarefa->id;
        }

        $dados = $request->merge(
            $this->intervalo($compromisso, $request) + [
                'titulo' => $request->get('titulo') ?? $compromisso->titulo,
                'descricao' => $request->get('descricao') ?? $compromisso->descricao,
                'categoria' => $request->get('categoria') ?? $compromisso->categoria,
                'participantes' => array_values(array_unique($participantes)),
                'tarefa_id' => $tarefaId,
            ]
        )->validate(AgendaService::regrasDoCompromisso());

        $agenda = app(AgendaService::class);
        $compromisso = $agenda->remarcar($compromisso, $dados, $usuario)->load('participantes');

        $conflitos = $agenda->conflitos(
            $compromisso->comecaEm(), $compromisso->terminaEm(), $compromisso->participantes->pluck('id')->all(), $compromisso->id,
        );

        return Response::text(
            "Compromisso alterado.\n".$this->fichaDoCompromisso($compromisso, $usuario)
            .($conflitos !== []
                ? "\nAtenção: ".$compromisso->participantes->whereIn('id', $conflitos)->pluck('name')->implode(', ')
                    .' já tem outro compromisso nesse horário.'
                : '')
        );
    }

    /**
     * O intervalo que resulta do que veio com o que já havia.
     *
     * Quem diz o fim (`hora_fim`) quer aquele fim; quem diz a duração quer
     * aquela duração. Quem só muda o INÍCIO não disse nada sobre o tamanho da
     * reunião, e o tamanho se mantém: "passa para as 15h" numa reunião de uma
     * hora termina às 16h, e não no horário antigo — que podia até ficar
     * antes do novo começo.
     *
     * @return array<string, mixed>
     */
    private function intervalo(Compromisso $compromisso, Request $request): array
    {
        $data = $request->get('data') ?? $compromisso->comecaEm()->toDateString();
        $hora = $request->get('hora') ?? $compromisso->comecaEm()->format('H:i');

        if (filled($request->get('hora_fim')) || filled($request->get('data_fim'))) {
            return [
                'data' => $data,
                'hora' => $hora,
                'duracao_modo' => false,
                'data_fim' => $request->get('data_fim') ?? $data,
                'hora_fim' => $request->get('hora_fim') ?? $compromisso->terminaEm()->format('H:i'),
            ];
        }

        $horas = $request->get('duracao_horas')
            ?? round($compromisso->comecaEm()->diffInMinutes($compromisso->terminaEm()) / 60, 2);

        // No modo de término livre, a duração mantida vira um término novo —
        // o compromisso continua sendo "de término livre", como foi marcado.
        if (! $compromisso->duracao_modo && $request->get('duracao_horas') === null) {
            $termino = Carbon::parse($data.' '.$hora)->addMinutes((int) round($horas * 60));

            return [
                'data' => $data,
                'hora' => $hora,
                'duracao_modo' => false,
                'data_fim' => $termino->toDateString(),
                'hora_fim' => $termino->format('H:i'),
            ];
        }

        return ['data' => $data, 'hora' => $hora, 'duracao_modo' => true, 'duracao_horas' => $horas];
    }
}
