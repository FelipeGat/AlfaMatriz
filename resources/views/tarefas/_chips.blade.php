@php
    /**
     * Os chips de contagem do cabeçalho do quadro.
     *
     * Partial própria porque eles voltam SOZINHOS: travar uma tarefa muda o
     * "N travadas" e responder uma pergunta muda o "N p/ você", sem que mais
     * nada do quadro mude. Mandar o quadro inteiro para atualizar quatro
     * números custava 906 KB por clique.
     *
     * Espera: $chips.
     */
@endphp

    @foreach ($chips as $chip)
        <a href="{{ $chip['href'] }}" title="{{ $chip['title'] }}"
           class="shrink-0 h-[26px] px-[9px] inline-flex items-center gap-1.5 rounded-tile border
                  font-mono text-[10.5px] font-semibold uppercase tracking-[0.06em] whitespace-nowrap transition
                  {{ $chip['total'] === 0 ? 'opacity-45 hover:opacity-100' : '' }}"
           style="border-color: {{ $chip['borda'] }}; background: {{ $chip['fundo'] }}; color: rgb(var(--{{ $chip['cor'] }}))">
            <span class="h-3 w-3 shrink-0"><x-nav-icon :name="$chip['icone']" :peso="1.9" /></span>
            {{ $chip['label'] }}
        </a>
    @endforeach
