<?php

namespace App\Http\Controllers;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\TarefaComentario;
use App\Models\TarefaItem;
use App\Models\TarefaRelatorioTeste;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class TarefaController extends Controller
{
    public function index(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        // O quadro é o trabalho EM CURSO: concluída e cancelada não têm coluna
        // (AC-082, AC-096). Sete colunas não cabiam na tela e as duas terminais
        // eram as de menor valor no dia a dia — encerrou, sai do quadro e passa
        // a viver no histórico (`historico()`), de onde também se reabre.
        // Isso aposenta o antigo recorte de 30 dias: não há mais o que recortar.
        $emCurso = collect(Tarefa::STATUS)->reject(
            fn ($label, $status) => in_array($status, Tarefa::STATUS_TERMINAIS, true)
        );

        $filtros = $this->filtros($request);

        // `eventos` entra no eager load porque o card lê a etapa atual de cada
        // tarefa para o chip de tempo — sem isso é uma consulta por card.
        // `comentarios.autor` pelo mesmo motivo: o quadro já monta o modal de
        // cada card, e a conversa inteira é impressa dentro dele.
        // `perguntaPara` entra porque a tarja de pergunta nomeia quem deve a
        // resposta — sem ele, cada card com conversa aberta faz a própria
        // consulta pelo nome.
        $tarefas = Tarefa::with(['sistema', 'responsavel', 'eventos', 'comentarios.autor', 'itens', 'perguntaPara'])
            ->whereIn('status', $emCurso->keys())
            ->tap(fn ($q) => $this->aplicarFiltros($q, $filtros))
            ->orderByDesc('created_at')
            ->get();

        $colunas = $emCurso->mapWithKeys(fn ($label, $status) => [
            $status => $this->ordenarColuna($tarefas->where('status', $status)->values()),
        ]);

        // A contagem da coluna é a do RECORTE, não a do quadro inteiro: com
        // filtro ligado, um selo dizendo 12 sobre três cards visíveis mediria
        // outra coisa que não o que está na tela.
        $etapas = $emCurso->map(function ($label, $status) use ($colunas) {
            $daEtapa = $colunas[$status];

            // O WIP conta só o que ANDA: vaga ocupada por tarefa travada não é
            // trabalho em curso, e somá-la faria o limite acusar excesso
            // justamente quando o time está impedido de produzir.
            $andando = $daEtapa->reject->estaBloqueada()->count();
            $limite = Tarefa::LIMITE_DE_WIP[$status] ?? null;

            return [
                'chave' => $status,
                'label' => $label,
                'cor' => Tarefa::corDaEtapa($status),
                'quantidade' => $daEtapa->count(),
                'andando' => $andando,
                'limite' => $limite,
                'acimaDoLimite' => $limite !== null && $andando > $limite,
                // Quantas ainda não foram triadas. A coluna Aberta é a fila de
                // triagem, mas o número aparece onde houver: tarefa sem
                // prioridade no meio do fluxo é a que ninguém vai priorizar.
                'aguardandoTriagem' => $daEtapa->where('prioridade', 'nao_definida')->count(),
            ];
        })->values()->all();

        // Quantas tarefas o quadro teria sem filtro nenhum: é o denominador do
        // "X de Y" do cabeçalho, o aviso de que há trabalho fora do recorte.
        $totalNoQuadro = Tarefa::whereIn('status', $emCurso->keys())->count();

        // O contador da faixa de bloqueio. Ele mede o RECORTE, como os das
        // colunas: com filtro ligado, um número falando do quadro inteiro
        // apontaria para cards que não estão na tela.
        $totalBloqueadas = $tarefas->filter->estaBloqueada()->count();

        // Quantas esperam por VOCÊ. Diferente dos outros contadores, este mede
        // o quadro INTEIRO e não o recorte: ele é caixa de entrada, e uma caixa
        // de entrada que esconde mensagens porque há um filtro de sistema
        // ligado deixa de ser caixa de entrada. É também por isso que clicar
        // nele filtra em vez de rolar até o card.
        $esperandoVoce = Tarefa::whereIn('status', $emCurso->keys())
            ->esperandoRespostaDe(auth()->id())
            ->count();

        $raias = $this->raias($request, $tarefas, $emCurso);

        $chips = $this->chipsDoQuadro($emCurso, $filtros, $esperandoVoce);

        return view('tarefas.index', compact(
            'tarefas', 'colunas', 'etapas', 'filtros', 'totalNoQuadro', 'totalBloqueadas',
            'esperandoVoce', 'chips', 'raias',
        ) + $this->listasDeFiltro());
    }

    /**
     * Os chips do cabeçalho do quadro.
     *
     * Eles são o que sobrou das duas faixas verticais de solto: aquelas faziam
     * duas coisas ao mesmo tempo — receber o card arrastado e mostrar as
     * contagens — e gastavam 132px de largura para isso, numa tela de seis
     * colunas onde largura é o recurso escasso. O gesto foi para os botões do
     * card; a contagem virou cabeçalho, que já existia.
     *
     * Cada chip é também um filtro, e clicar no que já está ligado desliga: sem
     * isso a única saída de um recorte seria o "Limpar" lá dos filtros, longe
     * de onde a pessoa clicou.
     *
     * As contagens são do QUADRO INTEIRO, não do recorte. Elas são fila de
     * trabalho: uma fila que encolhe porque há um filtro de sistema ligado
     * deixa de responder "quanto falta".
     *
     * @param  Collection<string, string>  $emCurso
     * @param  array<string, string>  $filtros
     * @return list<array<string, string>>
     */
    private function chipsDoQuadro($emCurso, array $filtros, int $esperandoVoce): array
    {
        $noQuadro = fn () => Tarefa::whereIn('status', $emCurso->keys());

        $chips = [
            // Primeiro e em destaque: é a caixa de entrada da pessoa. Sem ele,
            // saber que há uma pergunta dependia de olhar a coluna certa.
            [
                'chave' => 'esperando_mim', 'total' => $esperandoVoce,
                'label' => $esperandoVoce.' p/ você', 'icone' => 'duvida', 'cor' => 'brand-text',
                'title' => 'Perguntas esperando resposta sua',
                'fundo' => 'rgb(var(--brand) / 0.12)', 'fundoAtivo' => 'rgb(var(--brand) / 0.26)',
                'borda' => 'rgb(var(--brand) / 0.45)',
            ],
            [
                'chave' => 'travadas', 'total' => $noQuadro()->whereNotNull('bloqueado_em')->count(),
                'label' => null, 'icone' => 'cadeado-fechado', 'cor' => 'warn',
                'title' => 'Tarefas travadas esperando alguém',
                'fundo' => 'var(--warn-tint)', 'fundoAtivo' => 'rgb(var(--warn) / 0.24)',
                'borda' => 'var(--warn-line)',
            ],
            [
                'chave' => 'prontas', 'total' => $noQuadro()->where('status', 'pronta_producao')->count(),
                'label' => null, 'icone' => 'seta-cima', 'cor' => 'good',
                'title' => 'Fila do admin: validadas no staging, esperando a tag subir',
                'fundo' => 'var(--good-tint)', 'fundoAtivo' => 'rgb(var(--good) / 0.24)',
                'borda' => 'var(--good-line)',
            ],
            // O único que não filtra o quadro: o que foi concluído hoje já saiu
            // dele. Ele leva ao Histórico, que é onde essas tarefas passaram a
            // viver — e por isso não tem estado "ligado".
            [
                'chave' => 'hoje', 'total' => $this->concluidasHoje(),
                'label' => null, 'icone' => 'check-circle', 'cor' => 'good',
                'title' => 'Foram para produção hoje · clique para abrir o histórico',
                'fundo' => 'var(--good-tint)', 'fundoAtivo' => 'var(--good-tint)',
                'borda' => 'var(--good-line)',
                'rota' => 'historico',
            ],
        ];

        $chips = array_map(function (array $chip) use ($filtros) {
            $ligado = ($filtros['situacao'] ?? '') === $chip['chave'];

            return [
                'label' => $chip['label'] ?? $chip['total'].' '.match ($chip['chave']) {
                    'travadas' => 'travadas',
                    'prontas' => 'p/ subir',
                    'hoje' => 'hoje',
                },
                'icone' => $chip['icone'],
                'cor' => $chip['cor'],
                'title' => $ligado ? 'Mostrando só este recorte — clique para ver o quadro inteiro' : $chip['title'],
                'fundo' => $ligado ? $chip['fundoAtivo'] : $chip['fundo'],
                'borda' => $chip['borda'],
                'total' => $chip['total'],
                'href' => ($chip['rota'] ?? null) === 'historico'
                    ? route('tarefas.historico')
                    : request()->fullUrlWithQuery(['situacao' => $ligado ? null : $chip['chave']]),
            ];
        }, $chips);

        // Os quatro aparecem SEMPRE, inclusive zerados.
        //
        // O protótipo esconde o chip em zero (`filter(k => !k.label.startsWith('0 '))`)
        // e a razão é boa: "0 travadas" permanente ensina a não ler a fila. Mas
        // esconder faz o cabeçalho mudar de forma conforme o dia, e quem abre o
        // quadro numa terça calma não descobre que os recortes existem — some
        // justamente a informação de que NÃO há nada travado, que é uma notícia.
        // Decisão do dono do produto, contra o protótipo, e declarada aqui para
        // quem vier depois não "consertar" de volta.
        return array_values($chips);
    }

    /**
     * Quantas foram para produção HOJE.
     *
     * "Hoje" e não "nas últimas 24h": o número é lido junto com a data do dia,
     * e uma janela deslizante faria o mesmo chip dizer valores diferentes de
     * manhã e à tarde sem nada ter acontecido.
     */
    private function concluidasHoje(): int
    {
        return Tarefa::where('status', 'concluida')
            ->whereHas('eventos', fn ($evento) => $evento
                ->where('para_status', 'concluida')
                ->whereDate('entrou_em', today()))
            ->count();
    }

    public function store(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'titulo' => 'required|string|max:255',
            // Sem regra aqui, o resumo digitado no modal era descartado em
            // silêncio: `validate()` devolve só o que validou, e o que não tem
            // regra não chega ao `create`. `max:255` acompanha a coluna, que é
            // `string` — e o `maxlength` do textarea.
            'resumo' => 'nullable|string|max:255',
            // `nullable` e não `required`: o tipo tem padrão no modelo, e um
            // envio sem ele (formulário antigo em cache, integração futura) vale
            // como tarefa de desenvolvimento em vez de virar erro de validação.
            'tipo' => 'nullable|in:'.implode(',', array_keys(Tarefa::TIPOS)),
            'sistema_id' => 'nullable|exists:sistemas,id',
            'responsavel_id' => 'nullable|exists:users,id',
            // Nunca obrigatória. Ela falta em dois envios legítimos: o de quem
            // não triaga, que não tem o campo, e o da criação rápida do pé da
            // coluna, que manda só o título. Exigi-la aqui faria a tela
            // funcionar e a rota dizer não.
            'prioridade' => 'nullable|in:'.implode(',', array_keys(Tarefa::PRIORIDADES)),
            // A criação rápida do pé da coluna DECLARA onde nasce. Sem isso, o
            // `booted` decidia pela presença de responsável e o card criado no
            // Backlog aparecia em Aberta — o controle prometia um lugar e
            // entregava outro. Só as duas colunas de fila são destino válido:
            // criar direto em Em revisão pularia o trabalho.
            'status' => 'nullable|in:aberta,backlog',
        ]);

        // O padrão é resolvido AQUI, e não só no modelo, por causa da linha
        // abaixo: a busca por reenvio compara o formulário inteiro, e um `tipo`
        // nulo viraria `tipo IS NULL` — que não casa com a linha gravada, onde
        // ele é 'desenvolvimento'. O duplo clique voltaria a criar duas tarefas.
        $data['tipo'] ??= 'desenvolvimento';
        $data['prioridade'] ??= 'media';
        $data['criado_por_id'] = auth()->id();

        $data = $this->semTriagemDeQuemNaoTriaga($data);

        // Mesma rede do comentário (AC-137): aqui o clique duplo custa mais
        // caro, porque a segunda tarefa não é uma linha repetida na conversa —
        // é um card a mais no quadro, que alguém vai ter de cancelar na mão.
        if (! $this->reenvioDaMesmaTarefa($data)) {
            Tarefa::create($data);
        }

        return redirect()->route('tarefas.index')->with('status', 'Tarefa criada.');
    }

    /**
     * As raias do quadro: o mesmo quadro, quebrado em faixas.
     *
     * Uma raia não é um filtro — o filtro esconde, a raia mostra tudo separado.
     * A pergunta que ela responde é de distribuição ("quem está com o quê",
     * "onde cada sistema está travado"), e essa pergunta some quando se olha
     * coluna por coluna com todo mundo misturado.
     *
     * `nenhuma` devolve uma faixa só, sem título: é o quadro de sempre, e a
     * tela não precisa perguntar se há raias para saber o que desenhar dentro
     * de cada coluna.
     *
     * @param  Collection<int, Tarefa>  $tarefas
     * @param  Collection<string, string>  $emCurso
     * @return array{modo: string, faixas: array<int, array<string, mixed>>}
     */
    private function raias(Request $request, $tarefas, $emCurso): array
    {
        $modo = $this->textoDaQuery($request, 'raias');
        $modo = in_array($modo, ['responsavel', 'sistema'], true) ? $modo : 'nenhuma';

        if ($modo === 'nenhuma') {
            return ['modo' => $modo, 'faixas' => [[
                'chave' => 'todas',
                'titulo' => null,
                'colunas' => $emCurso->mapWithKeys(fn ($label, $status) => [
                    $status => $this->ordenarColuna($tarefas->where('status', $status)->values()),
                ]),
                'sobrecarga' => false,
            ]]];
        }

        // "Sem responsável"/"Sem sistema" é raia de verdade, e a ÚLTIMA: ela é
        // uma pergunta em aberto ("de quem isto vai ser?"), não um grupo — e no
        // meio da lista ela se leria como só mais uma pessoa.
        $agrupadas = $tarefas->groupBy(fn (Tarefa $tarefa) => $modo === 'responsavel'
            ? ($tarefa->responsavel?->name ?? "\u{FFFF}Sem responsável")
            : ($tarefa->sistema?->nome ?? "\u{FFFF}Sem sistema"))
            ->sortKeys();

        $faixas = $agrupadas->map(function ($daFaixa, $titulo) use ($emCurso, $modo) {
            $colunas = $emCurso->mapWithKeys(fn ($label, $status) => [
                $status => $this->ordenarColuna($daFaixa->where('status', $status)->values()),
            ]);

            return [
                'chave' => $titulo,
                'titulo' => ltrim($titulo, "\u{FFFF}"),
                'colunas' => $colunas,
                // Mais de duas em andamento é o selo de quem pegou trabalho
                // demais. Vale só nas raias de pessoa: sistema com cinco
                // tarefas andando é projeto grande, não sobrecarga de ninguém.
                'sobrecarga' => $modo === 'responsavel'
                    && $colunas['em_desenvolvimento']->reject->estaBloqueada()->count() > 2,
            ];
        })->values()->all();

        return ['modo' => $modo, 'faixas' => $faixas];
    }

    /**
     * Tira do envio o que só a triagem decide.
     *
     * Esconder os campos na tela não é regra — é sugestão: a rota continua
     * aceitando `prioridade` e `responsavel_id` de qualquer envio, e um
     * formulário guardado, um "voltar" do navegador ou um POST à mão passariam
     * por cima da tela. A regra mora aqui.
     *
     * Não é erro de validação, é omissão silenciosa: quem não triaga não
     * mandou esses campos por má-fé, e recusar o cadastro inteiro por causa
     * deles transformaria uma capacidade que a pessoa não tem num obstáculo
     * para o trabalho que ela tem.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function semTriagemDeQuemNaoTriaga(array $dados, ?Tarefa $tarefa = null): array
    {
        if (auth()->user()?->podeTriarTarefas()) {
            return $dados;
        }

        // Na criação, a tarefa nasce sem dono e esperando triagem. "Média" por
        // omissão seria uma classificação que ninguém fez, e é o motivo de
        // "A definir" existir (AC-194).
        $dados['prioridade'] = $tarefa?->prioridade ?? 'nao_definida';
        $dados['responsavel_id'] = $tarefa?->responsavel_id;

        // E a coluna declarada também cai: Backlog é "priorizado e com dono", e
        // quem não triaga não pode dar nenhum dos dois. Deixar passar criaria
        // no Backlog um card sem responsável, que é a contradição que a coluna
        // Aberta existe para não ter.
        unset($dados['status']);

        return $dados;
    }

    /**
     * A mesma tarefa, do mesmo autor, criada há instantes.
     *
     * A comparação é o FORMULÁRIO INTEIRO — título, sistema, responsável e
     * prioridade — e não só o título: abrir três tarefas "Renovar certificado"
     * em sistemas diferentes é trabalho legítimo de quem está cadastrando em
     * série, e um segundo envio idêntico em tudo é sempre o duplo clique.
     *
     * @param  array<string, mixed>  $dados
     */
    private function reenvioDaMesmaTarefa(array $dados): bool
    {
        return Tarefa::where($dados)
            ->where('created_at', '>=', now()->subMinute())
            ->exists();
    }

    public function update(Request $request, Tarefa $tarefa, FluxoTarefaService $fluxo)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'titulo' => 'required|string|max:255',
            // Chave AUSENTE no envio mantém o resumo gravado (é o caso de
            // qualquer formulário que não tenha o campo); chave presente e
            // vazia o apaga, que é o que quem limpou o textarea pediu — o
            // `ConvertEmptyStringsToNull` faz o '' virar null antes daqui.
            'resumo' => 'nullable|string|max:255',
            'tipo' => 'nullable|in:'.implode(',', array_keys(Tarefa::TIPOS)),
            'sistema_id' => 'nullable|exists:sistemas,id',
            'responsavel_id' => 'nullable|exists:users,id',
            // Nunca obrigatória. Ela falta em dois envios legítimos: o de quem
            // não triaga, que não tem o campo, e o da criação rápida do pé da
            // coluna, que manda só o título. Exigi-la aqui faria a tela
            // funcionar e a rota dizer não.
            'prioridade' => 'nullable|in:'.implode(',', array_keys(Tarefa::PRIORIDADES)),
            'comentario' => 'nullable|string|max:4000',
        ]);

        // Envio sem o campo mantém o tipo que a tarefa já tem: `null` aqui
        // apagaria a coluna, porque o padrão do modelo só vale na criação.
        $data['tipo'] ??= $tarefa->tipo;
        $data['prioridade'] ??= $tarefa->prioridade;

        // Na edição, o que a triagem decidiu fica como está: quem não triaga
        // salvar a tarefa não pode zerar a prioridade nem soltar o responsável
        // de passagem.
        $data = $this->semTriagemDeQuemNaoTriaga($data, $tarefa);

        // O comentário viaja no mesmo envio do cadastro (US-049): um botão só
        // no modal, e nada de decidir entre "Salvar" e "Comentar" para o que,
        // para quem edita, é uma passada só na tarefa. Campo em branco não
        // publica nada — é o caso comum de quem abriu o modal só para trocar o
        // responsável.
        $comentario = trim($data['comentario'] ?? '');
        unset($data['comentario']);

        $tarefa->update($data);

        $etapaNova = $this->seguirOResponsavel($tarefa, $fluxo);

        if ($comentario !== '' && ! $this->reenvioDoMesmoComentario($tarefa, $comentario)) {
            $tarefa->comentarios()->create([
                'autor_id' => auth()->id(),
                'corpo' => $comentario,
            ]);
        }

        $aviso = ['Tarefa atualizada.'];

        if ($etapaNova) {
            $aviso[] = 'Movida para '.Tarefa::STATUS[$etapaNova].'.';
        }

        if ($comentario !== '') {
            $aviso[] = 'Comentário publicado.';
        }

        return redirect()->route('tarefas.index')->with('status', implode(' ', $aviso));
    }

    /**
     * Direcionar move a tarefa; tirar o dono a devolve para a fila.
     *
     * Na criação, escolher responsável já fazia a tarefa nascer no Backlog
     * (`Tarefa::booted`) — mas na edição o mesmo gesto a deixava em Aberta, e
     * quem direcionava tinha de arrastar o card em seguida. Era o mesmo fato
     * com dois comportamentos, e dois passos para uma intenção só.
     *
     * O movimento passa pelo motor do fluxo, e não por um `update` direto, para
     * o cronômetro da etapa continuar honesto: um card que troca de coluna sem
     * evento seria tempo de Aberta contado como tempo de Backlog.
     *
     * Só vale entre Aberta e Backlog. Trocar o responsável de uma tarefa que já
     * está em andamento é trocar quem faz, não recomeçar o fluxo dela.
     */
    private function seguirOResponsavel(Tarefa $tarefa, FluxoTarefaService $fluxo): ?string
    {
        $destino = match (true) {
            $tarefa->status === 'aberta' && $tarefa->responsavel_id !== null => 'backlog',
            $tarefa->status === 'backlog' && $tarefa->responsavel_id === null => 'aberta',
            default => null,
        };

        if ($destino) {
            $fluxo->mover($tarefa, $destino);
        }

        return $destino;
    }

    /**
     * O mesmo comentário, do mesmo autor, publicado há instantes.
     *
     * Salvar é um clique só na intenção, mas o clique duplo manda dois envios
     * — e o cadastro aguenta ser regravado igual, enquanto o comentário é
     * linha nova a cada vez. O botão se tranca no primeiro envio
     * (`_form.blade.php`), e isto é a rede embaixo dele: vale quando o JS não
     * roda e quando o "voltar" do navegador reenvia o formulário.
     *
     * A janela é curta de propósito. Ela existe para desfazer um acidente de
     * um segundo, não para proibir alguém de repetir a mesma frase mais tarde
     * — e repetir a mesma frase no MESMO minuto é sempre o acidente.
     */
    private function reenvioDoMesmoComentario(Tarefa $tarefa, string $corpo): bool
    {
        return $tarefa->comentarios()
            ->where('autor_id', auth()->id())
            ->where('corpo', $corpo)
            ->where('created_at', '>=', now()->subMinute())
            ->exists();
    }

    public function mover(Request $request, Tarefa $tarefa, FluxoTarefaService $fluxo)
    {
        $this->bloquearVisaoDaMatriz();

        // Autorização, e não regra de fluxo: o `FluxoTarefaService` responde
        // por onde a tarefa PODE andar, e não tem — nem deve ter — noção de
        // quem está pedindo. A recusa sai com o motivo dito, porque "não
        // permitido" sem dizer de quem é a tarefa manda a pessoa adivinhar.
        if ($impedimento = $tarefa->motivoParaNaoMover(auth()->user())) {
            return back()->with('erro', $impedimento);
        }

        // Duas pessoas movendo o mesmo card: o segundo envio ganhava em
        // silêncio, e quem moveu primeiro só descobria ao recarregar — se
        // recarregasse. O formulário manda a etapa que o card TINHA na tela, e
        // a divergência vira recusa em vez de sobrescrita.
        //
        // A conferência é opcional (`nullable`) porque nem todo caminho tem
        // como saber a etapa de origem — e uma guarda que recusa envio sem o
        // campo transformaria concorrência, que é rara, em falha comum.
        $deStatus = $request->input('de_status');

        if ($deStatus && $deStatus !== $tarefa->status) {
            return back()->with('erro', 'Alguém já moveu esta tarefa para '
                .Tarefa::rotuloDaEtapa($tarefa->status).'. Confira o quadro antes de mover de novo.');
        }

        // As notas seguem opcionais AQUI de propósito, mesmo depois de o
        // `required` do textarea (`_mover.blade.php`) se revelar a única trava
        // de verdade: quem manda uma conclusão sem elas não passa mais, mas é o
        // motor do fluxo que recusa, e com a frase que explica o porquê. Um
        // `required` nesta lista responderia "o campo notas é obrigatório" a
        // quem tentou concluir uma tarefa que sequer chegou em Em testes.
        $data = $request->validate([
            'status' => 'required|in:'.implode(',', array_keys(Tarefa::STATUS)),
            'de_status' => 'nullable|string',
            'motivo' => 'nullable|string',
            'relatorio_aprovado' => 'nullable|boolean',
            'relatorio_notas' => 'nullable|string',
            'versao_producao' => 'nullable|string|max:60',
        ]);

        // A confirmação de "Em staging → Pronta p/ produção" carrega o carimbo
        // da validação (ASM-033): registra o relatório antes de checar a
        // transição, para que a validação feita agora já libere o mesmo
        // movimento. O relatório se prende sozinho ao evento AINDA ABERTO, que
        // neste instante é o do staging — é dessa passagem que ele fala.
        //
        // O texto é opcional e o carimbo não: o que o admin precisa saber antes
        // de subir a tag é que alguém validou, e a nota é o detalhe de como.
        if ($data['status'] === 'pronta_producao' && $request->has('relatorio_aprovado')) {
            TarefaRelatorioTeste::create([
                'tarefa_id' => $tarefa->id,
                'aprovado' => $request->boolean('relatorio_aprovado'),
                'notas' => $data['relatorio_notas'] ?? null,
            ]);
        }

        try {
            $fluxo->mover($tarefa, $data['status'], [
                'motivo' => $data['motivo'] ?? null,
                'versao_producao' => $data['versao_producao'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            return back()->with('erro', $e->getMessage());
        }

        // Volta para a tela de onde veio, e não para o quadro cru: com filtro
        // ligado, mover um card devolvia o quadro inteiro e o recorte se
        // perdia a cada arrasto. O mesmo vale para o "Reabrir" do histórico,
        // que agora não abandona a página nem a busca em que se estava.
        return redirect()->back(fallback: route('tarefas.index'))->with('status', 'Tarefa movida.');
    }

    /**
     * Trava a tarefa sem tirá-la da etapa, ou destrava.
     *
     * Uma rota só para os dois sentidos porque, para quem usa, é um botão só
     * que alterna — e dois caminhos separados abririam a chance de destravar o
     * que já está solto, ou travar duas vezes, sem nada a ganhar.
     */
    public function bloquear(Request $request, Tarefa $tarefa, FluxoTarefaService $fluxo)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'motivo' => 'nullable|string|max:2000',
        ]);

        try {
            $tarefa->estaBloqueada()
                ? $fluxo->destravar($tarefa)
                : $fluxo->bloquear($tarefa, $data['motivo'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return redirect()->back(fallback: route('tarefas.index'))
            ->with('status', $tarefa->fresh()->estaBloqueada() ? 'Tarefa bloqueada.' : 'Tarefa destravada.');
    }

    /**
     * Pergunta ao outro lado da revisão, ou responde a bola que está com você.
     *
     * Uma rota só para os dois sentidos pelo mesmo motivo do bloqueio: na tela
     * é uma tarja com um botão que alterna conforme de quem é a vez, e dois
     * caminhos separados abririam a chance de responder o que ninguém perguntou.
     *
     * Perguntar e responder NÃO passam por `motivoParaNaoMover`: travar isso não
     * impede ninguém de trabalhar no que não foi pedido — impede de REGISTRAR, e
     * o quadro passa a mentir. Quem responde já é conferido pelo ponteiro.
     */
    public function conversar(Request $request, Tarefa $tarefa, FluxoTarefaService $fluxo)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'corpo' => 'nullable|string|max:2000',
            // Só chega preenchido quando a tarefa ainda não tem outro lado: aí
            // a tela pergunta a quem passar a vez em vez de esconder o botão.
            // Quando há lado, o motor ignora este campo.
            'pergunta_para_id' => 'nullable|exists:users,id',
        ]);

        $usuario = auth()->user();

        try {
            $tarefa->esperaRespostaDe($usuario)
                ? $fluxo->responder($tarefa, $usuario, $data['corpo'] ?? null)
                : $fluxo->perguntar($tarefa, $usuario, $data['corpo'] ?? null, $data['pergunta_para_id'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return $this->voltarParaATarefa($tarefa->id);
    }

    /**
     * Apaga a tarefa. Não é cancelar.
     *
     * Cancelar encerra com motivo e fica auditável no histórico — é a decisão
     * de não fazer, registrada. Excluir tira o registro da existência: serve
     * para a tarefa que nunca deveria ter sido aberta (duplicada, aberta na
     * conta errada, teste), e não para a que foi descartada.
     *
     * Só de quem triaga, e mesmo assim atrás de dois passos na tela: é a única
     * ação do quadro que não tem desfazer.
     */
    public function destroy(Tarefa $tarefa)
    {
        $this->bloquearVisaoDaMatriz();

        if (! auth()->user()?->podeTriarTarefas()) {
            return back()->with('erro', 'Só quem faz triagem exclui tarefa. Para encerrar sem apagar, cancele.');
        }

        // `forceDelete` porque excluir aqui QUER dizer sumir: a tarefa usa
        // SoftDeletes, e um `delete()` deixaria a linha no banco sem aparecer
        // em lugar nenhum — nem no quadro, nem no histórico, nem para quem
        // fosse auditar. Excluir pela metade é o pior dos dois mundos.
        $tarefa->forceDelete();

        return redirect()->route('tarefas.index')->with('status', 'Tarefa excluída.');
    }

    /**
     * Regrava a ordem dos cards de UMA coluna, a partir da sequência recebida.
     *
     * Posicionar card é organizar o trabalho — decidir o que se pega primeiro —,
     * então segue a mesma capacidade que priorizar e direcionar. Quem não triaga
     * não recebe a alça de arraste (`_card.blade.php`), e aqui a rota confirma.
     *
     * Só posiciona quem está na coluna informada: a lista de ids vem do
     * navegador, e um id de outra coluna reordenaria o que não estava à vista.
     */
    public function posicionarNaColuna(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        if (! auth()->user()?->podeTriarTarefas()) {
            return back()->with('erro', 'Só quem faz triagem posiciona os cards da coluna.');
        }

        $data = $request->validate([
            'status' => 'required|in:'.implode(',', array_keys(Tarefa::STATUS)),
            'ordem' => 'required|array',
            'ordem.*' => 'integer',
        ]);

        $daColuna = Tarefa::where('status', $data['status'])->pluck('id')->all();

        foreach ($data['ordem'] as $posicao => $id) {
            if (in_array((int) $id, $daColuna, true)) {
                Tarefa::where('id', $id)->update(['ordem' => $posicao + 1]);
            }
        }

        return redirect()->back(fallback: route('tarefas.index'));
    }

    /** Acrescenta um item ao checklist, no fim da lista. */
    public function criarItem(Request $request, Tarefa $tarefa)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate(['texto' => 'required|string|max:255']);

        $tarefa->itens()->create(['texto' => trim($data['texto'])]);

        return $this->voltarParaATarefa($tarefa->id);
    }

    /**
     * Marca, desmarca ou corrige o texto de um item.
     *
     * Uma rota para as duas coisas porque, no checklist, elas são o mesmo
     * gesto de "mexer neste item" — e separá-las criaria duas rotas que fazem
     * `update` na mesma linha, com a mesma autorização e o mesmo retorno.
     *
     * Diferente do comentário, o item NÃO é do autor: checklist é combinado do
     * time, e quem confere um passo raramente é quem o escreveu.
     */
    public function atualizarItem(Request $request, TarefaItem $item)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'texto' => 'nullable|string|max:255',
            'feito' => 'nullable|boolean',
        ]);

        $mudancas = [];

        // Texto em branco não apaga o item: quem quis remover tem o botão de
        // remover, e um item sem texto seria uma linha que ninguém sabe ler.
        if (filled($data['texto'] ?? null)) {
            $mudancas['texto'] = trim($data['texto']);
        }

        if ($request->has('feito')) {
            $mudancas['feito'] = $request->boolean('feito');
        }

        if ($mudancas !== []) {
            $item->update($mudancas);
        }

        return $this->voltarParaATarefa($item->tarefa_id);
    }

    public function excluirItem(TarefaItem $item)
    {
        $this->bloquearVisaoDaMatriz();

        $tarefaId = $item->tarefa_id;

        $item->delete();

        return $this->voltarParaATarefa($tarefaId);
    }

    /**
     * Regrava a ordem do checklist a partir da sequência recebida.
     *
     * A ordem chega inteira, e não como "mova o item X para a posição N":
     * arrastar reordena a lista toda na tela, e mandar só o movimento obrigaria
     * o servidor a recalcular o que o navegador já sabe — divergindo na
     * primeira vez que dois arrastos chegassem fora de ordem.
     *
     * `whereIn` amarrado à tarefa: sem isso, um id de outra tarefa na lista
     * reordenaria checklist alheio.
     */
    public function ordenarItens(Request $request, Tarefa $tarefa)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'ordem' => 'required|array',
            'ordem.*' => 'integer',
        ]);

        $daTarefa = $tarefa->itens()->pluck('id')->all();

        foreach ($data['ordem'] as $posicao => $id) {
            if (in_array((int) $id, $daTarefa, true)) {
                TarefaItem::where('id', $id)->update(['ordem' => $posicao + 1]);
            }
        }

        return $this->voltarParaATarefa($tarefa->id);
    }

    /**
     * Volta para a tela de onde veio, com a tarefa reaberta.
     *
     * Todo mexer no checklist acontece dentro do modal da tarefa, e voltar sem
     * o `tarefa-aberta` fecharia o modal a cada item marcado — a lista de
     * conferência viraria uma sequência de reaberturas.
     */
    private function voltarParaATarefa(int $tarefaId)
    {
        return redirect()->back(fallback: route('tarefas.index'))
            ->with('tarefa-aberta', $tarefaId);
    }

    /**
     * Corrige um comentário — só o próprio, e a correção fica dita.
     *
     * Comentário errado é o erro mais barato de cometer, e até agora só havia
     * a saída de apagar e reescrever — o que jogava fora a data original e o
     * lugar da frase na conversa. Corrigir preserva os dois.
     *
     * O `editado_em` não é enfeite: reescrever em silêncio faria a tarefa
     * contar uma história que não aconteceu, e quem leu a versão anterior não
     * teria como saber que ela mudou. Editar o comentário alheio continua fora
     * de questão — a regra é a mesma do apagar.
     */
    public function editarComentario(Request $request, TarefaComentario $comentario)
    {
        $this->bloquearVisaoDaMatriz();

        abort_unless($comentario->autor_id === auth()->id(), 403, 'Só o autor corrige o próprio comentário.');

        $data = $request->validate([
            'corpo' => 'required|string|max:4000',
        ]);

        $comentario->update([
            'corpo' => trim($data['corpo']),
            'editado_em' => now(),
        ]);

        return redirect()->back(fallback: route('tarefas.index'))
            ->with('status', 'Comentário corrigido.')
            ->with('tarefa-aberta', $comentario->tarefa_id);
    }

    /**
     * Apaga um comentário — só o próprio.
     *
     * Errar o comentário é o erro mais barato de cometer e, sem esta porta, o
     * mais caro de conviver: fica na tarefa para sempre. Mas apagar o
     * comentário alheio seria reescrever a conversa de outra pessoa, então a
     * regra é estreita de propósito, e vale mesmo para quem administra.
     */
    public function excluirComentario(TarefaComentario $comentario)
    {
        $this->bloquearVisaoDaMatriz();

        abort_unless($comentario->autor_id === auth()->id(), 403, 'Só o autor apaga o próprio comentário.');

        $tarefaId = $comentario->tarefa_id;

        $comentario->delete();

        return redirect()->back(fallback: route('tarefas.index'))
            ->with('status', 'Comentário removido.')
            ->with('tarefa-aberta', $tarefaId);
    }

    public function historico(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        $filtros = $this->filtros($request);

        // Sem recorte de período (AC-097): é o caminho de auditoria para o
        // que o quadro enxuto (`index()`) já tirou de vista.
        // `eventos` para a duração do ciclo de cada linha (AC-133).
        //
        // Paginar não recorta nada — a lista continua inteira, só chega em
        // páginas. É a tabela que mais cresce (nada nunca sai dela) e era a
        // única que carregava o histórico completo, com os eventos de cada
        // tarefa, numa resposta só.
        //
        // `withQueryString` porque a busca só serve se sobreviver ao clique em
        // "próxima": sem isso, a página 2 volta a ser o histórico inteiro.
        $tarefas = Tarefa::with(['sistema', 'responsavel', 'eventos', 'comentarios.autor', 'itens'])
            ->whereIn('status', $filtros['desfecho'] !== '' ? [$filtros['desfecho']] : Tarefa::STATUS_TERMINAIS)
            ->tap(fn ($q) => $this->aplicarFiltros($q, $filtros))
            ->orderByDesc('updated_at')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        // Denominador do "X de Y" do cabeçalho, como no quadro: o histórico
        // inteiro, para o recorte se anunciar como recorte.
        $totalNoHistorico = Tarefa::whereIn('status', Tarefa::STATUS_TERMINAIS)->count();

        return view('tarefas.historico', compact(
            'tarefas', 'filtros', 'totalNoHistorico',
        ) + $this->listasDeFiltro());
    }

    /**
     * Recorte pedido na query string, já normalizado.
     *
     * O mesmo recorte serve as duas abas: quem procurou "boleto" no quadro e
     * não achou vai procurar a mesma coisa no histórico, e trocar de aba não
     * deveria obrigar a redigitar. `desfecho` é o único que só o histórico
     * usa — no quadro não há status terminal para escolher.
     *
     * Prioridade e desfecho passam por lista branca: valor inventado na URL
     * vira "sem filtro" em vez de devolver uma tela vazia sem explicação.
     *
     * @return array<string, string>
     */
    private function filtros(Request $request): array
    {
        $prioridade = $this->textoDaQuery($request, 'prioridade');
        $desfecho = $this->textoDaQuery($request, 'desfecho');
        $tipo = $this->textoDaQuery($request, 'tipo');
        $situacao = $this->textoDaQuery($request, 'situacao');

        return [
            'busca' => $this->textoDaQuery($request, 'busca'),
            'sistema' => $this->textoDaQuery($request, 'sistema'),
            'responsavel' => $this->textoDaQuery($request, 'responsavel'),
            'prioridade' => array_key_exists($prioridade, Tarefa::PRIORIDADES) ? $prioridade : '',
            // Com os dois tipos no mesmo quadro, "quero ver só o
            // desenvolvimento" passou a ser uma pergunta que a tela recebe.
            'tipo' => array_key_exists($tipo, Tarefa::TIPOS) ? $tipo : '',
            'desfecho' => in_array($desfecho, Tarefa::STATUS_TERMINAIS, true) ? $desfecho : '',
            // O recorte por SITUAÇÃO, que é o que os chips do cabeçalho do
            // quadro aplicam ao serem clicados. Um campo só para os três, e não
            // um booleano por chip: eles são mutuamente exclusivos — ninguém
            // pergunta "as travadas que também esperam por mim" —, e três
            // booleanos permitiriam justamente essa combinação sem sentido.
            'situacao' => in_array($situacao, ['esperando_mim', 'travadas', 'em_curso', 'prontas'], true) ? $situacao : '',
        ];
    }

    /**
     * Um campo da query string como texto limpo.
     *
     * `?sistema[]=1` chega como array e o cast direto para string seria um
     * erro fatal — a URL é digitável por qualquer um, então o que não for
     * texto simplesmente não filtra.
     */
    private function textoDaQuery(Request $request, string $campo): string
    {
        $valor = $request->query($campo, '');

        return is_string($valor) ? trim($valor) : '';
    }

    /**
     * Aplica o recorte comum às duas abas.
     *
     * A busca varre título, resumo, detalhes E os comentários: quem procura
     * uma tarefa pelo número do chamado ou pelo nome do cliente costuma ter
     * escrito isso no corpo, não no título — e desde que a tarefa tem
     * conversa, o corpo mais provável é justamente o comentário, que é onde o
     * assunto continua depois de a tarefa nascer. As condições vão dentro de
     * um `where` aninhado — soltas, o `orWhere` escaparia do `whereIn` de
     * status e o quadro passaria a mostrar tarefa concluída.
     *
     * "Sem sistema" e "Sem responsável" são filtro de verdade, não enfeite: a
     * coluna Aberta é a fila de triagem, e achar o que ainda não tem dono é
     * exatamente o que se pergunta ali (AC-130).
     *
     * @param  Builder<Tarefa>  $query
     * @param  array<string, string>  $filtros
     */
    private function aplicarFiltros($query, array $filtros): void
    {
        // O número do card, com ou sem o "#" que a tela imprime: quem recebe
        // "dá uma olhada na 128" digita o que ouviu, e sem esta linha o único
        // dado da tarefa que não a encontraria seria justamente o que existe
        // para nomeá-la. Continua sendo um OU — "128" também acha a tarefa que
        // tem 128 escrito no corpo, que é outra pergunta legítima.
        $numero = ltrim($filtros['busca'], '#');
        $buscaPorNumero = $numero !== '' && ctype_digit($numero);

        $query
            ->when($filtros['busca'] !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('titulo', 'like', '%'.$filtros['busca'].'%')
                ->orWhere('resumo', 'like', '%'.$filtros['busca'].'%')
                ->orWhere('detalhes', 'like', '%'.$filtros['busca'].'%')
                ->orWhereHas('comentarios', fn ($comentario) => $comentario
                    ->where('corpo', 'like', '%'.$filtros['busca'].'%'))
                // Sistema e responsável entram na busca porque é assim que se
                // procura na prática: "aquela do AlfaGym", "as da Camila". Sem
                // eles, quem digita o nome do produto recebe zero resultado num
                // quadro que tem seis cards dele.
                ->orWhereHas('sistema', fn ($sistema) => $sistema
                    ->where('nome', 'like', '%'.$filtros['busca'].'%'))
                ->orWhereHas('responsavel', fn ($pessoa) => $pessoa
                    ->where('name', 'like', '%'.$filtros['busca'].'%'))
                // No FIM da cadeia de propósito: como primeira condição do
                // grupo, o `or` seria descartado na compilação e o `and` do
                // título grudaria neste — a busca por texto passaria a valer
                // só dentro da tarefa daquele número.
                ->when($buscaPorNumero, fn ($comNumero) => $comNumero
                    ->orWhere('tarefas.id', (int) $numero))))
            ->when($filtros['sistema'] === 'sem', fn ($q) => $q->whereNull('sistema_id'))
            ->when($filtros['sistema'] !== '' && $filtros['sistema'] !== 'sem',
                fn ($q) => $q->where('sistema_id', $filtros['sistema']))
            ->when($filtros['responsavel'] === 'sem', fn ($q) => $q->whereNull('responsavel_id'))
            ->when($filtros['responsavel'] !== '' && $filtros['responsavel'] !== 'sem',
                fn ($q) => $q->where('responsavel_id', $filtros['responsavel']))
            ->when($filtros['prioridade'] !== '', fn ($q) => $q->where('prioridade', $filtros['prioridade']))
            ->when($filtros['tipo'] !== '', fn ($q) => $q->where('tipo', $filtros['tipo']))
            ->when(($filtros['situacao'] ?? '') === 'esperando_mim',
                fn ($q) => $q->esperandoRespostaDe(auth()->id()))
            ->when(($filtros['situacao'] ?? '') === 'travadas',
                fn ($q) => $q->whereNotNull('bloqueado_em'))
            // "Em curso" é o complemento de travadas: o que está ANDANDO. É a
            // mesma conta do WIP, e por isso a mesma definição — vaga ocupada
            // por tarefa parada não é trabalho em curso.
            ->when(($filtros['situacao'] ?? '') === 'em_curso',
                fn ($q) => $q->whereNull('bloqueado_em'))
            ->when(($filtros['situacao'] ?? '') === 'prontas',
                fn ($q) => $q->where('status', 'pronta_producao'));
    }

    /**
     * As listas que abastecem os selects — de filtro e de cadastro.
     *
     * Sistemas só os ativos, como no formulário; usuários sem escopo de
     * revenda, que são os que podem responder por tarefa da matriz.
     *
     * É a ÚNICA lista de sistemas do painel que não é filtrada por natureza, e
     * de propósito: aqui a pergunta não é "o que a Alfa vende", é "sobre o que
     * este trabalho é". A própria Matriz, a infra e o site produzem tarefa como
     * qualquer produto — e enquanto só o catálogo comercial era oferecido, essa
     * tarefa nascia sem sistema e sumia do filtro e da raia.
     *
     * Ordenada por natureza antes do nome porque o `select` agrupa nas duas
     * famílias: a ordem do grupo sai da ordem das linhas, e sem isto o
     * `optgroup` alternaria produto e interno a cada opção.
     *
     * Conta desativada sai da lista — não se dirige trabalho a quem não entra
     * mais. Mas continua quem JÁ é responsável ou interlocutor de alguma
     * tarefa: fora da lista, o `select` de uma tarefa antiga perderia o valor
     * escolhido, e salvá-la a esvaziaria sem ninguém pedir. O filtro do
     * histórico depende da mesma lista, e sem eles não haveria como procurar
     * pelo que a pessoa deixou.
     *
     * @return array<string, Collection<int, mixed>>
     */
    private function listasDeFiltro(): array
    {
        return [
            'sistemas' => Sistema::where('ativo', true)
                // Produto primeiro, e não a ordem alfabética da coluna — que
                // poria "interno" antes de "produto" e enterraria o catálogo
                // que responde pela maior parte das tarefas.
                ->orderByRaw("CASE WHEN natureza = 'produto' THEN 0 ELSE 1 END")
                ->orderBy('nome')
                ->get(),
            'usuarios' => User::whereNull('revenda_id')
                ->where(fn ($query) => $query
                    ->where('ativo', true)
                    ->orWhereHas('tarefas')
                    ->orWhereHas('tarefasComoInterlocutor'))
                ->orderBy('name')
                ->get(),
        ];
    }

    /**
     * Ordem dos cards dentro de uma coluna: gravidade primeiro, e no empate a
     * tarefa mais parada na etapa (AC-128).
     *
     * Antes a ordem era só `created_at desc`, o que fazia uma crítica antiga
     * afundar embaixo de tarefas baixas recentes — a prioridade ficava
     * legível no selo e sem efeito nenhum na leitura da coluna.
     *
     * O desempate usa a entrada na etapa ATUAL, o mesmo instante que o card
     * mostra no chip de tempo: se a ordem seguisse outro critério, a coluna
     * pareceria embaralhada para quem lê os chips de cima para baixo.
     *
     * @param  Collection<int, Tarefa>  $tarefas
     * @return Collection<int, Tarefa>
     */
    private function ordenarColuna($tarefas)
    {
        // Coluna que alguém arrumou à mão fica como foi arrumada.
        //
        // A ordem automática responde "o que é mais grave", que não é a mesma
        // pergunta que "qual eu pego primeiro" — entre duas tarefas altas, quem
        // conhece o trabalho sabe que uma destrava a outra, e o quadro não tem
        // como saber. Mas isso só vale onde alguém de fato arrumou: coluna
        // intocada segue a régua automática, senão a primeira renderização
        // viraria uma lista congelada.
        //
        // O que chega depois (card movido de outra coluna, tarefa nova) entra
        // sem posição e vai para o FIM, ordenado entre os seus pela régua
        // automática — ele ainda não foi colocado em lugar nenhum.
        if ($tarefas->whereNotNull('ordem')->isNotEmpty()) {
            return $tarefas
                ->sortBy(fn (Tarefa $tarefa) => sprintf(
                    '%d-%010d-%s',
                    $tarefa->ordem === null ? 1 : 0,
                    $tarefa->ordem ?? 0,
                    $this->chaveAutomatica($tarefa),
                ))
                ->values();
        }

        return $tarefas->sortBy(fn (Tarefa $tarefa) => $this->chaveAutomatica($tarefa))->values();
    }

    /**
     * A régua automática: gravidade primeiro, e no empate o mais parado.
     *
     * @return string
     */
    private function chaveAutomatica(Tarefa $tarefa)
    {
        // "A definir" fecha a lista: ela não é o grau mais baixo da escala, é a
        // decisão que ainda não foi tomada — e colocá-la no topo faria a tarefa
        // que ninguém classificou passar na frente da que alguém chamou de
        // crítica. Quem procura o que triar tem o contador no cabeçalho.
        $gravidade = array_flip(['critica', 'alta', 'media', 'baixa', 'nao_definida']);

        // Chave composta em vez de `sortBy([closure, closure])`: essa forma
        // NÃO ordena por múltiplas chaves — ela considera só a última, e a
        // gravidade era silenciosamente ignorada.
        return sprintf(
            '%d-%020d',
            $gravidade[$tarefa->prioridade] ?? count($gravidade),
            $this->entrouNaEtapaEm($tarefa)->getTimestamp(),
        );
    }

    /**
     * Quando a tarefa entrou na etapa em que está: o evento ainda sem saída.
     * Tarefa que nunca se moveu conta a partir da criação — mesmo critério do
     * card (`_card.blade.php`).
     */
    private function entrouNaEtapaEm(Tarefa $tarefa)
    {
        return $tarefa->eventos->firstWhere('saiu_em', null)?->entrou_em ?? $tarefa->created_at;
    }
}
