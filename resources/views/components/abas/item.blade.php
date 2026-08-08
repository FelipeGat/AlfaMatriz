{{--
    Um item do <x-abas>. `ativo` liga o fundo de marca; o resto fica em tinta
    neutra e ganha chip no hover. `icone` opcional usa o <x-nav-icon>.
--}}
@props([
    'href' => '#',
    'ativo' => false,
    'icone' => null,
    'rotulo' => null,
])

<a href="{{ $href }}"
   aria-current="{{ $ativo ? 'page' : 'false' }}"
   {{ $attributes->merge(['class' => 'inline-flex h-8 items-center gap-1.5 rounded-[5px] px-3 '
       .'text-[13px] font-semibold whitespace-nowrap transition '
       .($ativo
            ? 'bg-nav-active text-brand-text'
            : 'text-ink-mute hover:text-ink hover:bg-chip')]) }}>
    @if ($icone)
        <span class="h-[15px] w-[15px] shrink-0"><x-nav-icon name="{{ $icone }}" /></span>
    @endif
    {{ $slot }}
</a>
