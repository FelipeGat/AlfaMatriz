@php
    /**
     * O formulário de nova subtarefa, buscado do servidor com a mãe amarrada.
     *
     * Vive no mesmo `[data-modais]` que o modal de edição, e pelo mesmo motivo:
     * é UM por vez, buscado no clique. O de nova tarefa da página não serve
     * aqui — ele é um só, nasce junto com a tela e guardaria a mãe de uma
     * abertura para a seguinte.
     *
     * Trocar o bloco fecha o modal da mãe, que é o comportamento certo: a
     * subtarefa é um formulário inteiro, e empilhar dois modais deixaria dois
     * Esc e dois Cancelar disputando qual fecha o quê.
     *
     * Espera: $pai, $sistemas, $usuarios.
     */
@endphp

<x-modal name="nova-subtarefa-{{ $pai->id }}" maxWidth="tarefa">
    {{-- `contents` para o invólucro não entrar no layout, como no `_modais`: o
         painel espera o `<form class="flex flex-col">` como filho direto. --}}
    <div class="contents">
        @include('tarefas._form', ['tarefa' => null, 'pai' => $pai, 'sistemas' => $sistemas, 'usuarios' => $usuarios])
    </div>
</x-modal>
