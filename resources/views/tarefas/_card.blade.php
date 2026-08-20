@php
    /**
     * Card do quadro.
     *
     * As medidas vêm do `design/AlfaMatriz Tarefas.dc.html`, que tem todo
     * estilo inline em px literais — ele é a especificação dimensional, e o
     * `design/TAREFAS-SPEC.md` resume os mesmos números. Nada aqui foi medido
     * em screenshot: cada valor arbitrário abaixo está no fonte do protótipo.
     *
     * A etapa atual é o evento de `tarefa_eventos` ainda sem saída; tarefa que
     * nunca se moveu (sem evento nenhum) conta a partir da criação.
     */
    $eventoAberto = $tarefa->eventos->firstWhere('saiu_em', null);
    $entrouNaEtapaEm = $eventoAberto?->entrou_em ?? $tarefa->created_at;
    $segundosNaEtapa = $entrouNaEtapaEm->diffInSeconds(now());

    // Mesma régua do histórico (`Tarefa::duracaoCurta`): "3h" precisa querer
    // dizer a mesma coisa nas duas telas.
    $tempoNaEtapa = \App\Models\Tarefa::duracaoCurta((int) $segundosNaEtapa);

    // O envelhecimento vale em TODAS as etapas de trabalho, cada uma com a sua
    // régua (AC-193): três dias escrevendo código é trabalho, três dias
    // esperando alguém revisar é fila. O aviso dobra de peso no dobro do prazo.
    $limiar = \App\Models\Tarefa::HORAS_ATE_ENVELHECER[$tarefa->status] ?? null;
    $nivelEsquecida = null;
    if ($limiar !== null) {
        $horasNaEtapa = $segundosNaEtapa / 3600;
        $nivelEsquecida = match (true) {
            $horasNaEtapa >= $limiar * 2 => 'critico',
            $horasNaEtapa >= $limiar => 'atencao',
            default => null,
        };
    }
    /**
     * O selo de tempo, quando a tarefa envelhece.
     *
     * Os dois degraus são o MESMO dourado, e o que muda é o preenchimento —
     * tingido na atenção, chapado no crítico. O vermelho seria o segundo degrau
     * natural, e era o que estava aqui, mas ele virou a prioridade Crítica na
     * borda (AC-356): o selo passaria a repetir a cor de outra notícia, e no
     * card nenhuma cor diz duas coisas (AC-358). O dourado não é de mais
     * ninguém desde que "A definir" saiu dele.
     */
    $estiloDoTempo = match ($nivelEsquecida) {
        'atencao' => 'background: rgb(var(--warn) / var(--tint-alpha)); color: rgb(var(--warn))',
        'critico' => 'background: rgb(var(--warn)); color: rgb(var(--on-brand))',
        default => 'background: var(--chip); color: rgb(var(--ink-mute))',
    };

    $bloqueada = $tarefa->estaBloqueada();
    $temPergunta = $tarefa->temPergunta();
    $temRetorno = $tarefa->temRetorno();

    // Um tom por nível de prioridade, sem repetir (AC-126). "A definir" fica
    // fora da escala, no âmbar de alerta: não é um grau de gravidade, é a
    // triagem que ainda não aconteceu.
    $tomPrioridade = \App\Models\Tarefa::TOM_DA_PRIORIDADE[$tarefa->prioridade] ?? 'neutro';
    $corPrioridade = ['neutro' => null, 'marca' => 'brand', 'ambar' => 'amber',
                      'triagem' => 'triagem', 'critico' => 'crit'][$tomPrioridade] ?? null;

    /**
     * A borda do card é a PRIORIDADE — 2px, cor cheia.
     *
     * Ela já foi a precedência de sinais do protótipo (bloqueio e retorno em
     * âmbar, pergunta na marca, envelhecimento no tom do nível). Saiu porque
     * repetia: bloqueio, retorno e pergunta desenham tarja própria dentro do
     * card, com ícone, rótulo e motivo, e o envelhecimento pinta o selo de
     * tempo no rodapé. A prioridade era o único dado sem eco — vivia num selo
     * de 9,5px que só se lê parando em cima. Na borda ela se lê de relance,
     * com a coluna inteira na vista, que é como o quadro é olhado.
     *
     * 2px e sem o alfa 0.4 do protótipo porque 1px lavado sobre o fundo
     * recuado do quadro não se via: a borda existia e não informava nada.
     *
     * Prioridade Baixa não tem cor (tom neutro) e fica em `line` — se todo
     * card tivesse borda colorida, nenhuma se destacaria.
     */
    $corDaBorda = $corPrioridade ? 'rgb(var(--'.$corPrioridade.'))' : 'var(--line)';

    /**
     * Duas prioridades não se separam pelo tom — se separam pela FORMA.
     *
     * A paleta tem uma família quente só, e as três prioridades mais graves
     * moram nela: `amber` na Alta, `warn` em "A definir", `crit` na Crítica. No
     * tema claro os três descem para o mesmo marrom-alaranjado escuro e, de
     * relance na coluna, viram a mesma borda. Tom frio não sobra — `brand` é a
     * Média e `good` é o verde de sucesso —, e inventar cor fora dos tokens é o
     * que o redesign não faz. Então:
     *
     * - **"A definir" é tracejada.** Que é o que ela é: triagem que ainda não
     *   aconteceu, não um grau de gravidade (AC-126). O tracejado já é o idioma
     *   da casa para lacuna — o círculo sem sistema, no rodapé deste card.
     * - **A Crítica tinge o corpo.** Ela para de depender do tom da borda para
     *   se destacar: na coluna ela é outra COISA, e não um vizinho mais escuro.
     *
     * O tinte é `background-image` POR CIMA da classe `bg-card-quadro`, e não
     * uma cor no lugar dela: `--crit-tint` é translúcido, e embaixo dele tem de
     * ficar o fundo opaco do card. Trocado, ele cairia sobre o fundo recuado do
     * quadro e daria outra cor. Duas cores de fundo não se empilham em CSS —
     * degradê de uma cor só é como se pinta uma camada chapada sobre a outra.
     */
    $semTriagem = $tarefa->prioridade === 'nao_definida';
    $critica = $tarefa->prioridade === 'critica';

    // A pergunta em aberto e o comentário que a abriu, da coleção já carregada:
    // o quadro renderiza dezenas de cards, e uma consulta em cada um é o N+1
    // clássico desta tela.
    $perguntaAberta = $temPergunta ? $tarefa->comentarios->where('pergunta', true)->last() : null;
    $perguntaHa = $temPergunta
        ? \App\Models\Tarefa::duracaoCurta((int) $tarefa->pergunta_em->diffInSeconds(now()))
        : null;

    /**
     * Primeiro e último nome — a régua vale para o responsável e o criador.
     *
     * O nome inteiro não cabe no card, e o pedaço que sobra tem de ser o que
     * distingue: só o primeiro nome empata duas Julianas do time, e o `title`
     * com o nome completo continua a um passe de mouse. Nome único fica como
     * está — repeti-lo em vez de acusar a falta do sobrenome seria pior.
     */
    $primeiroEUltimo = function (?string $nome): ?string {
        $partes = \Illuminate\Support\Str::of((string) $nome)->squish()->explode(' ')->filter()->values();

        return $partes->count() > 1
            ? $partes->first().' '.$partes->last()
            : $partes->first();
    };
    $responsavel = $tarefa->responsavel;
    $nomeCurto = $primeiroEUltimo($responsavel?->name);
    $criadorCurto = $primeiroEUltimo($tarefa->criadoPor?->name);

    $progresso = $tarefa->progressoDoChecklist();
    $totalComentarios = $tarefa->comentarios->count();
    $totalAnexos = $tarefa->anexos->count();
    $progressoSubtarefas = $tarefa->progressoDasSubtarefas();
