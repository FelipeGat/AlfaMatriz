@props(['icone' => 'h-7 w-7', 'wordmark' => 'h-[15px]', 'somenteIcone' => false])

{{--
    A marca é sempre o par ícone + wordmark: o ícone é a matriz (um nó central
    ligado a quatro periféricos) e sustenta sozinho o menu recolhido e o
    favicon; o wordmark entra quando há largura para ele.
--}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5']) }}>
    <img src="/icon-matriz-solid.svg" alt="{{ $somenteIcone ? 'AlfaMatriz' : '' }}" class="{{ $icone }} shrink-0">

    @unless ($somenteIcone)
        <img src="/alfamatriz.png" alt="AlfaMatriz" class="{{ $wordmark }} w-auto shrink-0">
    @endunless
</span>
