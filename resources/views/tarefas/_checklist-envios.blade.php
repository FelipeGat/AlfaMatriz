@php
    /**
     * Os envios do checklist, um por ação.
     *
     * Vivem FORA do formulário da tarefa pelo mesmo motivo dos comentários:
     * formulário aninhado é HTML inválido e o navegador descarta o interno. Os
     * campos e botões da lista os alcançam pelo atributo `form`.
     *
     * Os de item vão vazios de propósito — a caixa de marcar e o campo de texto
     * que eles enviam estão lá na lista, junto do item que se está mexendo.
     */
@endphp

<form id="novo-item-{{ $tarefa->id }}" method="POST"
      action="{{ route('tarefas.itens.store', $tarefa) }}" class="hidden">
    @csrf
</form>

{{-- O excluir da tarefa mora aqui pelo mesmo motivo dos outros: o botão dele
     está DENTRO do formulário de edição, e formulário aninhado é HTML
     inválido. --}}
@if (auth()->user()?->podeTriarTarefas())
    {{-- Bloquear e destravar: a mesma rota nos dois sentidos, como o botão do
         card. Fora do formulário da tarefa porque aninhar é HTML inválido — o
         textarea do rodapé aponta para cá pelo atributo `form`. --}}
    <form id="bloquear-tarefa-{{ $tarefa->id }}" method="POST"
          action="{{ route('tarefas.bloquear', $tarefa) }}" class="hidden">
        @csrf
    </form>

    <form id="excluir-tarefa-{{ $tarefa->id }}" method="POST"
          action="{{ route('tarefas.destroy', $tarefa) }}" class="hidden">
        @csrf
        @method('DELETE')
    </form>
@endif

<form id="ordenar-checklist-{{ $tarefa->id }}" method="POST"
      action="{{ route('tarefas.itens.ordenar', $tarefa) }}" class="hidden">
    @csrf
</form>

@foreach ($tarefa->itens as $item)
    <form id="item-{{ $item->id }}" method="POST"
          action="{{ route('tarefas.itens.update', $item) }}" class="hidden">
        @csrf
        @method('PUT')
    </form>

    <form id="apagar-item-{{ $item->id }}" method="POST"
          action="{{ route('tarefas.itens.destroy', $item) }}" class="hidden">
        @csrf
        @method('DELETE')
    </form>
@endforeach
