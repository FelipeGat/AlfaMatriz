@php
    /**
     * Os envios do vínculo, um por ação.
     *
     * Vivem FORA do formulário da tarefa pelo mesmo motivo dos comentários e do
     * checklist: formulário aninhado é HTML inválido e o navegador descarta o
     * interno. O campo de número e os ✕ da lista os alcançam pelo atributo
     * `form`.
     *
     * O de vincular vai vazio de propósito — o campo que ele envia está lá na
     * lista, embaixo dos vínculos que já existem.
     *
     * `data-parcial` marca quem NÃO recarrega a página: o quadro intercepta o
     * envio, manda por `fetch` e troca as regiões que voltaram (ver
     * `trocarPedacos`, no `index`). Sem a marca, o formulário é enviado pelo
     * navegador como sempre foi — é o que acontece se o JavaScript falhar, e é
     * o caminho que a suíte exercita.
     */
@endphp

<form id="vincular-{{ $tarefa->id }}" method="POST" data-parcial
      action="{{ route('tarefas.vinculos.store', $tarefa) }}" class="hidden">
    @csrf
</form>

{{-- Um envio por vínculo, e a URL nomeia a OUTRA tarefa em vez de um id de
     linha: o par mora em duas linhas, uma por sentido, e apagar "o vínculo 37"
     deixaria a de volta em pé. --}}
@foreach ($tarefa->vinculadas as $vinculada)
    <form id="desvincular-{{ $tarefa->id }}-{{ $vinculada->id }}" method="POST" data-parcial
          action="{{ route('tarefas.vinculos.destroy', [$tarefa, $vinculada]) }}" class="hidden">
        @csrf
        @method('DELETE')
    </form>
@endforeach
