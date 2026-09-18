<?php

namespace App\Http\Controllers;

use App\Models\Compromisso;
use App\Models\Notificacao;
use App\Models\Tarefa;
use App\Services\AgendaService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Criar, editar e desmarcar compromisso — e as duas conversões entre agenda e
 * quadro.
 *
 * As conversões existem nos DOIS sentidos, de propósito, porque a combinação
 * nasce dos dois lados: "isto que marcamos vira trabalho" (Virar tarefa) e
 * "este trabalho precisa de uma hora com fulano" (Reservar tempo). Um caminho
 * só obrigaria metade do time a cadastrar duas vezes o que é a mesma coisa.
 *
 * O que deliberadamente NÃO se automatiza: chegar a hora do compromisso não
 * move a tarefa para Em andamento. Reunião acontecer não é trabalho começar —
 * a call pode ser só alinhamento, e a pessoa pode nem ter aberto o editor. A
 * automação que existe no mercado é a inversa, e é ela que "Reservar tempo"
 * implementa.
 */
class CompromissoController extends Controller
{
    public function __construct(private readonly AgendaService $agenda) {}

    public function store(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        $dados = $this->validar($request);

        $compromisso = DB::transaction(function () use ($dados, $request) {
            $compromisso = Compromisso::create(
                $this->camposDoIntervalo($dados) + [
                    'titulo' => $dados['titulo'],
                    'descricao' => $dados['descricao'] ?? null,
                    'criado_por_id' => $request->user()->id,
                    'tarefa_id' => $dados['tarefa_id'] ?? null,
                ]
            );

            $compromisso->sincronizarParticipantes($dados['participantes'] ?? []);
            $this->avisarParticipantes($compromisso, $request->user()->id, 'marcou');

            return $compromisso;
        });

        return $this->voltar($request, 'Compromisso marcado para '
            .Carbon::parse($compromisso->data)->translatedFormat('d/m').', '.$compromisso->intervalo().'.');
    }

    public function update(Request $request, Compromisso $compromisso)
    {
        $this->bloquearVisaoDaMatriz();

        if (! $compromisso->podeSerEditadoPor($request->user())) {
            return $this->recusar($request, 'Só quem marcou este compromisso — ou quem faz triagem — pode alterá-lo.');
        }

        $dados = $this->validar($request);

        DB::transaction(function () use ($compromisso, $dados, $request) {
            $campos = $this->camposDoIntervalo($dados);

            // Remarcar rearma o lembrete: se o início mudou, quem foi avisado do
            // horário antigo precisa do novo, e o `lembrete_enviado_em` volta a
            // null para o comando avisar de novo. Editar só o título ou a pauta
            // não mexe nisso — o início é que manda.
            $comecoMudou = $campos['data'] !== Carbon::parse($compromisso->data)->toDateString()
                || $campos['hora'] !== Carbon::parse($compromisso->hora)->format('H:i');

            $compromisso->update(
                $campos + [
                    'titulo' => $dados['titulo'],
                    'descricao' => $dados['descricao'] ?? null,
                    'tarefa_id' => $dados['tarefa_id'] ?? null,
                ] + ($comecoMudou ? ['lembrete_enviado_em' => null] : [])
            );

            // Depois do `update`: a data que os participantes repetem é a nova,
            // e sincronizar antes gravaria a carga no dia antigo.
            $compromisso->sincronizarParticipantes($dados['participantes'] ?? []);
            $this->avisarParticipantes($compromisso, $request->user()->id, 'remarcou');
        });

        return $this->voltar($request, 'Compromisso atualizado.');
    }

    public function destroy(Request $request, Compromisso $compromisso)
    {
        $this->bloquearVisaoDaMatriz();

        if (! $compromisso->podeSerEditadoPor($request->user())) {
            return $this->recusar($request, 'Só quem marcou este compromisso — ou quem faz triagem — pode desmarcá-lo.');
        }

        // O aviso sai ANTES da exclusão: depois dela não há mais de onde ler
        // título, data e participantes para escrever a frase.
        $this->avisarParticipantes($compromisso, $request->user()->id, 'desmarcou');
        $compromisso->delete();

        return $this->voltar($request, 'Compromisso desmarcado.');
    }