@endphp

{{--
    raio 5px, padding 10px, `panelRaised` de fundo e a sombra de 1px que só
    esta tela usa — o resto do painel decidiu não ter sombra, mas o quadro
    empilha cards sobre um fundo recuado e sem ela eles encostam no board.
--}}
<article data-tarefa="{{ $tarefa->id }}"
         @if ($nivelEsquecida && ! $bloqueada) data-esquecida="{{ $nivelEsquecida }}" @endif
         @if ($bloqueada) data-bloqueada="1" @endif
         class="rounded-[5px] border-2 p-[10px] bg-card-quadro shadow-card {{ $semTriagem ? 'border-dashed' : '' }}"
         style="border-color: {{ $corDaBorda }}{{ $critica ? '; background-image: linear-gradient(var(--crit-tint), var(--crit-tint))' : '' }}">

    {{-- Linha 1: título e selos. `flex-start` porque o título quebra em duas
         linhas e os selos ficam alinhados com a primeira. --}}
    <div class="flex items-start gap-2">
        {{--
            O número da tarefa é PREFIXO do título, dentro do mesmo parágrafo.

            Como item próprio do flex ele abriria uma coluna fixa que tira
            largura do título nos dois lados da quebra, e alinhar tipo miúdo
            contra 13,5px em `items-start` pediria um deslocamento que não está
            na especificação. No mesmo bloco de texto ele senta na linha de base
            do título de graça, e o título que quebra em duas linhas o mantém
            onde se lê primeiro — que é para isso que o número existe: dizer em
            voz alta "olha a 128" sem descrever a tarefa inteira.

            `ink-dim` e não `ink-faint`: o faint é o degrau mais apagado da
            escala nos dois temas, e um número que precisa ser LIDO de relance
            não pode morar na cor reservada a rótulo de apoio. Sobe também de
            10px para 11,5px, e a mono ganha peso — juntos eles dão destaque
            sem cor própria. Cor aqui seria mentira: no card, marca quer dizer
            "tem pergunta" e âmbar quer dizer "travada", e o número não é
            notícia nenhuma sobre a tarefa. O título continua acima dele, em
            `ink` cheio a 13,5px, porque quem procura pelo assunto é a maioria.
        --}}
        <p class="min-w-0 flex-1 text-[13.5px] font-medium leading-[1.35] text-ink">
            <span class="font-mono text-[11.5px] font-semibold text-ink-dim">{{ $tarefa->codigo() }}</span>

            {{--
                O placar das subtarefas mora AQUI, e não no rodapé com os
                outros selos: ele fala da VIZINHANÇA da tarefa, e o número ao
                lado é a moeda desse apontamento. Os selos do rodapé falam do
                trabalho desta tarefa — quanto tempo, quantos passos, quantas
                mensagens. Nesta linha sobram 141px; lá o selo cairia para uma
                segunda linha que o `overflow-hidden` corta, sumindo por inteiro
                (armadilha 19).

                Ele fica ÂMBAR enquanto há filha aberta: é ele que
                explica por que o botão de concluir vai recusar, e descobrir isso
                só no clique é a recusa sem aviso que o quadro evita em todo o
                resto. Verde quando fecha, porque aí ele destrava.
            --}}
            @if ($progressoSubtarefas)
                @php
                    $fechou = $progressoSubtarefas['feitas'] === $progressoSubtarefas['total'];
                @endphp
                <span class="inline-flex items-center gap-[3px] align-middle font-sans tabular text-[10px]"
                      style="color: rgb(var(--{{ $fechou ? 'good' : 'warn' }}))"
                      title="{{ $progressoSubtarefas['feitas'] }} de {{ $progressoSubtarefas['total'] }} subtarefas encerradas{{ $fechou ? '' : ' · esta tarefa não encerra antes' }}">
                    <span class="h-[11px] w-[11px]"><x-nav-icon name="view-list" :peso="1.8" /></span>{{ $progressoSubtarefas['feitas'] }}/{{ $progressoSubtarefas['total'] }}
                </span>
            @endif

            {{ $tarefa->titulo }}
        </p>

        @if ($tarefa->tipo === 'operacional')
            <span class="shrink-0 px-1.5 py-0.5 rounded-badge bg-chip text-ink-mute
                         font-mono text-[9.5px] font-semibold uppercase tracking-[0.08em]">Oper.</span>
        @endif

        <span class="shrink-0 px-1.5 py-0.5 rounded-badge font-mono text-[9.5px] font-semibold uppercase tracking-[0.08em]"
              style="{{ $corPrioridade
                  ? 'background: rgb(var(--'.$corPrioridade.') / var(--tint-alpha)); color: rgb(var(--'.$corPrioridade.'))'
                  : 'background: var(--chip); color: rgb(var(--ink-mute))' }}">
            {{ \App\Models\Tarefa::PRIORIDADES[$tarefa->prioridade] ?? $tarefa->prioridade }}
        </span>
    </div>

    {{-- Resumo: uma linha só. O texto inteiro é assunto do detalhe (AC-129), e
         o `title` entrega o resto sem custar altura. --}}
    @if (filled($tarefa->resumo))
        <p class="mt-[5px] text-[12px] leading-[1.4] text-ink-mute truncate"
           title="{{ $tarefa->resumo }}">{{ $tarefa->resumo }}</p>
    @endif

    {{-- Quem ABRIU a tarefa, e QUANDO (pedidos do dono em 18/08/2026). O
         rodapé nomeia com quem ela está, mas quem a pediu só existia dentro
         do detalhe — e "de quem veio este pedido?" é pergunta que se faz
         olhando o quadro, sobretudo na fila de triagem. Dito por extenso
         ("Criada por") porque um segundo nome solto no card se leria como
         outro responsável. A data vai por extenso pelo mesmo motivo do
         timestamp: o card só tinha tempo RELATIVO ("17h"), que obriga a conta
         de cabeça — a data diz de uma vez. `d/m/Y H:i` é o formato da linha
         do tempo e do histórico. Sem criador a linha continua, só com a data:
         o "quando" não depende do "quem". --}}
    {{-- Nome e data em flex, como o rodapé: quem cede é o NOME, que trunca
         com o inteiro no `title`; a data é `shrink-0` e aparece sempre
         inteira — truncada no meio ("14/08/2026 00…") ela era exatamente a
         metade de timestamp que a armadilha 19 manda não mostrar.
         `items-baseline` porque texto ao lado de texto alinha pela linha de
         base. --}}
    <p class="mt-[5px] flex items-baseline gap-2 text-[11.5px] leading-[1.4] text-ink-faint"
       title="Criada {{ $tarefa->criadoPor ? 'por '.$tarefa->criadoPor->name.' ' : '' }}em {{ $tarefa->created_at->format('d/m/Y H:i') }}">
        @if ($tarefa->criadoPor)
            <span class="min-w-0 flex-1 truncate">Criada por {{ $criadorCurto }}</span>
            <span class="shrink-0">{{ $tarefa->created_at->format('d/m/Y H:i') }}</span>
        @else
            <span class="min-w-0 flex-1 truncate">Criada em {{ $tarefa->created_at->format('d/m/Y H:i') }}</span>
        @endif
    </p>

    {{--
        A tarja de pergunta.

        Dúvida na revisão não é impedimento: o PR continua aberto, a tarefa
        continua no WIP, e a tarja é da cor da CONVERSA — de âmbar, junto do
        bloqueio, ela ensinaria que perguntar é problema.

        O roxo é dela e do portão de exame, e de mais ninguém. Enquanto a borda
        do card era o aviso de esquecida, a tarja podia usar o teal da marca;
        agora a borda é a prioridade (AC-356), e o teal ali quer dizer "Média" —
        uma tarja de pergunta da mesma cor pintava duas coisas diferentes com o
        mesmo tom, dentro do mesmo card.

        O NOME OCUPA LINHA PRÓPRIA. Na primeira linha cabem o rótulo e o tempo;
        "Aguardando resposta de Camila" ali dentro seria truncado justamente na
        parte que importa — quem deve a resposta é a informação inteira da tarja.
    --}}
    @if ($temPergunta)
        <div class="mt-2 px-[9px] py-[7px] rounded-tile border-l-2"
             style="background: var(--pergunta-tint); border-color: rgb(var(--pergunta))">
            <div class="flex items-center gap-1.5">
                <span class="h-3 w-3 shrink-0 text-pergunta"><x-nav-icon name="duvida" :peso="1.9" /></span>
                <span class="flex-1 min-w-0 font-mono text-[9.5px] font-semibold uppercase tracking-[0.08em] text-pergunta whitespace-nowrap">
                    Aguardando resposta
                </span>
                <span class="shrink-0 font-sans tabular text-[9.5px] text-ink-mute">{{ $perguntaHa }}</span>
            </div>

            <div class="mt-1 flex items-center gap-1.5">
                <span class="flex-1 min-w-0 text-[12px] font-semibold text-ink truncate">
                    {{ $tarefa->perguntaPara?->name ?? 'alguém' }}
                </span>
                <span class="shrink-0 px-[5px] py-px rounded-badge font-sans tabular text-[9px] font-semibold whitespace-nowrap"
                      {{-- Empacada, o selo CHAPA no próprio roxo da pergunta em vez
                           de acender em vermelho: o vermelho é a prioridade Crítica
                           (AC-356), e a terceira rodada de uma conversa não é isso.
                           A escalada acontece dentro da cor da notícia. --}}
                      style="{{ $tarefa->conversaEmpacada()
                          ? 'background: rgb(var(--pergunta)); color: rgb(var(--on-brand))'
                          : 'background: var(--chip); color: rgb(var(--ink-mute))' }}">
                    {{ max(1, $tarefa->rodadas) }}ª rodada
                </span>
            </div>

            @if (filled($perguntaAberta?->corpo))
                <p class="mt-1 text-[11.5px] leading-[1.4] text-ink line-clamp-2 whitespace-pre-wrap"
                   title="{{ $perguntaAberta->corpo }}">{{ $perguntaAberta->corpo }}</p>
            @endif

            {{-- Três idas e voltas quer dizer que o PR está grande demais ou a
                 tarefa foi mal especificada — e aí perguntar de novo não é o
                 que resolve. --}}
            @if ($tarefa->conversaEmpacada())
                <p class="mt-[5px] font-mono text-[9px] uppercase tracking-[0.06em]" style="color: rgb(var(--pergunta))">
                    considere devolver para correção
                </p>
            @endif

            {{-- Responder abre o campo NO CARD: a resposta de uma dúvida é
                 curta, e obrigar a abrir a tarefa para escrever duas linhas é o
                 atrito que faz a pergunta ficar sem resposta. --}}
            @if ($tarefa->esperaRespostaDe(auth()->user()))
                <div x-data="{ respondendo: false }" @click.stop>
                    <button type="button" x-show="! respondendo"
                            @click="respondendo = true; $nextTick(() => $refs.resposta.focus())"
                            class="mt-[7px] w-full h-[26px] rounded-tile border border-pergunta bg-transparent
                                   text-pergunta text-[11.5px] font-semibold transition hover:bg-pergunta/10">
                        Responder
                    </button>

                    {{-- `data-parcial`: responder daqui redesenha o quadro no
                         lugar, sem recarga. É o que mantém a rolagem da coluna
                         onde estava — a tarja fica a meia tela de altura, e
                         voltar ao topo depois de responder obrigaria a
                         reencontrar o card a cada resposta. --}}
                    <form x-show="respondendo" x-cloak method="POST" data-parcial
                          action="{{ route('tarefas.conversar', $tarefa) }}" class="mt-[7px]">
                        @csrf
                        <textarea x-ref="resposta" name="corpo" rows="2" required placeholder="Sua resposta…"
                                  @keydown.escape.stop="respondendo = false"
                                  class="block w-full px-[9px] py-[7px] rounded-tile bg-input border border-pergunta
                                         text-ink text-[12px] leading-[1.45] resize-y focus:ring-0"></textarea>
                        <div class="mt-1.5 flex gap-1.5">
                            <button type="button" @click="respondendo = false"
                                    class="shrink-0 h-[26px] px-2.5 rounded-tile border border-btn-line
                                           text-ink-dim text-[11.5px] font-semibold transition hover:bg-chip">
                                Cancelar
                            </button>
                            <button type="submit"
                                    class="flex-1 h-[26px] rounded-tile bg-pergunta text-on-brand
                                           text-[11.5px] font-semibold transition hover:opacity-90">
                                Enviar resposta
                            </button>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    @endif

    {{-- A bola do portão: quem foi apontado para examinar (US-087). Sem esta
         linha o apontamento não deixava rastro NO CARD — a pessoa escolhia no
         seletor, olhava o quadro e concluía que não tinha gravado. Uma linha
         só, no roxo da conversa como a pergunta: apontado não é problema, e as
         duas tarjas falam da mesma coisa — a bola está com uma pessoa.

         A frase inteira é PROSA, num corpo só, como o detalhe já diz "Na main,
         aguardando o teste de Fulano": o microlabel (mono, caixa alta,
         espaçado) só funciona em linha própria — inline com o nome, o ritmo de
         letras dos dois descasa e a linha se lê como remendo. Rótulo e nome no
         mesmo bloco de texto, na mesma fonte e corpo; o nome se destaca pelo
         peso, que não mexe no espaçamento. --}}
    {{--
        De que trabalho maior este card é pedaço.

        Nasceu como um selo de `#226` na linha do número, ao lado do elo. Não
        servia: numa coluna com três subtarefas de revisões diferentes, três
        números pequenos em mono não dizem que são três famílias — quem lê a
        coluna de cima para baixo não percebe agrupamento nenhum. Com o TÍTULO
        da mãe repetido, as irmãs se reconhecem pela leitura, em qualquer
        coluna.

        Chegou a existir também um recuo do card sob a mãe. Saiu: ele só ligava
        quando as duas calhavam de estar na mesma coluna — 2 vezes em 11, na
        massa real — e para conseguir isso obrigava a filha a furar a régua da
        coluna, passando na frente de tarefas mais graves. A faixa vale sempre e
        não mexe em ordem nenhuma.

        Tom NEUTRO, ao contrário das outras tarjas: de onde o card veio não é
        notícia. Âmbar é "travada", roxo é "tem pergunta" — dar cor a esta linha
        faria a proveniência gritar no mesmo volume de um impedimento.
    --}}
    @if ($tarefa->ehSubtarefa())
        <div class="mt-2 flex items-center gap-1.5 px-[9px] py-[7px] rounded-tile border-l-2 bg-chip"
             style="border-color: var(--line)">
            <span class="h-3 w-3 shrink-0 text-ink-faint"><x-nav-icon name="subtarefa" :peso="1.9" /></span>

            {{-- `truncate` e não `nowrap` solto: o título longo da mãe pararia
                 de pintar por cima do que vem depois só com o corte
                 (armadilha 1). O número vem junto porque é o que se copia
                 daqui para falar da mãe com alguém. --}}
            <p class="flex-1 min-w-0 text-[12px] leading-[1.4] truncate"
               title="Subtarefa de #{{ $tarefa->tarefa_pai_id }}{{ $tarefa->pai ? ' · '.$tarefa->pai->titulo : '' }}">
                <span class="font-mono text-[11px] text-ink-faint">#{{ $tarefa->tarefa_pai_id }}</span>
                <span class="text-ink-mute">{{ $tarefa->pai?->titulo }}</span>
            </p>
        </div>
    @endif

    @php
        $noAr = $tarefa->status === 'em_producao';

        // Em produção a faixa aparece SEMPRE, com ou sem apontado: a versão é o
        // que a coluna tem de dizer — "o que está no ar" é a primeira pergunta
        // de quem vai conferir —, e o motor a cobra na entrada, então ela nunca
        // falta. Nos outros dois portões a faixa continua presa ao apontamento,
        // que é a única notícia que eles têm.
        $mostraOExame = in_array($tarefa->status, \App\Models\Tarefa::PORTOES_DE_EXAME, true)
            && ($tarefa->interlocutor_id || $noAr);
    @endphp

    @if ($mostraOExame)
        <div class="mt-2 px-[9px] py-[7px] rounded-tile border-l-2"
             style="background: var(--exame-tint); border-color: rgb(var(--exame))">
            <div class="flex items-center gap-1.5">
                <span class="h-3 w-3 shrink-0 text-exame"><x-nav-icon name="eye" :peso="1.9" /></span>
                <p class="flex-1 min-w-0 text-[12px] leading-[1.4] truncate">
                    @if ($tarefa->interlocutor_id)
                        <span class="text-exame">{{ match ($tarefa->status) {
                            'em_revisao' => 'Revisão com',
                            'em_staging' => 'Teste com',
                            default => 'Validação com',
                        } }}</span>
                        <span class="font-semibold text-ink">{{ $tarefa->interlocutor?->name ?? 'alguém' }}</span>
                    @else
                        <span class="text-exame">No ar, sem validador apontado</span>
                    @endif
                </p>
            </div>

            {{-- A VERSÃO OCUPA LINHA PRÓPRIA, como o nome na tarja de pergunta
                 e pelo mesmo motivo. Ao lado do rótulo ela deixava 148px para o
                 texto, e "Validação com Camila Reis" mede 151 — o nome ia para
                 a reticência por 3px, e quem está segurando a tarefa é a
                 informação inteira desta faixa. Os outros dois portões não
                 ganham a linha: só o que está no ar tem versão.

                 O rótulo continua nomeando o ATO, como "Revisão com" e "Teste
                 com". Chegou a ser "No ar com" enquanto a versão dividia a
                 linha — mas aquilo nomeava o lugar, e a faixa passava a
                 responder outra pergunta que não a dela. --}}
            @if ($noAr && $tarefa->versao_producao)
                <p class="mt-1 font-mono text-[9.5px] text-ink-mute truncate"
                   title="Versão que subiu para produção">{{ $tarefa->versao_producao }}</p>
            @endif
        </div>
    @endif

    {{-- A tarja de retorno nomeia o PORTÃO que reprovou: "Voltou da revisão" e
         "Voltou do staging" descrevem recuperações diferentes, e era esse
         detalhe que a coluna única de Ajustes achatava. --}}
    @if ($temRetorno)
        <div class="mt-2 px-[9px] py-[7px] rounded-tile border-l-2"
             style="background: var(--retorno-tint); border-color: rgb(var(--retorno))">
            <div class="flex items-center gap-1.5">
                <span class="h-3 w-3 shrink-0" style="color: rgb(var(--retorno))"><x-nav-icon name="arrow-uturn-left" :peso="1.9" /></span>
                <span class="flex-1 min-w-0 font-mono text-[9.5px] font-semibold uppercase tracking-[0.08em] truncate"
                      style="color: rgb(var(--retorno))">{{ $tarefa->rotuloDoRetorno() }}</span>
            </div>

            @if (filled($tarefa->retorno_motivo))
                <p class="mt-1 text-[11.5px] leading-[1.4] text-ink line-clamp-2"
                   title="{{ $tarefa->retorno_motivo }}">{{ $tarefa->retorno_motivo }}</p>
            @endif
        </div>
    @endif

    {{--
        A tarja de bloqueio.

        O motivo ocupa a largura inteira e vai até duas linhas: ele é a razão de
        Bloqueada ter virado marca em vez de coluna. Truncado a duas palavras, o
        "porquê" só existiria no tooltip — fora do relance, que é onde o quadro
        opera. Etapa e tempo sobem para o cabeçalho da tarja, e o Destravar é
        ícone.

        VERMELHA, e não âmbar como o retorno. As duas dividiam o mesmo tom e não
        são a mesma notícia: quem voltou de um portão está andando — para trás,
        mas andando —, e quem está travada não está fazendo nada. O âmbar também
        era a cor da borda de "A definir", e a tarja dentro daquele card pintava
        o impedimento com o tom da falta de triagem.
    --}}
    @if ($bloqueada)
        <div class="mt-2 px-[9px] py-[7px] rounded-tile border-l-2"
             style="background: var(--bloqueio-tint); border-color: rgb(var(--bloqueio))">
            <div class="flex items-center gap-1.5">
                <span class="h-3 w-3 shrink-0" style="color: rgb(var(--bloqueio))"><x-nav-icon name="cadeado-fechado" :peso="1.8" /></span>
                <span class="flex-1 min-w-0 font-mono text-[9.5px] font-semibold uppercase tracking-[0.08em] truncate"
                      style="color: rgb(var(--bloqueio))">{{ $tarefa->rotuloDoBloqueio() }}</span>

                <form method="POST" data-parcial action="{{ route('tarefas.bloquear', $tarefa) }}" @click.stop>
                    @csrf
                    <button type="submit" title="Destravar tarefa" aria-label="Destravar tarefa"
                            class="shrink-0 h-5 w-5 rounded-badge border flex items-center justify-center transition hover:bg-chip"
                            style="border-color: var(--bloqueio-line); color: rgb(var(--bloqueio))">
                        <span class="h-[11px] w-[11px]"><x-nav-icon name="cadeado-aberto" :peso="1.9" /></span>
                    </button>
                </form>
            </div>

            <p class="mt-1 text-[11.5px] leading-[1.4] text-ink line-clamp-2" title="{{ $tarefa->bloqueio_motivo }}">
                {{ $tarefa->bloqueio_motivo }}
            </p>
        </div>
    @endif

    {{--
        O veredito do portão, direto no card.

        Ele já existia no detalhe da tarefa — e só lá. Quem revisa uma coluna de
        cards em staging tinha de abrir cada tarefa para carimbar aprovado, e
        abrir-fechar-abrir por um veredito de um clique é o atrito que faz o
        teste ficar sem registro (e o portão seguinte recusar a passagem sem
        ninguém entender por quê).

        Mesmo padrão do Responder da tarja de pergunta: a ação mora no card, e
        o Reprovar abre o campo ali mesmo — o motor recusa reprovação sem notas,
        e mandar a pessoa ao detalhe para escrevê-las devolveria o atrito pela
        outra ponta.

        Some quando bloqueada, como o banner do detalhe: travada, o teste não é
        o assunto.
    --}}
    @php
        $noPortaoDeVeredito = $tarefa->tipo === 'desenvolvimento'
            && in_array($tarefa->status, \App\Models\Tarefa::PORTOES_DE_VEREDITO, true)
            && ! $bloqueada;

        $veredito = $noPortaoDeVeredito ? $tarefa->testeDestaPassagem() : null;
        // A concordância do ambiente: "Produção" é feminino e "Staging" não, e
        // um `aprovado` fixo escrevia "Produção reprovado" no card. `$noAr` vem
        // do bloco da faixa de exame, mais acima — a mesma pergunta respondida
        // duas vezes é a que diverge quando alguém mexer numa só.
        $ambiente = $noAr ? 'Produção' : 'Staging';
    @endphp

    @if ($noPortaoDeVeredito)
        @if ($veredito)
            {{-- Registrado: o card diz o veredito e quem assinou, e os botões
                 saem. Refazer é no detalhe — carimbar de novo por engano, num
                 card que se rola, é fácil demais. --}}
            <div class="mt-2 flex items-center gap-1.5 px-[9px] py-[7px] rounded-tile border-l-2"
                 style="background: var(--{{ $veredito->aprovado ? 'good' : 'retorno' }}-tint);
                        border-color: rgb(var(--{{ $veredito->aprovado ? 'good' : 'retorno' }}))">
                <span class="h-3 w-3 shrink-0" style="color: rgb(var(--{{ $veredito->aprovado ? 'good' : 'retorno' }}))">
                    <x-nav-icon :name="$veredito->aprovado ? 'check-circle' : 'alert-triangle'" :peso="1.9" />
                </span>
                <p class="flex-1 min-w-0 text-[12px] leading-[1.4] truncate">
                    <span style="color: rgb(var(--{{ $veredito->aprovado ? 'good' : 'retorno' }}))">
                        {{ $ambiente }} {{ ($veredito->aprovado ? 'aprovad' : 'reprovad').($noAr ? 'a' : 'o') }}
                    </span>
                    <span class="text-ink-mute">por {{ $veredito->autor?->name ?? 'alguém' }}</span>
                </p>
            </div>
        @else
            {{-- `@click.stop` no invólucro inteiro: o card abre a tarefa no
                 clique, e sem isso carimbar abriria o modal por cima do gesto.
                 `data-parcial` redesenha o quadro no lugar, mantendo a rolagem
                 da coluna — o mesmo motivo do Responder. --}}
            <div x-data="{ reprovando: false }" @click.stop class="mt-2">
                <div x-show="! reprovando" class="flex gap-1.5">
                    <form method="POST" data-parcial action="{{ route('tarefas.testar', $tarefa) }}" class="flex-1">
                        @csrf
                        <input type="hidden" name="aprovado" value="1">
                        <button type="submit"
                                class="w-full h-[26px] rounded-tile border text-[11.5px] font-semibold transition hover:bg-chip"
                                style="border-color: var(--good-line); color: rgb(var(--good))">
                            Aprovar {{ $noAr ? 'no ar' : 'staging' }}
                        </button>
                    </form>

                    <button type="button"
                            @click="reprovando = true; $nextTick(() => $refs.notas.focus())"
                            class="shrink-0 h-[26px] px-2.5 rounded-tile border text-[11.5px] font-semibold transition hover:bg-chip"
                            style="border-color: var(--retorno-line); color: rgb(var(--retorno))">
                        Reprovar
                    </button>
                </div>

                {{-- Reprovar sem dizer o quê manda quem recebe abrir o ambiente
                     e adivinhar — o motor recusa, e o campo é o que evita a
                     recusa em vez de explicá-la depois. --}}
                <form x-show="reprovando" x-cloak method="POST" data-parcial
                      action="{{ route('tarefas.testar', $tarefa) }}">
                    @csrf
                    <input type="hidden" name="aprovado" value="0">
                    <textarea x-ref="notas" name="notas" rows="2" required
                              placeholder="O que falhou {{ $noAr ? 'em produção' : 'no staging' }}…"
                              @keydown.escape.stop="reprovando = false"
                              class="block w-full px-[9px] py-[7px] rounded-tile bg-input border text-ink
                                     text-[12px] leading-[1.45] resize-y focus:ring-0"
                              style="border-color: var(--retorno-line)"></textarea>
                    <div class="mt-1.5 flex gap-1.5">
                        <button type="button" @click="reprovando = false"
                                class="shrink-0 h-[26px] px-2.5 rounded-tile border border-btn-line
                                       text-ink-dim text-[11.5px] font-semibold transition hover:bg-chip">
                            Cancelar
                        </button>
                        <button type="submit"
                                class="flex-1 h-[26px] rounded-tile text-on-brand text-[11.5px] font-semibold transition hover:opacity-90"
                                style="background: rgb(var(--retorno))">
                            Reprovar {{ $noAr ? 'no ar' : 'staging' }}
                        </button>
                    </div>
                </form>
            </div>
        @endif
    @endif

    {{--
        O rodapé.

        As duas vagas trocaram de dono: o SISTEMA fica no círculo e o
        RESPONSÁVEL no texto. A marca do produto é reconhecida de relance, sem
        ler — é para isso que ela existe —, enquanto duas iniciais no círculo
        exigiam decorar quem é "JR" ou parar o mouse em cima para descobrir. O
        nome da pessoa, que não tem símbolo equivalente, fica onde há largura
        para ser lido. Nenhum dos dois perdeu o `title` com o valor inteiro.

        `min-width:56px` no nome não é enfeite: sem ele o flex encolhe o texto
        até "Joa…" para caber os selos, e o card perde justamente o dado que
        diz com quem a tarefa está.
    --}}
    <div class="mt-[9px] pt-[9px] border-t border-rule flex items-center gap-[7px]">
        {{-- Sem sistema, o círculo é tracejado e vazio: a lacuna se vê, em vez
             de virar uma marca emprestada de outro produto. O ícone em si vem
             do `x-marca-sistema` — o mapa de qual arquivo serve a qual slug
             mora lá, e uma segunda cópia aqui divergiria na primeira marca
             nova. --}}
        <span title="{{ $tarefa->sistema?->nome ?? 'Sem sistema' }}"
              class="shrink-0 h-[21px] w-[21px] rounded-full flex items-center justify-center overflow-hidden"
              style="{{ $tarefa->sistema
                  ? 'background: var(--chip)'
                  : 'background: transparent; border: 1px dashed rgb(var(--ink-faint))' }}">
            @if ($tarefa->sistema)
                <x-marca-sistema :sistema="$tarefa->sistema" />
            @endif
        </span>

        {{-- Sem responsável, a frase é dita por extenso (AC-130): a informação
             é afirmada, não deduzida da ausência do nome. --}}
        <span class="flex-1 text-[11.5px] truncate {{ $responsavel ? 'text-ink-mute' : 'text-ink-faint' }}"
              style="min-width: 56px"
              title="{{ $responsavel?->name ?? 'Sem responsável' }}">
            {{ $nomeCurto ?? 'Sem responsável' }}
        </span>

        {{--
            Os selos informativos, num contêiner que DERRUBA selo inteiro
            quando falta largura (armadilha 19: selo ou aparece inteiro ou não
            aparece). O rodapé é `nowrap` com piso de 56px no nome e botões
            intocáveis — no pior caso ("31m" + checklist + conversa + "11"
            anexos, e o Concluir que quem triaga vê em todo card), a soma
            passava da linha e o estouro cortava o ÚLTIMO BOTÃO pela metade.

            O truque: `flex-wrap` + `overflow-hidden` + altura de UMA linha —
            o selo que não coube quebra para uma segunda linha que não existe,
            sumindo por inteiro. Os 19px são a altura do próprio selo
            (py-0.5 + fonte mono de 10px). A ordem é a prioridade: o tempo na
            etapa é SINAL (envelhecimento) e cai por último; as contagens
            moram completas dentro da tarefa.
        --}}
        {{--
            O vão entre selos é de 4px, e não os 7px do rodapé.

            Com 7px o segundo selo NUNCA cabia. Medido no quadro: a tira recebe
            49px e a soma dava 51 (tempo 24 + vão 7 + selo 20), então o segundo
            quebrava para a segunda linha que este `overflow-hidden` corta. O
            defeito é anterior ao vínculo — quem sumia era o selo de conversa, e
            some em silêncio, que é o pior jeito de sumir. Com 4px a soma dá 48
            e a tira nem precisa encolher.

            4px não é valor novo: é o vão do grupo de botões, dois elementos à
            direita nesta mesma linha (`TAREFAS-SPEC.md`, "Rodapé"). O que a
            spec fixa em 7px é o vão ENTRE OS BLOCOS do rodapé — ícone, nome,
            selos, botões —, e esse continua 7px. Este é o vão de dentro de um
            bloco, que a spec não tinha porque desenhou os selos soltos na
            linha.

            Três selos ainda não cabem, e isso continua certo: aí a queda é o
            comportamento que a armadilha 19 pede, não um acidente de 2px.
        --}}
        <div class="min-w-0 shrink h-[19px] flex flex-wrap items-center gap-[4px] overflow-hidden">
            {{-- O selo continua curto ("17h") — é o sinal de envelhecimento,
                 e data completa ali estouraria a linha (armadilha 19). O
                 INSTANTE absoluto vai no title: quem quer saber "desde
                 quando" para o mouse em cima, sem custar largura. --}}
            <span title="Na etapa desde {{ $entrouNaEtapaEm->format('d/m/Y H:i') }} · há {{ $tempoNaEtapa }}"
                  class="shrink-0 px-1.5 py-0.5 rounded-badge font-sans tabular text-[10px] font-semibold"
                  style="{{ $estiloDoTempo }}">
                {{ $tempoNaEtapa }}
            </span>

            @if ($progresso)
                <span class="shrink-0 flex items-center gap-[3px] font-sans tabular text-[10px]"
                      title="{{ $progresso['feitos'] }} de {{ $progresso['total'] }} itens concluídos"
                      style="color: {{ $progresso['feitos'] === $progresso['total'] ? 'rgb(var(--good))' : 'rgb(var(--ink-mute))' }}">
                    <span class="h-[11px] w-[11px]"><x-nav-icon name="check-circle" :peso="1.9" /></span>
                    {{ $progresso['feitos'] }}/{{ $progresso['total'] }}
                </span>
            @endif

            @if ($totalComentarios > 0)
                <span class="shrink-0 flex items-center gap-[3px] font-sans tabular text-[10px] text-ink-mute"
                      title="{{ $totalComentarios }} comentário{{ $totalComentarios === 1 ? '' : 's' }}">
                    <span class="h-[11px] w-[11px]"><x-nav-icon name="balao" :peso="1.8" /></span>
                    {{ $totalComentarios }}
                </span>
            @endif

            {{-- Contagem, e não miniatura (US-064): a faixa com o primeiro print
                 deixaria o card ~46px mais alto, e a coluna cabe menos cards por
                 tela justamente na etapa em que mais se anexa arquivo. O selo diz
                 que há o que ver; ver é dentro da tarefa.

                 Um selo só para print, log e planilha: separá-los daria três
                 contagens numa linha que já carrega checklist, conversa e três
                 botões — e a distinção entre eles não muda nada de fora do card. --}}
            @if ($totalAnexos > 0)
                <span class="shrink-0 flex items-center gap-[3px] font-sans tabular text-[10px] text-ink-mute"
                      title="{{ $totalAnexos }} {{ $totalAnexos === 1 ? 'anexo' : 'anexos' }}">
                    <span class="h-[11px] w-[11px]"><x-nav-icon name="paperclip" :peso="1.8" /></span>
                    {{ $totalAnexos }}
                </span>
            @endif

        </div>

        {{--
            Os três botões. Bloquear é SEMPRE válido — travar não é mover, e não
            depende de para onde a tarefa pode ir. Concluir só aparece onde o
            fluxo permite: fixo, ficaria morto na maioria dos cards, e botão que
            quase nunca funciona ensina a não clicar em nenhum.
        --}}
        <div class="shrink-0 ml-auto flex items-center gap-1">
            @unless ($bloqueada)
                {{-- Abre o painel em vez de enviar: travar exige o motivo, e um
                     POST daqui seria recusado com uma frase que ninguém pediu. --}}
                <button type="button" @click.stop="abrirPendente({{ $tarefa->id }}, 'bloqueio', '{{ $tarefa->status }}', '{{ $tarefa->tipo }}')"
                        title="Bloquear tarefa" aria-label="Bloquear tarefa"
                        class="h-5 w-5 rounded-badge border border-line flex items-center justify-center
                               text-ink-mute transition hover:text-bloqueio hover:border-bloqueio-line">
                    <span class="h-[11px] w-[11px]"><x-nav-icon name="cadeado-fechado" :peso="1.9" /></span>
                </button>
            @endunless

            @if (in_array('concluida', $transicoes ?? [], true) && $tarefa->concluirCabeNestaEtapa())
                <button type="button" @click.stop="abrirPendente({{ $tarefa->id }}, 'concluida', '{{ $tarefa->status }}', '{{ $tarefa->tipo }}')"
                        title="Concluir tarefa" aria-label="Concluir tarefa"
                        class="h-5 w-5 rounded-badge border flex items-center justify-center transition"
                        style="border-color: var(--good-line); background: var(--good-tint); color: rgb(var(--good))">
                    <span class="h-[11px] w-[11px]"><x-nav-icon name="check" :peso="2.2" /></span>
                </button>
            @endif

            @if (! empty($transicoes ?? []))
                <button type="button" @click.stop="menuAberto = ! menuAberto"
                        title="Mover de etapa · atalho M" aria-label="Mover de etapa"
                        class="h-5 w-5 rounded-badge border flex items-center justify-center transition"
                        :style="menuAberto
                            ? 'border-color: rgb(var(--brand) / 0.5); color: rgb(var(--brand-text))'
                            : 'border-color: var(--btn-line); color: rgb(var(--ink-faint))'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         class="h-[11px] w-[11px] transition-transform duration-150"
                         :class="menuAberto && 'rotate-180'">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>
            @endif
        </div>
    </div>

    {{--
        Onde o pedido de motivo é desenhado — o lugar, não o formulário.

        O formulário morava aqui, em cada card, guardado por
        `pendente.id === {id}`. Mas `pendente` é estado do QUADRO: um painel por
        vez, nunca dois. Cada card baixava 4090 bytes de um formulário que 119
        deles nunca abririam — 479 KB num quadro de 120 tarefas.

        Ele continua aparecendo AQUI DENTRO, e isso é requisito: o molde único
        (`_painel-motivo`) é clonado para dentro deste div quando a tarefa pede
        motivo. O painel já foi um bloco flutuante ancorado ao rodapé do quadro,
        e foi revertido porque a pessoa soltava o card na terceira coluna e o
        pedido de texto aparecia lá embaixo, longe do card de que falava.

        Vazio não ocupa nada: o espaçamento é margem do formulário, não daqui.
    --}}
    <div data-motivo="{{ $tarefa->id }}"></div>

    {{--
        O menu mora DENTRO do `<article>`: fora dele, o bloco ficava solto
        abaixo da borda do card e lia-se como um controle do quadro, não da
        tarefa — ainda mais com outro card logo abaixo, à mesma distância.
    --}}
    @include('tarefas._mover', ['tarefa' => $tarefa, 'transicoes' => $transicoes ?? []])
</article>
