{{--
    Abas da tela de Tarefas, no padrão de Revendas: o quadro é o trabalho em
    curso e o histórico é o que já foi encerrado — dois recortes do mesmo
    assunto, e por isso duas abas da mesma tela, não duas telas (AC-125).

    Aqui as abas apontam para rotas distintas em vez de `?aba=`: as duas já
    existiam como rota própria, e um `?aba=` sobre isso só acrescentaria um
    segundo endereço para a mesma página.
--}}
@props(['ativa' => 'quadro'])

{{--
    O <div> em volta não é enfeite: como item de um container flex em coluna, a
    pílula teria o `display: inline-flex` blocado para `flex` e seria esticada
    pelo `align-items: stretch` — a borda ia de ponta a ponta. Dentro de um
    bloco comum ela volta a encolher até o conteúdo.
--}}
<div class="shrink-0">
    <x-abas>
        <x-abas.item :href="route('tarefas.index')" :ativo="$ativa === 'quadro'" icone="view-grid">
            Quadro
        </x-abas.item>
        <x-abas.item :href="route('tarefas.historico')" :ativo="$ativa === 'historico'" icone="clock">
            Histórico
        </x-abas.item>
    </x-abas>
</div>
