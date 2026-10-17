<x-app-layout>
    <x-slot name="titulo">Agenda consolidada</x-slot>
    <x-slot name="contexto">
        <span>Secretário · Google · Agenda do Matriz</span>
    </x-slot>
    <x-slot name="acoes">
        <a href="{{ route('secretario.meu-dia') }}"
           class="h-[34px] px-3 inline-flex items-center gap-1.5 rounded-control border border-btn-line text-ink-dim text-[12.5px] font-semibold hover:text-brand hover:border-brand transition whitespace-nowrap">
            Meu Dia
        </a>
    </x-slot>

    {{-- Etapa 2 (docs/specs/secretario-agenda): três fontes numa lista só, cada
         item com a origem. Sem Google em dia, o aviso diz a limitação — dia
         vazio nunca é apresentado como dia livre de certeza (FR-011). --}}
    <div class="mx-auto w-full max-w-3xl space-y-4">
        @if ($visao['google']['aviso'])
            <p class="rounded-panel border border-warn-line bg-warn-tint px-4 py-2.5 text-[13px] text-warn">{{ $visao['google']['aviso'] }}</p>
        @elseif ($visao['google']['sincronizado_em'])
            <p class="font-mono text-[11px] text-ink-faint">Google lido em {{ $visao['google']['sincronizado_em'] }}</p>
        @endif

        @foreach ($visao['dias'] as $dia)
            <x-painel :titulo="$dia['titulo']" solto>
                @if (empty($dia['itens']))
                    <p class="px-4 py-3 text-[13px] text-ink-mute">
                        {{ $visao['google']['estado'] === 'ok' ? 'Nada na agenda.' : 'Nada nas fontes que consegui ler.' }}
                    </p>
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($dia['itens'] as $item)
                            <li class="flex flex-col gap-0.5 px-4 py-2.5 sm:flex-row sm:items-center sm:gap-3">
                                <span class="w-[110px] shrink-0 font-mono text-[12px] {{ $item['conflito'] ? 'text-crit' : 'text-ink-dim' }}">{{ $item['hora'] }}</span>
                                <span class="min-w-0 flex-1 break-words text-[14px] text-ink">
                                    @if ($item['link'])
                                        <a href="{{ $item['link'] }}" target="_blank" rel="noopener noreferrer" class="hover:text-brand">{{ $item['titulo'] }}</a>
                                    @else
                                        {{ $item['titulo'] }}
                                    @endif
                                    @if ($item['conflito'])
                                        <span class="ml-1 rounded-badge border border-crit px-1 text-[10.5px] font-semibold text-crit">conflito</span>
                                    @endif
                                </span>
                                <span class="shrink-0 text-[11.5px] text-ink-mute">{{ $item['origem_rotulo'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if (! empty($dia['livres']) && $visao['google']['estado'] === 'ok')
                    <p class="border-t border-line px-4 py-2 text-[12px] text-good">Livre: {{ implode(', ', $dia['livres']) }}</p>
                @endif
            </x-painel>
        @endforeach
    </div>
</x-app-layout>
