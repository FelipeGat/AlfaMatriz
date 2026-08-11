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

    // AC-093: só Aberta e Em testes ganham destaque de tarefa esquecida.
    $etapasComDestaqueDeEsquecida = ['aberta', 'em_testes'];
    $nivelEsquecida = null;
    if (in_array($tarefa->status, $etapasComDestaqueDeEsquecida, true)) {
        $horasNaEtapa = $segundosNaEtapa / 3600;
        $nivelEsquecida = match (true) {
            $horasNaEtapa >= 48 => 'critico',
            $horasNaEtapa >= 24 => 'atencao',
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
    $tomPrioridade = [
        'baixa' => 'neutro',
        'media' => 'marca',
        'alta' => 'atencao',
        'critica' => 'critico',
    ][$tarefa->prioridade] ?? 'neutro';
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
        A linha de metadados tem SEMPRE os dois segmentos. Antes, tarefa sem
        responsável simplesmente omitia o nome, e a falta era lida por
        comparação com os cards vizinhos — logo na coluna Aberta, que é a fila
        que pede triagem (AC-130). Dito em texto e sem cor de alarme: a borda
        do card já é o canal do aviso de esquecida.
    --}}
    <p class="mt-1 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint truncate">
        {{ $tarefa->sistema?->nome ?? 'Sem sistema' }} · {{ $tarefa->responsavel?->name ?? 'Sem responsável' }}
    </p>

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

    <div class="mt-2 pt-2 border-t border-rule flex items-center gap-2">
        <x-badge :tom="$tomEsquecida ? $nivelEsquecida : 'neutro'"
                 :title="'Na etapa há '.$tempoNaEtapa">
            {{ $tempoNaEtapa }}
        </x-badge>

        {{--
            O selo de comentários só aparece quando há algum: é o único aviso
            de que existe conversa dentro do card — sem ele, o detalhe que
            alguém escreveu ontem ficaria escondido atrás de um clique que
            ninguém tem motivo para dar. Card sem comentário não ganha um
            "0" para ler.
        --}}
        @if (($totalComentarios = $tarefa->comentarios->count()) > 0)
            <x-badge :title="$totalComentarios.' comentário(s) nesta tarefa'">
                {{ $totalComentarios }} {{ $totalComentarios === 1 ? 'comentário' : 'comentários' }}
            </x-badge>
        @endif
    </div>

    {{--
        O menu mora DENTRO do `<article>`: fora dele, o bloco ficava solto
        abaixo da borda do card e lia-se como um controle do quadro, não da
        tarefa — ainda mais com outro card logo abaixo, à mesma distância.
    --}}
    @include('tarefas._mover', ['tarefa' => $tarefa, 'transicoes' => $transicoes ?? []])
</article>
