@php
    /**
     * A entrega da passagem atual (#210), no topo do modal enquanto o código
     * está nos portões — revisão, staging, produção.
     *
     * É o que quem revisa e quem testa abrem a tarefa para ler: o que mudou,
     * como conferir e onde está o código. Antes isso ficava espalhado pelo
     * resumo, pelos motivos e pela conversa, e cada um procurava num lugar.
     *
     * As entregas anteriores ficam recolhidas, e não somem: a 1ª é o que a
     * revisão reprovou, e quem examina a 2ª precisa poder comparar.
     *
     * O tom é o da etapa (`brand`), o mesmo do painel que pediu a entrega no
     * quadro: é a mesma informação, de um lado escrita e do outro lida.
     *
     * Espera: $tarefa.
     */
    $entrega = $tarefa->entregaAtual();
@endphp

@if ($entrega)
    @php
        $anteriores = $tarefa->entregas->where('numero', '<', $entrega->numero)->sortByDesc('numero')->values();
    @endphp

    <div x-data="{ anteriores: false }" class="px-[11px] py-[9px] rounded-[5px] border border-l-2"
         style="background: rgb(var(--brand) / calc(var(--tint-alpha) / 2));
                border-color: rgb(var(--brand) / 0.4);
                border-left-color: rgb(var(--brand))">
        <div class="flex items-center gap-2.5">
            <span class="h-3.5 w-3.5 shrink-0" style="color: rgb(var(--brand))">
                <x-nav-icon name="clipboard" :peso="1.8" />
            </span>
            {{-- O número só aparece a partir da 2ª: "1ª entrega" numa tarefa
                 que nunca voltou é ruído; "2ª entrega" é a notícia de que ela
                 já foi reprovada uma vez. --}}
            <span class="flex-1 min-w-0 font-mono text-[10.5px] font-semibold uppercase tracking-[0.08em] truncate"
                  style="color: rgb(var(--brand))">
                {{ $entrega->numero > 1 ? $entrega->numero.'ª entrega' : 'Entrega' }} para a revisão
            </span>
            <span class="shrink-0 text-[11.5px] text-ink-faint whitespace-nowrap">
                {{ $entrega->autor ? $entrega->autor->name.' · ' : '' }}{{ $entrega->created_at->format('d/m/Y H:i') }}
            </span>
        </div>

        @include('tarefas._entrega-campos', ['entrega' => $entrega])

        @if ($anteriores->isNotEmpty())
            <button type="button" @click="anteriores = ! anteriores"
                    class="mt-2 inline-flex items-center gap-1.5 text-[11.5px] font-medium text-ink-dim transition hover:text-ink">
                <span class="h-3 w-3 transition" :class="anteriores && 'rotate-180'">
                    <x-nav-icon name="chevron-down" :peso="1.8" />
                </span>
                {{ $anteriores->count() === 1 ? 'Ver a entrega anterior' : 'Ver as '.$anteriores->count().' entregas anteriores' }}
            </button>

            <div x-show="anteriores" x-cloak class="mt-2 flex flex-col gap-2">
                @foreach ($anteriores as $anterior)
                    <div class="pt-2 border-t border-rule">
                        <p class="font-mono text-[9.5px] uppercase tracking-[0.08em] text-ink-faint">
                            {{ $anterior->numero }}ª entrega
                            · {{ $anterior->autor ? $anterior->autor->name.' · ' : '' }}{{ $anterior->created_at->format('d/m/Y H:i') }}
                        </p>
                        @include('tarefas._entrega-campos', ['entrega' => $anterior])
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endif