    /**
     * Virar tarefa — do compromisso para o quadro.
     *
     * A tarefa nasce com PRAZO na data do compromisso e já vinculada a ele: o
     * que se combinou numa reunião tem a data da reunião como referência, e
     * deixar o prazo em branco obrigaria a reabrir a tarefa para digitar a data
     * que estava na tela.
     *
     * Cai na fila de triagem quando quem converte não triaga, exatamente como
     * toda criação de tarefa (`semTriagemDeQuemNaoTriaga` no quadro): a porta
     * nova não pode ser o desvio que entrega card priorizado por quem não pode
     * priorizar.
     *
     * Recusa o segundo clique porque o vínculo é de um para um: um compromisso
     * que já virou tarefa geraria duplicata a cada nova conversão, e a tela
     * esconde o botão — mas a rota é quem garante.
     */
    public function virarTarefa(Request $request, Compromisso $compromisso)
    {
        $this->bloquearVisaoDaMatriz();

        if ($compromisso->tarefa_id !== null) {
            return $this->recusar($request, 'Este compromisso já está vinculado a uma tarefa.');
        }

        $dados = $request->validate([
            'sistema_id' => 'nullable|exists:sistemas,id',
            'tipo' => 'nullable|in:'.implode(',', array_keys(Tarefa::TIPOS)),
        ]);

        $triaga = $request->user()?->podeTriarTarefas() ?? false;

        $tarefa = DB::transaction(function () use ($compromisso, $dados, $request, $triaga) {
            $tarefa = Tarefa::create([
                'titulo' => $compromisso->titulo,
                'resumo' => $compromisso->descricao,
                'tipo' => $dados['tipo'] ?? 'desenvolvimento',
                'sistema_id' => $dados['sistema_id'] ?? null,
                'criado_por_id' => $request->user()->id,
                'prazo' => Carbon::parse($compromisso->data)->toDateString(),
                // A mesma régua da criação no quadro: quem não triaga não
                // escolhe prioridade nem dono, e o card cai na fila.
                'prioridade' => $triaga ? 'media' : 'nao_definida',
                'responsavel_id' => $triaga ? $request->user()->id : null,
            ]);

            $compromisso->update(['tarefa_id' => $tarefa->id]);

            return $tarefa;
        });

        return $this->voltar($request, 'Tarefa '.$tarefa->codigo().' criada a partir do compromisso.');
    }

    /**
     * Reservar tempo — da tarefa para a agenda.
     *
     * Devolve o compromisso PRÉ-PREENCHIDO em vez de já o criar: título, data,
     * participantes e vínculo saem da tarefa, mas a HORA não sai de lugar
     * nenhum — ela é a única coisa que só quem está marcando sabe. Criar na
     * hora do clique poria uma reunião na agenda de duas pessoas num horário
     * que ninguém escolheu.
     *
     * Os participantes são o responsável pela tarefa E quem está reservando: a
     * reserva é justamente o pedido de uma hora entre os dois, e uma reunião
     * com um participante só é um lembrete, não um compromisso.
     */
    public function reservarTempo(Request $request, Tarefa $tarefa)
    {
        $this->bloquearVisaoDaMatriz();

        $participantes = collect([$tarefa->responsavel_id, $request->user()->id])
            ->filter()
            ->unique()
            ->values();

        $data = $tarefa->prazo
            ? Carbon::parse($tarefa->prazo)->toDateString()
            : now()->toDateString();

        return response()->json([
            'titulo' => $tarefa->titulo,
            'descricao' => null,
            'data' => $data,
            'tarefa_id' => $tarefa->id,
            'participantes' => $participantes,
            'carga' => $this->agenda->cargaNoDia(Carbon::parse($data), $participantes->all()),
        ]);
    }

    /**
     * Quem, entre os candidatos, já tem compromisso sobreposto a este intervalo.
     *
     * O modal pergunta a cada mudança de horário ou de participante, e é o que
     * pinta o chip de âmbar com "· conflito". A resposta vem do servidor porque
     * a agenda dos outros não está na tela: o navegador só conhece o que a
     * visão atual carregou, e o conflito costuma estar exatamente no dia que
     * não está aberto.
     */
    public function conflitos(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        $dados = $request->validate([
            'data' => 'required|date',
            'hora' => 'required|date_format:H:i',
            'data_fim' => 'required|date',
            'hora_fim' => 'required|date_format:H:i',
            'participantes' => 'nullable|array',
            'participantes.*' => 'integer',
            'ignorar' => 'nullable|integer',
        ]);

        $inicio = Carbon::parse($dados['data'].' '.$dados['hora']);
        $fim = Carbon::parse($dados['data_fim'].' '.$dados['hora_fim']);
        $candidatos = array_values(array_filter($dados['participantes'] ?? []));

        return response()->json([
            'conflitos' => $this->agenda->conflitos($inicio, $fim, $candidatos, $dados['ignorar'] ?? null),
            'carga' => $this->agenda->cargaNoDia($inicio->copy()->startOfDay(), $candidatos),
        ]);
    }

