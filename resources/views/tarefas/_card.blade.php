@php
    /**
     * Card do quadro: sistema, prioridade e tempo na etapa atual (US-038).
     * A etapa atual é o evento de `tarefa_eventos` ainda sem saída; tarefa
     * que nunca se moveu (sem evento nenhum) conta a partir da criação.
     */
    $eventoAberto = $tarefa->eventos->firstWhere('saiu_em', null);
    $entrouNaEtapaEm = $eventoAberto?->entrou_em ?? $tarefa->created_at;
    $segundosNaEtapa = $entrouNaEtapaEm->diffInSeconds(now());

    // Mesma régua do histórico (`Tarefa::duracaoCurta`): "3h" precisa querer
    // dizer a mesma coisa nas duas telas.
    $tempoNaEtapa = \App\Models\Tarefa::duracaoCurta((int) $segundosNaEtapa);

    // O envelhecimento passou a valer em TODAS as etapas de trabalho, cada uma
    // com a sua régua (AC-193). Antes só Aberta e Em testes acendiam, com 24h
    // fixas para as duas — mas três dias escrevendo código é trabalho, enquanto
    // três dias esperando alguém testar é fila, e a tarefa que mais apodrece,
    // a de Em andamento parada há dias, não era medida por ninguém.
    //
    // O aviso dobra de peso no dobro do prazo, como já fazia: o primeiro nível
    // chama atenção, o segundo diz que passou da hora.
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

    // Travada manda na borda. O aviso de esquecida mede abandono, e tarefa
    // travada não está abandonada — está esperando, com o porquê escrito. Se os
    // dois disputassem a borda, o card diria "ninguém olha para mim" sobre uma
    // tarefa que alguém parou de propósito.
    $bloqueada = $tarefa->estaBloqueada();
    $corDaBorda = match (true) {
        $bloqueada => 'rgb(var(--warn) / 0.45)',
        (bool) $tomEsquecida => 'rgb(var(--'.$tomEsquecida.') / 0.4)',
        default => 'var(--line)',
    };

    // Um tom por nível, sem repetir: com `baixa` e `media` no mesmo neutro,
    // dois dos quatro níveis ficavam indistinguíveis e a escala perdia o
    // meio. A ordem sobe do mais discreto ao mais grave (AC-126).
    //
    // "A definir" fica fora da escala e no âmbar de alerta: ela não é um grau
    // de gravidade, é a triagem que ainda não aconteceu. Alta desceu para o
    // âmbar mais quente para os dois não se confundirem.
    $tomPrioridade = \App\Models\Tarefa::TOM_DA_PRIORIDADE[$tarefa->prioridade] ?? 'neutro';
@endphp

