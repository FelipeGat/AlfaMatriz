{{--
    As duas listas do "O que espera você" (#300), nos dois painéis da mesma
    moldura que o Centro de Controle usa. Partial porque o Centro de Controle
    e o botão do quadro mostram exatamente isto, e duas cópias divergiriam.

    Espera: $espera = ['pendencias' => Collection, 'minhas' => Collection].
--}}
<x-painel titulo="O que espera você"
          :sub="$espera['pendencias']->count().' '.($espera['pendencias']->count() === 1 ? 'aberto' : 'abertos')"
          solto data-painel-espera class="min-w-0">
    @forelse ($espera['pendencias'] as $item)
        @include('o-que-espera._pendencia', ['item' => $item])
    @empty
        <div class="flex items-center gap-3 px-4 py-6">
            <span class="h-7 w-7 shrink-0 rounded-tile flex items-center justify-center"
                  style="background: rgb(var(--good) / var(--tint-alpha)); color: rgb(var(--good))">
                <span class="h-[15px] w-[15px]"><x-nav-icon name="check-circle" /></span>
            </span>
            <span class="text-[14px] text-ink-dim">Nada esperando você no quadro.</span>
        </div>
    @endforelse
</x-painel>

<x-painel titulo="Minhas tarefas"
          :sub="$espera['minhas']->count().' em curso · as mais paradas primeiro'"
          solto data-painel-minhas class="min-w-0">
    @forelse ($espera['minhas'] as $linha)
        @include('o-que-espera._minha', ['linha' => $linha])
    @empty
        <p class="px-4 py-6 text-[13px] text-ink-mute">Nenhuma tarefa em curso com você.</p>
    @endforelse
</x-painel>
