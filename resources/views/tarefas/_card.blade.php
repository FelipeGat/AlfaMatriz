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
    $tomEsquecida = ['atencao' => 'warn', 'critico' => 'crit'][$nivelEsquecida] ?? null;

    $bloqueada = $tarefa->estaBloqueada();
    $temPergunta = $tarefa->temPergunta();
    $temRetorno = $tarefa->temRetorno();

    /**
     * A cor da borda, na precedência do protótipo.
     *
     * A ordem não é estética: ela responde "qual notícia manda". Bloqueio e
     * retorno vencem o envelhecimento porque tarefa travada não está
     * abandonada — está esperando, com o porquê escrito; e pergunta vence a
     * régua de tempo pelo mesmo motivo. Seleção pelo teclado entra entre as
     * duas: ela é onde a pessoa ESTÁ, e some assim que ela sai.
     */
    $corDaBorda = match (true) {
        $bloqueada || $temRetorno => 'rgb(var(--warn) / 0.4)',
        $temPergunta => 'rgb(var(--brand) / 0.4)',
        (bool) $tomEsquecida => 'rgb(var(--'.$tomEsquecida.') / 0.4)',
        default => 'var(--line)',
    };

    // Um tom por nível de prioridade, sem repetir (AC-126). "A definir" fica
    // fora da escala, no âmbar de alerta: não é um grau de gravidade, é a
    // triagem que ainda não aconteceu.
    $tomPrioridade = \App\Models\Tarefa::TOM_DA_PRIORIDADE[$tarefa->prioridade] ?? 'neutro';
    $corPrioridade = ['neutro' => null, 'marca' => 'brand', 'ambar' => 'amber',
                      'atencao' => 'warn', 'critico' => 'crit'][$tomPrioridade] ?? null;

    // A pergunta em aberto e o comentário que a abriu, da coleção já carregada:
    // o quadro renderiza dezenas de cards, e uma consulta em cada um é o N+1
    // clássico desta tela.
    $perguntaAberta = $temPergunta ? $tarefa->comentarios->where('pergunta', true)->last() : null;
    $perguntaHa = $temPergunta
        ? \App\Models\Tarefa::duracaoCurta((int) $tarefa->pergunta_em->diffInSeconds(now()))
        : null;

    /**
     * Primeiro e último nome do responsável.
     *
     * O nome inteiro não cabe no rodapé, e o pedaço que sobra tem de ser o que
     * distingue: só o primeiro nome empata duas Julianas do time, e o `title`
     * com o nome completo continua a um passe de mouse. Nome único fica como
     * está — repeti-lo em vez de acusar a falta do sobrenome seria pior.
     */
    $responsavel = $tarefa->responsavel;
    $partesDoNome = $responsavel
        ? \Illuminate\Support\Str::of($responsavel->name)->squish()->explode(' ')->filter()->values()
        : collect();
    $nomeCurto = $partesDoNome->count() > 1
        ? $partesDoNome->first().' '.$partesDoNome->last()
        : $partesDoNome->first();

    $progresso = $tarefa->progressoDoChecklist();
    $totalComentarios = $tarefa->comentarios->count();
    $totalAnexos = $tarefa->anexos->count();
@endphp

