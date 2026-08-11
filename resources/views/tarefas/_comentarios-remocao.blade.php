@php
    /**
     * Os formulários de apagar comentário, um por comentário do autor.
     *
     * Vivem FORA do formulário da tarefa — formulário aninhado é HTML
     * inválido — e são alcançados pelos botões da lista através do atributo
     * `form`, que os liga por id de dentro do modal. Sem esse par, apagar
     * seria carona no salvar da tarefa: o clique no lixo publicaria o
     * comentário que estivesse escrito no campo.
     */
@endphp

@foreach ($tarefa->comentarios as $comentario)
    @if ($comentario->autor_id === auth()->id())
        <form id="apagar-comentario-{{ $comentario->id }}" method="POST"
              action="{{ route('tarefas.comentarios.destroy', $comentario) }}" class="hidden">
            @csrf
            @method('DELETE')
        </form>
    @endif
@endforeach
