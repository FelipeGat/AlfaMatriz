{{--
    Aviso de ação concluída, centralizado embaixo.

    Lê a `session('status')` que os controllers já gravam — então cada baixa,
    cada cadastro e cada faturamento gerado passam a confirmar sozinhos, sem
    tocar em controller nenhum.

    Some em 2,6s. Fica com `role="status"` para leitores de tela anunciarem
    sem roubar o foco de quem está digitando.
--}}
@if (session('status'))
    <div x-data="{ visivel: true }"
         x-show="visivel"
         x-init="setTimeout(() => visivel = false, 2600)"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="anim-toast fixed bottom-[26px] left-1/2 z-50 flex -translate-x-1/2 items-center gap-2.5 rounded-control border border-line bg-panel px-4 py-2.5 shadow-overlay"
         role="status"
         aria-live="polite">
        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-good" aria-hidden="true"></span>
        <span class="text-[12.5px] text-ink">{{ session('status') }}</span>
        <button type="button" @click="visivel = false"
                class="ml-1 text-mute transition-colors hover:text-ink" aria-label="Fechar aviso">
            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>
@endif
