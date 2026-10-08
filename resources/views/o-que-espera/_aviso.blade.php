{{--
    O aviso ao entrar (#300) — só o que pede ação, nada de "Minhas tarefas".

    Chega pelo `espera.aviso` depois de a tela carregar e já nasce aberto. O
    servidor o adiou ao entregá-lo, então fechar de qualquer jeito vale como
    "Lembrar mais tarde"; só o "Ok, vi" precisa avisar o servidor, para
    silenciar até o fim do dia.

    Espera: $pendencias.
--}}
<x-modal name="o-que-espera-aviso" :show="true" maxWidth="xl">
    <div class="h-[38px] flex items-center gap-3 px-4 bg-head border-b border-line">
        <h2 class="font-display text-[15px] font-semibold text-ink truncate">O que espera você</h2>
        <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint truncate">
            {{ $pendencias->count() }} {{ $pendencias->count() === 1 ? 'item pede' : 'itens pedem' }} ação sua
        </span>
    </div>

    <div class="bg-subtle">
        @foreach ($pendencias as $item)
            @include('o-que-espera._pendencia', ['item' => $item])
        @endforeach
    </div>

    {{-- Preso ao pé do modal: com a lista longa, os dois botões ficavam
         depois da rolagem, e o aviso parecia não ter como responder. --}}
    <div class="sticky bottom-0 flex items-center justify-end gap-2.5 px-4 py-3 border-t border-line bg-panel">
        <button type="button"
                x-on:click="$dispatch('close-modal', 'o-que-espera-aviso')"
                class="h-[34px] px-3 rounded-control border border-btn-line text-ink-mute text-[12.5px]
                       hover:text-brand hover:border-brand transition whitespace-nowrap">
            Lembrar mais tarde
        </button>
        <button type="button" data-espera-visto
                x-on:click="fetch('{{ route('espera.visto') }}', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                    });
                    $dispatch('close-modal', 'o-que-espera-aviso')"
                class="h-[34px] px-3 rounded-control bg-brand text-on-brand font-semibold text-[12.5px]
                       hover:bg-brand-bright transition whitespace-nowrap">
            Ok, vi
        </button>
    </div>
</x-modal>
