@props([
    'icone',
    'titulo',                  // o que a ação faz — vira title e rótulo acessível
    'href' => null,            // ausente = <button>
    'destrutivo' => false,
])

@php
    // Hover neutro usa a superfície de chip; ação destrutiva usa o tingimento
    // de crítico, para a mão hesitar antes de clicar.
    $hover = $destrutivo
        ? 'hover:text-crit hover:bg-crit-tint'
        : 'hover:text-brand hover:bg-chip';

    $classes = 'inline-flex h-7 w-7 items-center justify-center rounded-tile '
        .'text-ink-mute transition '.$hover;
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}
       title="{{ $titulo }}" aria-label="{{ $titulo }}">
        <span class="h-[15px] w-[15px]"><x-nav-icon :name="$icone" /></span>
    </a>
@else
    <button type="{{ $attributes->get('type', 'button') }}" {{ $attributes->except('type')->merge(['class' => $classes]) }}
            title="{{ $titulo }}" aria-label="{{ $titulo }}">
        <span class="h-[15px] w-[15px]"><x-nav-icon :name="$icone" /></span>
    </button>
@endif