{{--
    raio 5px, padding 10px, `panelRaised` de fundo e a sombra de 1px que só
    esta tela usa — o resto do painel decidiu não ter sombra, mas o quadro
    empilha cards sobre um fundo recuado e sem ela eles encostam no board.
--}}
<article data-tarefa="{{ $tarefa->id }}"
         @if ($nivelEsquecida && ! $bloqueada) data-esquecida="{{ $nivelEsquecida }}" @endif
         @if ($bloqueada) data-bloqueada="1" @endif
         class="rounded-[5px] border p-[10px] bg-card-quadro shadow-card"
         style="border-color: {{ $corDaBorda }}">

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

    {{--
        A tarja de pergunta.

        Dúvida na revisão não é impedimento: o PR continua aberto, a tarefa
        continua no WIP, e a tarja é da cor da MARCA — de âmbar, junto do
        bloqueio, ela ensinaria que perguntar é problema.

        O NOME OCUPA LINHA PRÓPRIA. Na primeira linha cabem o rótulo e o tempo;
        "Aguardando resposta de Camila" ali dentro seria truncado justamente na
        parte que importa — quem deve a resposta é a informação inteira da tarja.
    --}}
    @if ($temPergunta)
        <div class="mt-2 px-[9px] py-[7px] rounded-tile border-l-2"
             style="background: rgb(var(--brand) / 0.085); border-color: rgb(var(--brand))">
            <div class="flex items-center gap-1.5">
                <span class="h-3 w-3 shrink-0 text-brand-text"><x-nav-icon name="duvida" :peso="1.9" /></span>
                <span class="flex-1 min-w-0 font-mono text-[9.5px] font-semibold uppercase tracking-[0.08em] text-brand-text whitespace-nowrap">
                    Aguardando resposta
                </span>
                <span class="shrink-0 font-mono text-[9.5px] text-ink-mute">{{ $perguntaHa }}</span>
            </div>

            <div class="mt-1 flex items-center gap-1.5">
                <span class="flex-1 min-w-0 text-[12px] font-semibold text-ink truncate">
                    {{ $tarefa->perguntaPara?->name ?? 'alguém' }}
                </span>
                <span class="shrink-0 px-[5px] py-px rounded-badge font-mono text-[9px] font-semibold whitespace-nowrap"
                      style="{{ $tarefa->conversaEmpacada()
                          ? 'background: rgb(var(--crit) / var(--tint-alpha)); color: rgb(var(--crit))'
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
                <p class="mt-[5px] font-mono text-[9px] uppercase tracking-[0.06em]" style="color: rgb(var(--crit))">
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
                            class="mt-[7px] w-full h-[26px] rounded-tile border border-brand bg-transparent
                                   text-brand-text text-[11.5px] font-semibold transition hover:bg-brand/10">
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
                                  class="block w-full px-[9px] py-[7px] rounded-tile bg-input border border-brand
                                         text-ink text-[12px] leading-[1.45] resize-y focus:ring-0"></textarea>
                        <div class="mt-1.5 flex gap-1.5">
                            <button type="button" @click="respondendo = false"
                                    class="shrink-0 h-[26px] px-2.5 rounded-tile border border-btn-line
                                           text-ink-dim text-[11.5px] font-semibold transition hover:bg-chip">
                                Cancelar
                            </button>
                            <button type="submit"
                                    class="flex-1 h-[26px] rounded-tile bg-brand text-on-brand
                                           text-[11.5px] font-semibold transition hover:bg-brand-bright">
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
         só, na cor da marca como a pergunta: apontado não é problema. --}}
    @if ($tarefa->interlocutor_id
        && in_array($tarefa->status, \App\Models\Tarefa::PORTOES_DE_EXAME, true))
        <div class="mt-2 flex items-center gap-1.5 px-[9px] py-[7px] rounded-tile border-l-2"
             style="background: rgb(var(--brand) / 0.085); border-color: rgb(var(--brand))">
            <span class="h-3 w-3 shrink-0 text-brand-text"><x-nav-icon name="eye" :peso="1.9" /></span>
            <span class="shrink-0 font-mono text-[9.5px] font-semibold uppercase tracking-[0.08em] text-brand-text whitespace-nowrap">
                {{ $tarefa->status === 'em_revisao' ? 'Revisão com' : 'Teste com' }}
            </span>
            <span class="flex-1 min-w-0 text-[12px] font-semibold text-ink truncate">
                {{ $tarefa->interlocutor?->name ?? 'alguém' }}
            </span>
        </div>
    @endif

    {{-- A tarja de retorno nomeia o PORTÃO que reprovou: "Voltou da revisão" e
         "Voltou do staging" descrevem recuperações diferentes, e era esse
         detalhe que a coluna única de Ajustes achatava. --}}
    @if ($temRetorno)
        <div class="mt-2 px-[9px] py-[7px] rounded-tile border-l-2"
             style="background: var(--warn-tint); border-color: rgb(var(--warn))">
            <div class="flex items-center gap-1.5">
                <span class="h-3 w-3 shrink-0" style="color: rgb(var(--warn))"><x-nav-icon name="arrow-uturn-left" :peso="1.9" /></span>
                <span class="flex-1 min-w-0 font-mono text-[9.5px] font-semibold uppercase tracking-[0.08em] truncate"
                      style="color: rgb(var(--warn))">{{ $tarefa->rotuloDoRetorno() }}</span>
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
    --}}
    @if ($bloqueada)
        <div class="mt-2 px-[9px] py-[7px] rounded-tile border-l-2"
             style="background: var(--warn-tint); border-color: rgb(var(--warn))">
            <div class="flex items-center gap-1.5">
                <span class="h-3 w-3 shrink-0" style="color: rgb(var(--warn))"><x-nav-icon name="cadeado-fechado" :peso="1.8" /></span>
                <span class="flex-1 min-w-0 font-mono text-[9.5px] font-semibold uppercase tracking-[0.08em] truncate"
                      style="color: rgb(var(--warn))">{{ $tarefa->rotuloDoBloqueio() }}</span>

                <form method="POST" data-parcial action="{{ route('tarefas.bloquear', $tarefa) }}" @click.stop>
                    @csrf
                    <button type="submit" title="Destravar tarefa" aria-label="Destravar tarefa"
                            class="shrink-0 h-5 w-5 rounded-badge border flex items-center justify-center transition hover:bg-chip"
                            style="border-color: var(--warn-line); color: rgb(var(--warn))">
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

        <span title="Na etapa há {{ $tempoNaEtapa }}"
              class="shrink-0 px-1.5 py-0.5 rounded-badge font-mono text-[10px] font-semibold"
              style="{{ $tomEsquecida
                  ? 'background: rgb(var(--'.$tomEsquecida.') / var(--tint-alpha)); color: rgb(var(--'.$tomEsquecida.'))'
                  : 'background: var(--chip); color: rgb(var(--ink-mute))' }}">
            {{ $tempoNaEtapa }}
        </span>

        @if ($progresso)
            <span class="shrink-0 flex items-center gap-[3px] font-mono text-[10px]"
                  title="{{ $progresso['feitos'] }} de {{ $progresso['total'] }} itens concluídos"
                  style="color: {{ $progresso['feitos'] === $progresso['total'] ? 'rgb(var(--good))' : 'rgb(var(--ink-mute))' }}">
                <span class="h-[11px] w-[11px]"><x-nav-icon name="check-circle" :peso="1.9" /></span>
                {{ $progresso['feitos'] }}/{{ $progresso['total'] }}
            </span>
        @endif

        @if ($totalComentarios > 0)
            <span class="shrink-0 flex items-center gap-[3px] font-mono text-[10px] text-ink-mute"
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
            <span class="shrink-0 flex items-center gap-[3px] font-mono text-[10px] text-ink-mute"
                  title="{{ $totalAnexos }} {{ $totalAnexos === 1 ? 'anexo' : 'anexos' }}">
                <span class="h-[11px] w-[11px]"><x-nav-icon name="paperclip" :peso="1.8" /></span>
                {{ $totalAnexos }}
            </span>
        @endif

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
                               text-ink-mute transition hover:text-warn hover:border-warn-line">
                    <span class="h-[11px] w-[11px]"><x-nav-icon name="cadeado-fechado" :peso="1.9" /></span>
                </button>
            @endunless

            @if (in_array('concluida', $transicoes ?? [], true))
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
