@php
    /**
     * Um modal de edição por card do quadro.
     *
     * Partial própria pelo mesmo motivo do `_quadro`: ela é desenhada ao abrir
     * a tela E de novo, sozinha, quando uma ação muda QUAIS tarefas existem no
     * quadro — criar, excluir, ou mover para uma etapa terminal, que tira a
     * tarefa da tela. Sem redesenhá-la nessas três, a tarefa recém-criada
     * apareceria no quadro e não abriria ao clique: o modal dela não existiria.
     *
     * Trocá-la fecha os modais abertos, porque cada `x-modal` nasce com
     * `show: false`. Isso é o comportamento certo justamente nas ações que a
     * pedem — salvar, excluir e criar terminavam com o modal fechado quando a
     * página recarregava. Por isso ela NÃO volta nas ações do checklist e da
     * conversa: lá o modal precisa continuar aberto.
     *
     * Espera: $tarefas, $sistemas, $usuarios.
     */
@endphp

@foreach ($tarefas as $tarefa)
    <x-modal name="editar-tarefa-{{ $tarefa->id }}" maxWidth="tarefa">
        {{-- `contents` para o invólucro não entrar no layout: o painel do modal
             espera o `<form class="flex flex-col">` como filho direto, e um div
             comum no meio soltaria o cabeçalho e o rodapé grudados. --}}
        <div class="contents" data-pedaco="formulario-{{ $tarefa->id }}">
            @include('tarefas._form', ['tarefa' => $tarefa, 'sistemas' => $sistemas, 'usuarios' => $usuarios])
        </div>

        {{-- Os envios escondidos também são trocados: um item novo no checklist
             precisa dos formulários de corrigir e apagar DELE, um vínculo novo
             e um comentário apagado deixa para trás um par de formulários que
             aponta para um id que já não existe. --}}
        <div data-pedaco="checklist-envios-{{ $tarefa->id }}">
            @include('tarefas._checklist-envios', ['tarefa' => $tarefa])
        </div>
        <div data-pedaco="conversa-envios-{{ $tarefa->id }}">
            @include('tarefas._comentarios-envios', ['tarefa' => $tarefa])
        </div>
    </x-modal>
@endforeach
