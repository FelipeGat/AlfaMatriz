@php
    /**
     * O cabeçalho de uma etapa: nome, contador e as notícias da coluna.
     *
     * Partial próprio porque em raias ele aparece UMA vez, fixo no topo do
     * quadro, enquanto os cards se repetem faixa a faixa. Repetido em cada
     * raia, ele viraria a maior parte da tela.
     *
     * Espera: $etapa.
     */
    $corDoContador = $etapa['acimaDoLimite'] ? 'warn' : $etapa['cor'];
@endphp

<header class="shrink-0 px-3 py-2.5 border-b border-rule">
    <div class="flex items-center gap-2">
        <span class="h-[7px] w-[7px] shrink-0 rounded-full"
              style="background: rgb(var(--{{ $etapa['cor'] }}))"></span>
        <h3 class="min-w-0 truncate font-display text-[14px] font-semibold text-ink">{{ $etapa['label'] }}</h3>

        {{--
            O contador vira "4/3" onde há limite de WIP, e tinge de âmbar ao
            estourar. O numerador é o que ANDA: a tarefa travada não ocupa vaga,
            porque o limite existe para conter trabalho começado em paralelo, e
            tarefa parada não está sendo tocada por ninguém.
        --}}
        <span class="ml-auto shrink-0 h-5 min-w-[20px] px-1.5 rounded-full font-mono text-[10px] font-semibold leading-5 text-center"
              style="background: rgb(var(--{{ $corDoContador }}) / var(--tint-alpha)); color: rgb(var(--{{ $corDoContador }}))"
              @if ($etapa['acimaDoLimite']) title="Acima do limite de {{ $etapa['limite'] }} tarefas em curso nesta etapa" @endif>
            @if ($etapa['limite'])
                {{ $etapa['andando'] }}/{{ $etapa['limite'] }}
            @else
                {{ $etapa['quantidade'] }}
            @endif
        </span>
    </div>

    {{--
        A segunda linha do cabeçalho, com uma prioridade declarada.

        O aviso ganha do portão quando existe: "acima do limite" é uma notícia
        de agora, e o portão é a descrição fixa da coluna — quem já sabe onde
        está não precisa reler o que a etapa examina, mas precisa saber que ela
        estourou. Sem a disputa resolvida aqui, as duas linhas apareceriam
        juntas e a coluna ganharia duas alturas diferentes conforme o dia.
    --}}
    @if ($etapa['acimaDoLimite'])
        <p class="mt-1 font-mono text-[10px] uppercase tracking-caps" style="color: rgb(var(--warn))">
            acima do limite
        </p>
    @elseif ($etapa['aguardandoTriagem'] > 0)
        <p class="mt-1 font-mono text-[10px] uppercase tracking-caps" style="color: rgb(var(--warn))">
            {{ $etapa['aguardandoTriagem'] }} aguardando triagem
        </p>
    @elseif ($portao = \App\Models\Tarefa::PORTAO_DA_ETAPA[$etapa['chave']] ?? null)
        {{-- Recuada até o nome, e não até a borda: ela descreve a etapa, e
             alinhada com o ponto de cor pareceria um terceiro item da linha
             de cima. --}}
        <p class="mt-1 pl-[15px] font-mono text-[10px] uppercase tracking-caps text-ink-faint truncate"
           title="{{ $portao }}">{{ $portao }}</p>
    @endif
</header>
