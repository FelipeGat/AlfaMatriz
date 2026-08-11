@php
    /**
     * Os envios que mexem em comentário já publicado — corrigir e apagar —,
     * um par por comentário do autor.
     *
     * Vivem FORA do formulário da tarefa: formulário aninhado é HTML inválido
     * e o navegador descarta o interno. Os botões da lista (e o campo da
     * correção) os alcançam pelo atributo `form`, que liga por id de dentro do
     * modal. Sem esse par, o lápis e o lixo não fariam nada, e a falha seria
     * silenciosa na tela.
     *
     * O formulário de correção vai vazio de propósito: o `textarea` que ele
     * envia está lá na lista, junto do texto que se está corrigindo.
     */
@endphp

@foreach ($tarefa->comentarios as $comentario)
    @if ($comentario->autor_id === auth()->id())
        <form id="editar-comentario-{{ $comentario->id }}" method="POST"
              action="{{ route('tarefas.comentarios.update', $comentario) }}" class="hidden">
            @csrf
            @method('PUT')
        </form>

        <form id="apagar-comentario-{{ $comentario->id }}" method="POST"
              action="{{ route('tarefas.comentarios.destroy', $comentario) }}" class="hidden">
            @csrf
            @method('DELETE')
        </form>
    @endif
@endforeach
