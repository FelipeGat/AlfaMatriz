@props([
    'sistema',
    'formato' => 'icone',   // icone | wordmark
    'tamanho' => 'h-full w-full',
])

@php
    /**
     * A marca de cada produto, resolvida pelo slug.
     *
     * Os arquivos vêm dos repositórios de cada sistema (public/sistemas/).
     * A regra de qual usar mora aqui e não espalhada nas telas: quando um
     * sistema novo entrar, é este mapa que muda.
     *
     * Ícone e wordmark são coisas diferentes e servem a lugares diferentes:
     * o ícone é quadrado e identifica de relance numa tabela; o wordmark é
     * comprido e só cabe onde o produto é o assunto, sem comparação lado a
     * lado. Ver o comentário na lista de Produtos.
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

    $wordmarks = [
        'alfacontrol' => '/sistemas/alfacontrol-wordmark.png',
        'alfagym' => '/sistemas/alfagym-wordmark.png',
        'alfahome' => '/sistemas/alfahome-wordmark.png',
        'alfamed' => '/sistemas/alfamed-wordmark.png',
        'gestor' => '/sistemas/gestor-wordmark.svg',
        // AlfaJornada não tem wordmark em arquivo — o dele é componente no
        // front do próprio sistema. Cai no nome em texto, que é honesto.
    ];

    $arquivo = $formato === 'wordmark'
        ? ($wordmarks[$slug] ?? null)
        : ($icones[$slug] ?? null);
@endphp

@if ($arquivo && $formato === 'wordmark')
    <img src="{{ $arquivo }}" alt="{{ $sistema->nome }}"
         {{ $attributes->merge(['class' => 'w-auto object-contain object-left '.$tamanho]) }}>
@elseif ($arquivo)
    <img src="{{ $arquivo }}" alt=""
         {{ $attributes->merge(['class' => 'object-contain '.$tamanho]) }}>
@elseif ($formato === 'wordmark')
    {{-- Sem arquivo de wordmark: o nome do produto na tipografia de destaque
         diz a mesma coisa, sem inventar uma marca que não existe. --}}
    <span {{ $attributes->merge(['class' => 'font-display text-[17px] font-semibold text-ink']) }}>
        {{ $sistema->nome }}
    </span>
@else
    {{-- Sistema sem ícone: iniciais, como Revendas e Clientes já fazem. --}}
    <span {{ $attributes->merge(['class' => 'flex items-center justify-center font-display text-[12.5px] font-semibold text-brand-text '.$tamanho]) }}>
        {{ Str::of($sistema->nome)->replace('Alfa', '')->substr(0, 2)->upper() }}
    </span>
@endif
