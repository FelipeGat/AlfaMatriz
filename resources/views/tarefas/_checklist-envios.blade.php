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
