@props([
    'titulo' => null,
    'sub' => null,
    'recuado' => false,  // fundo rebaixado (quadro do funil)
    'solto' => false,    // sem padding interno: quem manda é o conteúdo
])

{{--
    Painel — a superfície padrão do conteúdo. Contraste vem do hairline de 1px
    e do degrau de superfície, não de sombra: o desenho é praticamente sem
    sombra por decisão.

    A faixa de cabeçalho tem 38px fixos para que painéis lado a lado alinhem o
    título mesmo quando um deles tem subtítulo e o outro não.
--}}
<section {{ $attributes->merge([
    'class' => 'rounded-panel border border-line overflow-hidden '.($recuado ? 'bg-board' : 'bg-subtle'),
]) }}>
    @if ($titulo || isset($acoes))
        <div class="h-[38px] flex items-center gap-3 px-4 bg-head border-b border-line">
            @if ($titulo)
                <h2 class="font-display text-[15px] font-semibold text-ink truncate">{{ $titulo }}</h2>
            @endif
            @if ($sub)
                <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint truncate">{{ $sub }}</span>
            @endif
            <div class="ml-auto flex items-center gap-2 shrink-0">{{ $acoes ?? '' }}</div>
        </div>
    @endif

    <div class="{{ $solto ? '' : 'p-4' }}">
        {{ $slot }}
    </div>
</section>
