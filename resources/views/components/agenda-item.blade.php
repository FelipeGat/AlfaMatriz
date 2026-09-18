@props([
    'item',                    // o array que o AgendaService monta
    'variante' => 'coluna',    // coluna | linha | ponto
    'arrastavel' => false,     // só o prazo, e só para quem faz triagem
])

@php
    /**
     * Um item da Agenda — prazo de tarefa OU compromisso.
     *
     * Componente único para as quatro superfícies (coluna da Semana, célula do
     * Mês, linha da Lista, cartão do drawer) porque o que muda entre elas é a
     * FORMA, não o significado: a mesma tarefa travada precisa aparecer travada
     * nas quatro, e um mapa de cor por superfície faria o mesmo dado contar
     * histórias diferentes conforme onde se olha.
     *
     * Os cinco primeiros tons são os de PRIORIDADE, iguais aos da borda do card
     * no quadro (`tarefas/_card.blade.php`) — a tarefa mantém o grau que tem
     * lá. Os quatro últimos são de ESTADO e vencem a prioridade quando
     * aparecem; quem decide isso é `Tarefa::marcaDaAgenda`, não esta view.
     *
     * `neutro` é o único sem cor cheia: prioridade Baixa numa lista inteira de
     * barras coloridas é o item que não precisa chamar, e pintá-lo faria os
     * outros pararem de chamar.
     */
    $token = match ($item['tom']) {
        'marca' => 'brand',
        'ambar' => 'amber',
        'triagem' => 'triagem',
        'critico' => 'crit',
        'bloqueio' => 'bloqueio',
        'retorno' => 'retorno',
        'exame' => 'exame',
        'warn' => 'warn',
        default => null,
    };

    $cor = $token ? 'rgb(var(--'.$token.'))' : 'rgb(var(--ink-mute))';
    $barra = $token ? 'rgb(var(--'.$token.'))' : 'var(--line)';
    $tinte = $token
        ? 'rgb(var(--'.$token.') / var(--tint-alpha))'
        : 'var(--chip)';

    // O clique leva a destinos diferentes por FONTE, e é a diferença que a
    // tabela do §19 fixa: prazo abre o detalhe da tarefa, compromisso abre o
    // modal de edição. Confundir os dois é o erro que o rótulo previne.
    $aoClicar = $item['tipo'] === 'tarefa'
        ? "abrirTarefa({$item['id']})"
        : "abrirCompromisso({$item['id']})";
@endphp

@if ($variante === 'ponto')
    {{-- A célula do Mês: só o ponto e o título, porque a célula tem 1/42 da
         grade. O resto do dia mora no drawer, que é o que o clique na célula
         abre — e é por isso que o ponto não tem clique próprio: dois alvos de
         clique numa caixa de 11px acertariam o errado. --}}
    <div class="flex shrink-0 items-center gap-1 overflow-hidden">
        <span class="h-[5px] w-[5px] shrink-0 rounded-full" style="background: {{ $cor }}"></span>
        <span class="min-w-0 truncate text-[10px] text-ink-dim">{{ $item['titulo'] }}</span>
    </div>

@elseif ($variante === 'linha')
    {{-- A Lista: rótulo à esquerda em largura FIXA, título elástico no meio,
         meta à direita. O rótulo leva `w-[74px]` e não `flex-1` porque é ele
         que alinha a coluna inteira — com largura de conteúdo, "Tarefa" e
         "Tarefa · Bloqueada" empurrariam cada linha para um lugar diferente. --}}
    <button type="button" @click="{{ $aoClicar }}"
            class="flex w-full items-center gap-[9px] rounded-control px-3 py-2 text-left transition hover:brightness-110"
            style="background: {{ $tinte }}; border-left: 2px solid {{ $barra }}">
        <span class="w-[74px] shrink-0 font-mono text-[9px] font-bold uppercase tracking-[0.06em]"
              style="color: {{ $cor }}">{{ $item['rotulo'] }}</span>
        <span class="min-w-0 flex-1 truncate text-[13px] font-semibold text-ink">{{ $item['titulo'] }}</span>
        <span class="shrink-0 whitespace-nowrap text-[11.5px] text-ink-mute">{{ $item['meta'] }}</span>
    </button>

@else
    {{-- A coluna da Semana e o cartão do drawer: três linhas empilhadas.

         Todo texto leva `truncate` (que é `nowrap` + `overflow:hidden` +
         reticências) e não só `whitespace-nowrap`: sem o overflow o título
         longo PINTA POR CIMA da coluna vizinha em vez de ser cortado, e a
         coluna de sete não tem para onde crescer. --}}
    <div @if ($arrastavel)
             draggable="true"
             @dragstart="arrastar($event, {{ $item['id'] }}, '{{ $item['data'] }}')"
             @dragend="arrastando = null"
         @endif
         @click="{{ $aoClicar }}"
         class="cursor-pointer rounded-ctl px-2 py-1.5 transition hover:brightness-110
                @if ($arrastavel) cursor-grab active:cursor-grabbing @endif"
         style="background: {{ $tinte }}; border-left: 2px solid {{ $barra }}">
        {{-- O rótulo QUEBRA em vez de truncar, ao contrário das duas linhas
             abaixo. Ele é o sinal — "Tarefa · Bloqueada" cortado em "Tarefa ·
             Bloq…" vira um card que anuncia um estado sem dizer qual, e numa
             coluna de 150px ele é cortado sempre. Título e meta continuam
             truncando: ali o começo já identifica, e o resto está no drawer. --}}
        <span class="block font-mono text-[8.5px] font-bold uppercase leading-[1.3] tracking-[0.06em]"
              style="color: {{ $cor }}">{{ $item['rotulo'] }}</span>
        <p class="mt-0.5 truncate text-[11.5px] font-semibold leading-[1.35] text-ink">{{ $item['titulo'] }}</p>
        <p class="mt-0.5 truncate text-[10.5px] text-ink-mute">{{ $item['meta'] }}</p>
    </div>
@endif
