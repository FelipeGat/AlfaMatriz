@props([
    'titulo' => null,
    'acao' => null,       // slot de ação no cabeçalho (link "Ver todas", etc.)
    'semPadding' => false, // tabelas ocupam o card inteiro
])

{{-- Superfície padrão das telas: borda de 1px e diferença de fundo, sem
     sombra. `overflow-hidden` para a tabela respeitar o raio do card. --}}
<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-card border border-line bg-panel']) }}>
    @if ($titulo || $acao)
        <div class="flex flex-wrap items-center justify-between gap-3 px-6 pt-[22px] {{ $semPadding ? 'pb-4' : 'pb-0' }}">
            @if ($titulo)
                <h3 class="text-[15px] font-semibold text-ink">{{ $titulo }}</h3>
            @endif
            @if ($acao)
                <div class="shrink-0 text-[12px]">{{ $acao }}</div>
            @endif
        </div>
    @endif

    <div class="{{ $semPadding ? '' : 'px-6 pb-[22px] '.($titulo || $acao ? 'pt-4' : 'pt-[22px]') }}">
        {{ $slot }}
    </div>
</div>
