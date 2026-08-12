@php
    /**
     * O cabeçalho de uma etapa: nome, contador e a notícia da coluna.
     *
     * Partial próprio porque em raias ele aparece UMA vez, fixo no topo do
     * quadro, enquanto os cards se repetem faixa a faixa. Repetido em cada
     * raia, ele viraria a maior parte da tela.
     *
     * As medidas são as do `design/AlfaMatriz Tarefas.dc.html`: padding
     * 9px 8px 9px 12px, ponto de 7px, nome em Space Grotesk 13.5px/600,
     * contador de 19px de altura e a linha de aviso em mono 9.5px.
     *
     * Espera: $etapa.
     */
    $corDoContador = $etapa['acimaDoLimite'] ? 'warn' : $etapa['cor'];

    /**
     * A segunda linha, com a prioridade declarada.
     *
     * O alerta ganha do portão: "acima do limite de 3" é notícia de agora, e o
     * portão é a descrição fixa da coluna — quem já sabe onde está não precisa
     * reler o que a etapa examina, mas precisa saber que ela estourou. Sem a
     * disputa resolvida aqui, as duas linhas apareceriam juntas e a coluna
     * mudaria de altura conforme o dia.
     */
    [$aviso, $corDoAviso] = match (true) {
        $etapa['acimaDoLimite'] => ['acima do limite de '.$etapa['limite'], 'warn'],
        $etapa['aguardandoTriagem'] > 0 => [$etapa['aguardandoTriagem'].' aguardando triagem', 'warn'],
        default => [\App\Models\Tarefa::PORTAO_DA_ETAPA[$etapa['chave']] ?? null, 'ink-faint'],
    };
@endphp

<header class="shrink-0 pt-[9px] pb-[9px] pl-3 pr-2">
    <div class="flex items-center gap-2">
        <span class="h-[7px] w-[7px] shrink-0 rounded-full"
              style="background: rgb(var(--{{ $etapa['cor'] }}))"></span>

        <h3 class="min-w-0 flex-1 truncate font-display text-[13.5px] font-semibold text-ink">{{ $etapa['label'] }}</h3>

        {{--
            O contador vira "4/3" onde há limite de WIP, e tinge de âmbar ao
            estourar. O numerador é o que ANDA: a tarefa travada não ocupa vaga,
            porque o limite existe para conter trabalho começado em paralelo, e
            tarefa parada não está sendo tocada por ninguém — o `title` diz
            quantas ficaram fora da conta, senão o número parece errado para
            quem contou os cards na tela.
        --}}
        <span class="shrink-0 h-[19px] px-1.5 rounded-full flex items-center justify-center
                     font-mono text-[10px] font-semibold"
              style="background: rgb(var(--{{ $corDoContador }}) / var(--tint-alpha)); color: rgb(var(--{{ $corDoContador }}))"
              title="{{ $etapa['limite']
                  ? $etapa['andando'].' em curso de um limite de '.$etapa['limite']
                      .($etapa['quantidade'] > $etapa['andando']
                          ? ' · '.($etapa['quantidade'] - $etapa['andando']).' travada(s) fora da conta'
                          : '')
                  : $etapa['quantidade'].' tarefas' }}">
            @if ($etapa['limite'])
                {{ $etapa['andando'] }}/{{ $etapa['limite'] }}
            @else
                {{ $etapa['quantidade'] }}
            @endif
        </span>
    </div>

    @if ($aviso)
        <p class="mt-[5px] font-mono text-[9.5px] uppercase tracking-[0.1em] whitespace-nowrap overflow-hidden"
           style="color: rgb(var(--{{ $corDoAviso }}))" title="{{ $aviso }}">{{ $aviso }}</p>
    @endif
</header>