    /**
     * As regras do formulário — e a única validação que não é de formato.
     *
     * "O término precisa ser depois do início" é checada DEPOIS de o intervalo
     * ser montado, e não por um `after:` no campo, porque no modo Duração o
     * término não é um campo: ele é calculado. Um `after:hora` recusaria o que
     * o usuário nem digitou, e deixaria passar a meia-noite — que é o caso em
     * que o término é, legitimamente, uma hora "menor" que o início.
     */
    private function validar(Request $request): array
    {
        $dados = $request->validate([
            'titulo' => 'required|string|max:255',
            'descricao' => 'nullable|string|max:2000',
            'data' => 'required|date',
            'hora' => 'required|date_format:H:i',

            // `duracao_modo` chega como booleano do botão-pílula. Quando é
            // verdadeiro, as horas mandam e o término é ignorado; quando é
            // falso, é o contrário. Por isso os dois grupos são `nullable`
            // aqui e cobrados em `camposDoIntervalo`, que sabe qual é qual.
            'duracao_modo' => 'nullable|boolean',
            'duracao_horas' => 'nullable|numeric|min:'.Compromisso::DURACAO_MINIMA.'|max:24',
            'data_fim' => 'nullable|date',
            'hora_fim' => 'nullable|date_format:H:i',

            'tarefa_id' => 'nullable|exists:tarefas,id',
            'participantes' => 'nullable|array',
            'participantes.*' => 'exists:users,id',
        ]);

        $intervalo = $this->camposDoIntervalo($dados);

        $inicio = Carbon::parse($intervalo['data'].' '.$intervalo['hora']);
        $fim = Carbon::parse($intervalo['data_fim'].' '.$intervalo['hora_fim']);

        if ($fim->lte($inicio)) {
            abort(response()->json(['erro' => 'O término precisa ser depois do início.'], 422));
        }

        return $dados;
    }

    /**
     * Resolve os quatro campos do intervalo a partir do modo escolhido.
     *
     * Um lugar só, porque os dois modos precisam produzir as MESMAS quatro
     * colunas: o banco guarda sempre `data`/`hora`/`data_fim`/`hora_fim`, e é
     * `duracao_modo` que diz qual dos dois lados foi digitado. Espalhar essa
     * decisão entre `store` e `update` faria os dois divergirem na primeira
     * correção.
     *
     * @return array<string, mixed>
     */
    private function camposDoIntervalo(array $dados): array
    {
        $data = Carbon::parse($dados['data'])->toDateString();
        $modoDuracao = (bool) ($dados['duracao_modo'] ?? true);

        if ($modoDuracao) {
            $horas = (float) ($dados['duracao_horas'] ?? 1);
            $termino = Compromisso::terminoPorDuracao($data, $dados['hora'], $horas);

            return [
                'data' => $data,
                'hora' => $dados['hora'],
                'data_fim' => $termino->toDateString(),
                'hora_fim' => $termino->format('H:i'),
                'duracao_modo' => true,
                'duracao_horas' => $horas,
            ];
        }

        return [
            'data' => $data,
            'hora' => $dados['hora'],
            // Término livre sem data cai no mesmo dia: é o que quem digita só a
            // hora quer dizer, e exigir a data repetiria o campo em 95% dos
            // casos para cobrir a virada de meia-noite.
            'data_fim' => Carbon::parse($dados['data_fim'] ?? $data)->toDateString(),
            'hora_fim' => $dados['hora_fim'] ?? $dados['hora'],
            'duracao_modo' => false,
            // Nulo de propósito: no modo livre não houve duração digitada, e
            // gravar a subtração aqui reintroduziria a ambiguidade que a
            // coluna existe para resolver (ver a migração).
            'duracao_horas' => null,
        ];
    }

    /**
     * Avisa quem participa — menos quem mexeu.
     *
     * Compromisso é EVENTO: aconteceu num instante e tem destinatário certo, que
     * é a definição do que o sino guarda. As condições da Agenda — prazo perto
     * sem reunião, tarefa travada — são outra coisa e não passam por aqui; elas
     * são recalculadas e pintadas na própria tela, porque valem enquanto
     * durarem e gravá-las viraria uma linha nova por dia repetindo o mesmo
     * aviso (ver `Notificacao`).
     */
    private function avisarParticipantes(Compromisso $compromisso, int $autorId, string $verbo): void
    {
        $quando = Carbon::parse($compromisso->data)->translatedFormat('d/m').' · '.$compromisso->intervalo();

        foreach ($compromisso->participantes as $participante) {
            Notificacao::avisar($participante->id, $autorId, [
                'tipo' => 'compromisso',
                'nivel' => 'neutro',
                'icone' => 'calendar',
                'titulo' => 'Alguém '.$verbo.' "'.$compromisso->titulo.'"',
                'meta' => $quando,
                'rota' => route('agenda.index', ['em' => Carbon::parse($compromisso->data)->toDateString()]),
                'tarefa_id' => $compromisso->tarefa_id,
            ]);
        }
    }

    private function recusar(Request $request, string $mensagem)
    {
        if ($request->expectsJson()) {
            return response()->json(['erro' => $mensagem], 422);
        }

        return redirect()->back(fallback: route('agenda.index'))->with('erro', $mensagem);
    }

    private function voltar(Request $request, string $mensagem)
    {
        if ($request->expectsJson()) {
            return response()->json(['status' => $mensagem]);
        }

        return redirect()->back(fallback: route('agenda.index'))->with('status', $mensagem);
    }
}