<article data-tarefa="{{ $tarefa->id }}"
         @if ($nivelEsquecida && ! $bloqueada) data-esquecida="{{ $nivelEsquecida }}" @endif
         @if ($bloqueada) data-bloqueada="1" @endif
         class="rounded-ctl bg-card-grad border p-2.5"
         style="border-color: {{ $corDaBorda }}">
    <div class="flex items-start gap-2">
        <p class="min-w-0 flex-1 truncate text-[13.5px] font-medium text-ink">{{ $tarefa->titulo }}</p>
        {{--
            Só a operacional se anuncia. Marcar as duas encheria o quadro de um
            selo "Desenvolvimento" que não diz nada — quase tudo ali é —, e o
            que se precisa saber de relance é o contrário: por que AQUELE card
            vai pular a coluna de testes. Mesma regra do resumo e do selo de
            comentários: o selo aparece quando tem o que dizer.
        --}}
        @if ($tarefa->tipo === 'operacional')
            <x-badge>Operacional</x-badge>
        @endif
        <x-badge :tom="$tomPrioridade">{{ \App\Models\Tarefa::PRIORIDADES[$tarefa->prioridade] ?? $tarefa->prioridade }}</x-badge>
    </div>

    {{--
        O resumo só ocupa espaço quando existe: `@if` e não uma linha vazia
        reservada, senão todo card sem resumo ganharia um buraco. Uma linha
        truncada — o card é relance, o texto inteiro é assunto do detalhe
        (AC-129). O `title` entrega o resto sem custar altura.
    --}}
    @if (filled($tarefa->resumo))
        <p class="mt-1 text-[12px] leading-snug text-ink-mute truncate"
           title="{{ $tarefa->resumo }}">{{ $tarefa->resumo }}</p>
    @endif

    {{--
        A tarja de bloqueio.

        O motivo ocupa a largura inteira e quebra em até duas linhas — truncado
        numa linha só, o "porquê" existiria apenas no tooltip, e o argumento
        inteiro de tirar o bloqueio da coluna era ele viajar junto da etapa. Um
        card que diz "travada" sem dizer do quê obriga a abrir a tarefa para
        descobrir, que é o trabalho que a tarja existe para poupar.
    --}}
    @if ($bloqueada)
        <div class="mt-2 rounded-[4px] px-2.5 py-[7px] border-l-2"
             style="background: rgb(var(--warn) / var(--tint-alpha)); border-color: rgb(var(--warn))">
            <div class="flex items-center gap-1.5">
                <span class="shrink-0 h-3 w-3" style="color: rgb(var(--warn))">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-3 w-3">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                    </svg>
                </span>
                <span class="flex-1 min-w-0 truncate font-mono text-[9.5px] font-semibold uppercase tracking-caps"
                      style="color: rgb(var(--warn))">
                    {{ $tarefa->rotuloDoBloqueio() }}
                </span>

                {{-- Destravar mora na tarja, e não no menu: quem lê o motivo é
                     quem acabou de descobrir que ele não vale mais. --}}
                <form method="POST" action="{{ route('tarefas.bloquear', $tarefa) }}" @click.stop>
                    @csrf
                    <button type="submit" title="Destravar tarefa" aria-label="Destravar tarefa"
                            class="shrink-0 h-5 w-5 rounded-[3px] border flex items-center justify-center transition hover:bg-chip"
                            style="border-color: rgb(var(--warn) / 0.45); color: rgb(var(--warn))">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" class="h-[11px] w-[11px]">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                    </button>
                </form>
            </div>

            <p class="mt-1 text-[11.5px] leading-snug text-ink line-clamp-2" title="{{ $tarefa->bloqueio_motivo }}">
                {{ $tarefa->bloqueio_motivo }}
            </p>
        </div>
    @endif

    {{--
        O rodapé: quem, onde, há quanto tempo — e o que a tarefa ainda deve.

        O responsável virou avatar porque o nome dele e o nome do sistema
        disputavam a mesma linha, e os dois saíam truncados. A inicial num
        círculo identifica quem já se conhece sem gastar largura; o nome inteiro
        continua no `title`, para quem não reconhece a inicial.

        Sem responsável, o círculo é TRACEJADO e o card diz a frase (AC-130):
        um contorno vazio é símbolo, e símbolo se aprende — a fila de triagem
        não pode depender de quem já aprendeu. Como é a exceção, dizer custa
        largura só nos cards que precisam.
    --}}
    <div class="mt-2 pt-2 border-t border-rule flex items-center gap-2">
        @php $responsavel = $tarefa->responsavel; @endphp

        @if ($responsavel)
            <span class="shrink-0 h-[18px] w-[18px] rounded-full flex items-center justify-center
                         font-mono text-[9px] font-semibold uppercase"
                  style="background: rgb(var(--brand) / var(--tint-alpha)); color: rgb(var(--brand-text))"
                  title="{{ $responsavel->name }}">
                {{ \Illuminate\Support\Str::of($responsavel->name)->squish()->explode(' ')
                    ->take(2)->map(fn ($parte) => mb_substr($parte, 0, 1))->implode('') }}
            </span>
        @else
            <span class="shrink-0 h-[18px] w-[18px] rounded-full border border-dashed"
                  style="border-color: rgb(var(--ink-faint))" aria-hidden="true"></span>
        @endif

        {{--
            Quebra em duas linhas em vez de truncar: com responsável, aqui só
            cabe o nome do sistema e nada quebra — mas na tarefa sem dono a
            frase e o sistema dividem a linha, e truncando some justamente o
            sistema, que é a informação que sobrou.
        --}}
        <span class="min-w-0 line-clamp-2 leading-tight font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
            @if (! $responsavel) Sem responsável · @endif{{ $tarefa->sistema?->nome ?? 'Sem sistema' }}
        </span>

        <span class="ml-auto"></span>

        <x-badge :tom="$tomEsquecida ? $nivelEsquecida : 'neutro'"
                 :title="'Na etapa há '.$tempoNaEtapa">
            {{ $tempoNaEtapa }}
        </x-badge>

        {{-- O progresso do checklist, quando existe checklist. É a única
             pendência do card que não é uma data: diz quanto FALTA, e não há
             quanto tempo está parado. --}}
        @if ($progresso = $tarefa->progressoDoChecklist())
            <x-badge :tom="$progresso['feitos'] === $progresso['total'] ? 'bom' : 'neutro'"
                     :title="$progresso['feitos'].' de '.$progresso['total'].' itens do checklist concluídos'">
                ✓ {{ $progresso['feitos'] }}/{{ $progresso['total'] }}
            </x-badge>
        @endif

        {{--
            O selo de comentários só aparece quando há algum: é o único aviso
            de que existe conversa dentro do card — sem ele, o detalhe que
            alguém escreveu ontem ficaria escondido atrás de um clique que
            ninguém tem motivo para dar. Card sem comentário não ganha um "0"
            para ler.

            Só o número, com a palavra no `title`: escrito por extenso, ele
            empurrava o resto do rodapé para fora em card estreito, e "3" ao
            lado de um balão já é a frase inteira.
        --}}
        @if (($totalComentarios = $tarefa->comentarios->count()) > 0)
            <x-badge :title="$totalComentarios === 1 ? '1 comentário nesta tarefa' : $totalComentarios.' comentários nesta tarefa'">
                ✎ {{ $totalComentarios }}
            </x-badge>
        @endif

        {{--
            O Mover virou chevron. Como texto, ele ocupava uma linha inteira do
            card e comia a largura do nome do sistema — três palavras gastas
            para abrir um menu que quase sempre fica fechado.
        --}}
        @if (! empty($transicoes ?? []))
            <button type="button" @click.stop="menuAberto = ! menuAberto"
                    title="Mover tarefa" aria-label="Mover tarefa"
                    class="shrink-0 h-5 w-5 rounded-control flex items-center justify-center
                           text-ink-faint hover:text-brand transition"
                    :class="menuAberto && 'text-brand'">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" class="h-3 w-3">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                </svg>
            </button>
        @endif
    </div>

    {{--
        O menu mora DENTRO do `<article>`: fora dele, o bloco ficava solto
        abaixo da borda do card e lia-se como um controle do quadro, não da
        tarefa — ainda mais com outro card logo abaixo, à mesma distância.
    --}}
    @include('tarefas._mover', ['tarefa' => $tarefa, 'transicoes' => $transicoes ?? []])
</article>
