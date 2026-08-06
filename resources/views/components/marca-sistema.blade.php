@props([
    'sistema',
    'formato' => 'icone',   // icone | wordmark
    'tamanho' => 'h-full w-full',
])

@php
    /**
     * A marca de cada produto, resolvida pelo slug. Os arquivos vivem em
     * `public/sistemas/` e vêm da pasta de marcas da casa; a regra de qual
     * usar mora aqui, e não espalhada nas telas.
     *
     * Ícone e wordmark servem a lugares diferentes: o ícone é quadrado e
     * identifica de relance numa tabela; o wordmark é comprido e só cabe onde
     * o produto é o assunto, sem comparação lado a lado.
     *
     * Alguns wordmarks são monocromáticos e precisam de uma versão por tema
     * (a do AlfaJornada tem "Alfa" em preto: no tema escuro sumiria). Outros
     * são coloridos e leem nos dois — esses têm só a versão base.
     */
    $slug = $sistema->slug ?? '';

    $icones = [
        'alfacontrol' => '/sistemas/alfacontrol.svg',
        'alfagym' => '/sistemas/alfagym.svg',
        'alfajornada' => '/sistemas/alfajornada.svg',
        'alfamed' => '/sistemas/alfamed.svg',
        'alfahome' => '/sistemas/alfahome.png',
        'gestor' => '/sistemas/gestor.png',
    ];

    // base = tema escuro (o padrão do painel) · claro = quando houver troca
    $wordmarks = [
        'alfacontrol' => ['/sistemas/alfacontrol-wordmark.png', null],
        'alfagym' => ['/sistemas/alfagym-wordmark.png', null],
        'alfahome' => ['/sistemas/alfahome-wordmark.png', '/sistemas/alfahome-wordmark-claro.png'],
        'alfajornada' => ['/sistemas/alfajornada-wordmark.png', '/sistemas/alfajornada-wordmark-claro.png'],
        'alfamed' => ['/sistemas/alfamed-wordmark.png', '/sistemas/alfamed-wordmark-claro.png'],
        // Gestor ainda não tem wordmark na pasta de marcas.
    ];
@endphp

@if ($formato === 'wordmark')
    @php [$escuro, $claro] = $wordmarks[$slug] ?? [null, null]; @endphp

    @if ($escuro && $claro)
        {{-- Duas versões: a claridade do tema decide qual aparece. Trocar por
             filtro CSS (invert) sujaria a cor da metade colorida da marca. --}}
        <img src="{{ $escuro }}" alt="{{ $sistema->nome }}"
             {{ $attributes->merge(['class' => 'w-auto object-contain object-left light:hidden '.$tamanho]) }}>
        <img src="{{ $claro }}" alt="{{ $sistema->nome }}"
             {{ $attributes->merge(['class' => 'hidden w-auto object-contain object-left light:block '.$tamanho]) }}>
    @elseif ($escuro)
        <img src="{{ $escuro }}" alt="{{ $sistema->nome }}"
             {{ $attributes->merge(['class' => 'w-auto object-contain object-left '.$tamanho]) }}>
    @else
        {{-- Sem arquivo de wordmark: o nome na tipografia de destaque diz a
             mesma coisa, sem inventar uma marca que não existe. --}}
        <span {{ $attributes->merge(['class' => 'font-display text-[17px] font-semibold text-ink']) }}>
            {{ $sistema->nome }}
        </span>
    @endif
@elseif ($arquivo = $icones[$slug] ?? null)
    <img src="{{ $arquivo }}" alt=""
         {{ $attributes->merge(['class' => 'object-contain '.$tamanho]) }}>
@else
    {{-- Sistema sem ícone: iniciais, como Revendas e Clientes já fazem. --}}
    <span {{ $attributes->merge(['class' => 'flex items-center justify-center font-display text-[12.5px] font-semibold text-brand-text '.$tamanho]) }}>
        {{ Str::of($sistema->nome)->replace('Alfa', '')->substr(0, 2)->upper() }}
    </span>
@endif
