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
     *
     * `data-parcial` marca quem NÃO recarrega a página: o quadro intercepta o
     * envio, manda por `fetch` e troca as regiões que voltaram (ver
     * `trocarPedacos`, no `index`). Sem a marca, o formulário é enviado pelo
     * navegador como sempre foi — é o que acontece se o JavaScript falhar, e é
     * o caminho que a suíte exercita.
     *
     * O excluir entra junto. Ele tem para onde voltar: o quadro sem a tarefa, e
     * o bloco de modais sem o dela — e é essa troca que fecha o modal aberto,
     * já que cada `x-modal` nasce com `show: false`.
     */
@endphp

<form id="novo-item-{{ $tarefa->id }}" method="POST" data-parcial
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
    <form id="bloquear-tarefa-{{ $tarefa->id }}" method="POST" data-parcial
          action="{{ route('tarefas.bloquear', $tarefa) }}" class="hidden">
        @csrf
    </form>

    <form id="excluir-tarefa-{{ $tarefa->id }}" method="POST" data-parcial
          action="{{ route('tarefas.destroy', $tarefa) }}" class="hidden">
        @csrf
        @method('DELETE')
    </form>
@endif

{{-- O teste do staging: DOIS envios, um por veredito, porque o veredito viaja
     como campo fixo — o caminho parcial monta `new FormData(form)` sem o
     submitter, e o value do botão que enviou se perderia. Sem gate de perfil,
     como o bloqueio do card: quem testa normalmente não é quem move. --}}
@if ($tarefa->tipo === 'desenvolvimento' && $tarefa->status === 'em_staging')
    <form id="testar-aprovar-{{ $tarefa->id }}" method="POST" data-parcial
          action="{{ route('tarefas.testar', $tarefa) }}" class="hidden">
        @csrf
        <input type="hidden" name="aprovado" value="1">
    </form>

    <form id="testar-reprovar-{{ $tarefa->id }}" method="POST" data-parcial
          action="{{ route('tarefas.testar', $tarefa) }}" class="hidden">
        @csrf
        <input type="hidden" name="aprovado" value="0">
    </form>
@endif

<form id="ordenar-checklist-{{ $tarefa->id }}" method="POST" data-parcial
      action="{{ route('tarefas.itens.ordenar', $tarefa) }}" class="hidden">
    @csrf
</form>

@foreach ($tarefa->itens as $item)
    <form id="item-{{ $item->id }}" method="POST" data-parcial
          action="{{ route('tarefas.itens.update', $item) }}" class="hidden">
        @csrf
        @method('PUT')
    </form>

    <form id="apagar-item-{{ $item->id }}" method="POST" data-parcial
          action="{{ route('tarefas.itens.destroy', $item) }}" class="hidden">
        @csrf
        @method('DELETE')
    </form>
@endforeach
