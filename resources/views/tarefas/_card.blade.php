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

    $responsavel = $tarefa->responsavel;
    $iniciais = $responsavel
        ? \Illuminate\Support\Str::of($responsavel->name)->explode(' ')
            ->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('')
        : '';

    // A cor do avatar sai do NOME, e não do id: o mesmo rosto guarda o mesmo
    // tom entre telas e entre bancos, e a paleta tem quatro entradas para as
    // pessoas não virarem todas o mesmo círculo azul.
    $paletaAvatar = ['brand', 'accent', 'amber', 'good'];
    $corAvatar = $responsavel ? $paletaAvatar[mb_strlen($responsavel->name) % 4] : null;

    $progresso = $tarefa->progressoDoChecklist();
    $totalComentarios = $tarefa->comentarios->count();
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
        <p class="min-w-0 flex-1 text-[13.5px] font-medium leading-[1.35] text-ink">{{ $tarefa->titulo }}</p>

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
                <x-nav-icon name="duvida" :peso="1.9" class="h-3 w-3 shrink-0 text-brand-text" />
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

                    <form x-show="respondendo" x-cloak method="POST"
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

    {{-- A tarja de retorno nomeia o PORTÃO que reprovou: "Voltou da revisão" e
         "Voltou do staging" descrevem recuperações diferentes, e era esse
         detalhe que a coluna única de Ajustes achatava. --}}
    @if ($temRetorno)
        <div class="mt-2 px-[9px] py-[7px] rounded-tile border-l-2"
             style="background: var(--warn-tint); border-color: rgb(var(--warn))">
            <div class="flex items-center gap-1.5">
                <x-nav-icon name="arrow-uturn-left" :peso="1.9" class="h-3 w-3 shrink-0" style="color: rgb(var(--warn))" />
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
                <x-nav-icon name="cadeado-fechado" :peso="1.8" class="h-3 w-3 shrink-0" style="color: rgb(var(--warn))" />
                <span class="flex-1 min-w-0 font-mono text-[9.5px] font-semibold uppercase tracking-[0.08em] truncate"
                      style="color: rgb(var(--warn))">{{ $tarefa->rotuloDoBloqueio() }}</span>

                <form method="POST" action="{{ route('tarefas.bloquear', $tarefa) }}" @click.stop>
                    @csrf
                    <button type="submit" title="Destravar tarefa" aria-label="Destravar tarefa"
                            class="shrink-0 h-5 w-5 rounded-badge border flex items-center justify-center transition hover:bg-chip"
                            style="border-color: var(--warn-line); color: rgb(var(--warn))">
                        <x-nav-icon name="cadeado-aberto" :peso="1.9" class="h-[11px] w-[11px]" />
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

        `min-width:56px` no sistema não é enfeite: sem ele o flex encolhe o nome
        até "Alfa…" para caber os selos, e o card perde justamente o dado que
        diz de qual produto ele fala.
    --}}
    <div class="mt-[9px] pt-[9px] border-t border-rule flex items-center gap-[7px]">
        {{-- Sem responsável, o círculo é tracejado e vazio (AC-130): a lacuna
             se vê, em vez de virar uma inicial inventada. --}}
        <span title="{{ $responsavel?->name ?? 'Sem responsável' }}"
              class="shrink-0 h-[21px] w-[21px] rounded-full flex items-center justify-center text-[9px] font-semibold"
              style="{{ $responsavel
                  ? 'background: rgb(var(--'.$corAvatar.') / 0.18); color: rgb(var(--ink))'
                  : 'background: transparent; border: 1px dashed rgb(var(--ink-faint)); color: rgb(var(--ink-faint))' }}">
            {{ $iniciais }}
        </span>

        <span class="flex-1 text-[11.5px] text-ink-mute truncate" style="min-width: 56px"
              title="{{ $tarefa->sistema?->nome ?? 'Sem sistema' }}">
            {{ $tarefa->sistema?->nome ?? 'Sem sistema' }}
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
                <x-nav-icon name="check-circle" :peso="1.9" class="h-[11px] w-[11px]" />
                {{ $progresso['feitos'] }}/{{ $progresso['total'] }}
            </span>
        @endif

        @if ($totalComentarios > 0)
            <span class="shrink-0 flex items-center gap-[3px] font-mono text-[10px] text-ink-mute"
                  title="{{ $totalComentarios }} comentário{{ $totalComentarios === 1 ? '' : 's' }}">
                <x-nav-icon name="balao" :peso="1.8" class="h-[11px] w-[11px]" />
                {{ $totalComentarios }}
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
                <button type="button" @click.stop="abrirPendente({{ $tarefa->id }}, 'bloqueio')"
                        title="Bloquear tarefa" aria-label="Bloquear tarefa"
                        class="h-5 w-5 rounded-badge border border-line flex items-center justify-center
                               text-ink-mute transition hover:text-warn hover:border-warn-line">
                    <x-nav-icon name="cadeado-fechado" :peso="1.9" class="h-[11px] w-[11px]" />
                </button>
            @endunless

            @if (in_array('concluida', $transicoes ?? [], true))
                <button type="button" @click.stop="abrirPendente({{ $tarefa->id }}, 'concluida')"
                        title="Concluir tarefa" aria-label="Concluir tarefa"
                        class="h-5 w-5 rounded-badge border flex items-center justify-center transition"
                        style="border-color: var(--good-line); background: var(--good-tint); color: rgb(var(--good))">
                    <x-nav-icon name="check" :peso="2.2" class="h-[11px] w-[11px]" />
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
        O painel de motivo, DENTRO do card.

        Ele já foi um bloco flutuante ancorado ao rodapé do quadro, e isso
        custava a ligação com o gesto: a pessoa soltava o card na terceira
        coluna e o pedido de texto aparecia lá embaixo, longe do card de que
        falava. Aqui ele nasce colado no card — o texto é sobre aquela tarefa e
        aparece nela.

        A borda esquerda de 2px na cor do destino é a mesma gramática das
        tarjas: o painel é uma notícia do card, não uma janela por cima dele.
    --}}
    <template x-if="pendente && pendente.id === {{ $tarefa->id }}">
        <form method="POST" :action="pendente.acao" @submit="enviandoPendente = true" @click.stop
              class="mt-[10px] p-[10px] rounded-[5px] border border-l-2"
              :style="`background: rgb(var(--${pendente.cor}) / calc(var(--tint-alpha) / 2));
                       border-color: rgb(var(--${pendente.cor}) / 0.4);
                       border-left-color: rgb(var(--${pendente.cor}))`">
            @csrf
            <input type="hidden" name="status" :value="pendente.status">
            <input type="hidden" name="de_status" :value="pendente.de">

            {{-- "Devolvendo para Em andamento": o verbo diz o que está
                 acontecendo e o destino vem em negrito, na cor dele.
                 "Confirmar" sozinho é o que se aperta sem ler. --}}
            <div class="flex items-center gap-2">
                <span class="h-[7px] w-[7px] shrink-0 rounded-full"
                      :style="`background: rgb(var(--${pendente.cor}))`"></span>
                <p class="flex-1 min-w-0 text-[12.5px] text-ink">
                    <span x-text="pendente.verbo"></span>
                    <strong class="font-semibold" :style="`color: rgb(var(--${pendente.cor}))`"
                            x-text="pendente.label"></strong>
                </p>
                <button type="button" @click.stop="fecharPendente()" title="Cancelar" aria-label="Cancelar"
                        class="shrink-0 h-5 w-5 rounded-badge flex items-center justify-center text-ink-mute
                               transition hover:text-ink">
                    <x-nav-icon name="x-mark" :peso="2" class="h-3 w-3" />
                </button>
            </div>

            {{-- Uma linha dizendo POR QUE o texto está sendo pedido: sem ela o
                 campo é uma exigência sem causa, e a pessoa escreve qualquer
                 coisa para passar. --}}
            <p class="mt-1.5 text-[11.5px] leading-[1.45] text-ink-dim" x-text="pendente.porque"></p>

            <template x-if="pendente.campo">
                <textarea x-ref="textoPendente" :name="pendente.campo" rows="2"
                          :placeholder="pendente.placeholder" x-model="textoPendente"
                          class="mt-2 block w-full px-[9px] py-[7px] rounded-[5px] bg-input text-ink
                                 text-[12px] leading-[1.45] resize-y focus:ring-0"
                          :style="`border: 1px solid rgb(var(--${pendente.cor}) / 0.4)`"></textarea>
            </template>

            {{-- O carimbo do staging. Nasce marcado: o caminho comum é ter
                 validado, e quem NÃO validou é quem precisa agir para dizer. --}}
            <template x-if="pendente.pedeAprovacao">
                <label class="mt-2 flex items-center gap-[7px] text-[11.5px] text-ink-dim cursor-pointer">
                    <input type="checkbox" name="relatorio_aprovado" value="1" checked
                           class="h-[14px] w-[14px] rounded-[3px] bg-input border-btn-line text-brand focus:ring-0">
                    Validado no staging
                </label>
            </template>

            {{-- O botão ocupa a largura INTEIRA. Com um Cancelar ao lado ele
                 era espremido a ~110px e "Liberar para o admin subir" virava
                 "Liberar para o a…" — desistir é o × do cabeçalho ou Esc. --}}
            <div class="mt-2 flex flex-col gap-[7px]">
                <p class="font-mono text-[9.5px] uppercase tracking-[0.08em] truncate"
                   :style="`color: ${(pendente.obrigatorio && ! textoPendente.trim())
                       ? 'rgb(var(--warn))' : 'rgb(var(--ink-faint))'}`"
                   x-text="pendente.obrigatorio ? 'obrigatório' : 'opcional'"></p>

                <button type="submit"
                        :disabled="(pendente.obrigatorio && ! textoPendente.trim()) || enviandoPendente"
                        class="w-full h-[30px] px-2.5 rounded-[5px] text-[12px] font-semibold whitespace-nowrap
                               transition disabled:cursor-not-allowed"
                        :style="(pendente.obrigatorio && ! textoPendente.trim()) || enviandoPendente
                            ? 'background: rgb(var(--line)); color: rgb(var(--ink-faint))'
                            : `background: rgb(var(--${pendente.cor})); color: rgb(var(--on-brand))`"
                        x-text="enviandoPendente ? 'Enviando…' : pendente.acaoRotulo"></button>
            </div>
        </form>
    </template>

    {{--
        O menu mora DENTRO do `<article>`: fora dele, o bloco ficava solto
        abaixo da borda do card e lia-se como um controle do quadro, não da
        tarefa — ainda mais com outro card logo abaixo, à mesma distância.
    --}}
    @include('tarefas._mover', ['tarefa' => $tarefa, 'transicoes' => $transicoes ?? []])
</article>
