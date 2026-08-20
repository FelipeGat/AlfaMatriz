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
     *
     * `data-parcial` nos três: perguntar, corrigir e apagar acontecem com o
     * modal aberto e no meio de uma leitura, e recarregar ali descartaria o
     * comentário que ainda está sendo escrito no campo de baixo. Ver
     * `trocarPedacos`, no `index`.
     */
@endphp

{{--
    O envio de COMENTAR. Fora do formulário da tarefa pelo mesmo motivo dos
    outros, e com o `corpo` num campo escondido porque o textarea que a pessoa
    preenche pertence àquele formulário — o botão copia o texto para cá no
    clique, como o de perguntar.

    `data-limpa` esvazia o campo depois do envio: sem isso o texto continuaria
    na tela e o Salvar seguinte publicaria a mesma frase de novo.
--}}
<form id="comentar-{{ $tarefa->id }}" method="POST" data-parcial
      data-limpa="#comentario-{{ $tarefa->id }}"
      action="{{ route('tarefas.comentarios.store', $tarefa) }}" class="hidden">
    @csrf
    <input type="hidden" id="comentario-corpo-{{ $tarefa->id }}" name="corpo" value="">
</form>

{{--
    O envio de PERGUNTAR, irmão dos de cima e fora do formulário pelo mesmo
    motivo. O `corpo` vai num campo escondido porque o textarea que a pessoa
    preenche pertence ao formulário da tarefa — o botão copia o texto para cá
    no clique.
--}}
@unless (in_array($tarefa->status, \App\Models\Tarefa::STATUS_TERMINAIS, true))
    {{-- `data-limpa` esvazia o campo de comentário depois do envio. Perguntar
         PUBLICA o que está escrito nele, e sem recarga o texto continuaria na
         tela — o Salvar seguinte publicaria a mesma frase uma segunda vez. --}}
    <form id="perguntar-{{ $tarefa->id }}" method="POST" data-parcial
          data-limpa="#comentario-{{ $tarefa->id }}"
          action="{{ route('tarefas.conversar', $tarefa) }}" class="hidden">
        @csrf
        <input type="hidden" id="pergunta-corpo-{{ $tarefa->id }}" name="corpo" value="">
    </form>
@endunless

@foreach ($tarefa->comentarios as $comentario)
    @if ($comentario->autor_id === auth()->id())
        <form id="editar-comentario-{{ $comentario->id }}" method="POST" data-parcial
              action="{{ route('tarefas.comentarios.update', $comentario) }}" class="hidden">
            @csrf
            @method('PUT')
        </form>

        <form id="apagar-comentario-{{ $comentario->id }}" method="POST" data-parcial
              action="{{ route('tarefas.comentarios.destroy', $comentario) }}" class="hidden">
            @csrf
            @method('DELETE')
        </form>
    @endif
@endforeach
