@php
    /**
     * A Lista agrupa por dia, com as ATRASADAS num grupo próprio no topo.
     *
     * Elas vêm primeiro e em vermelho porque são o único grupo cuja data já não
     * ajuda a decidir nada: espalhadas pelos dias em que venceram, ficariam
     * acima da dobra em ordem cronológica — que é onde ninguém olha depois de o
     * prazo passar.
     *
     * Só PRAZO atrasa. Compromisso que já aconteceu não é pendência: a reunião
     * das duas da tarde de ontem não precisa de ação, e listá-la como atraso
     * encheria o grupo de coisas resolvidas.
     */
    $atrasadas = $itens->where('atrasada', true);
    $futuras = $itens->where('atrasada', false)->groupBy('data');
@endphp

<div class="min-h-0 flex-1 overflow-y-auto rounded-panel border border-line bg-board p-3.5">
    @if ($atrasadas->isNotEmpty())
        <div class="mb-4">
            <p class="mb-1.5 font-mono text-[11px] uppercase tracking-[0.08em] text-crit">
                Atrasadas · {{ $atrasadas->count() }}
            </p>
            <div class="flex flex-col gap-[5px]">
                @foreach ($atrasadas as $item)
                    <x-agenda-item :item="$item" variante="linha" />
                @endforeach
            </div>
        </div>
    @endif

    @forelse ($futuras as $data => $doDia)
        @php $dia = \Illuminate\Support\Carbon::parse($data); @endphp
        <div class="mb-4">
            <p class="mb-1.5 font-mono text-[11px] uppercase tracking-[0.08em] text-ink-mute">
                {{ $dia->isSameDay($hoje) ? 'Hoje' : $dia->translatedFormat('D, d \d\e F') }}
            </p>
            <div class="flex flex-col gap-[5px]">
                @foreach ($doDia as $item)
                    <x-agenda-item :item="$item" variante="linha" />
                @endforeach
            </div>
        </div>
    @empty
        @if ($atrasadas->isEmpty())
            {{-- Uma frase, inteira. Texto de ajuda ou aparece todo ou não
                 aparece: meia explicação custa a mesma leitura e não resolve. --}}
            <p class="py-10 text-center text-[12.5px] text-ink-faint">
                Nada marcado para os próximos {{ \App\Services\AgendaService::DIAS_DA_LISTA }} dias.
            </p>
        @endif
    @endforelse
</div>
