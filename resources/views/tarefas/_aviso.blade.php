@php
    /**
     * O aviso de uma ação que não recarregou a página.
     *
     * Existe como partial porque a mensagem passa a viajar no JSON, e o
     * `<x-aviso>` precisa ser montado por quem tem os tokens de cor e o
     * teleporte para a pilha do layout — remontá-lo em JavaScript duplicaria
     * um componente que já existe, e a cópia divergiria na primeira mudança.
     *
     * Espera: $texto, $tom.
     */
@endphp

<x-aviso :tom="$tom">{{ $texto }}</x-aviso>
