{{--
    A aba Programadas (#225): as próximas janelas de manutenção, que são os
    compromissos da Agenda na categoria Deploy / manutenção.
    Espera: $porSistema, $quantas.
--}}
@forelse ($porSistema as $nome => $janelas)
    <x-tabela min="860px" class="tabela-zebrada" :titulo="$nome !== '' ? $nome : 'Sem sistema'"
              :sub="$janelas->count().' '.($janelas->count() === 1 ? 'janela' : 'janelas')">
        <thead>
            <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                <th class="px-4 py-2.5 font-semibold">Quando</th>
                <th class="px-4 py-2.5 font-semibold">Janela</th>
                <th class="px-4 py-2.5 font-semibold">Tarefa</th>
                <th class="px-4 py-2.5 font-semibold">Quem participa</th>
                <th class="px-4 py-2.5 font-semibold text-right">Agenda</th>
            </tr>
        </thead>

        <tbody>
            @foreach ($janelas as $janela)
                @php
                    $inicio = $janela->comecaEm();
                    $acontecendo = $inicio->lte(now());
                @endphp

                <tr class="border-b border-rule hover:bg-chip transition align-top">
                    <td class="px-4 py-3 whitespace-nowrap">
                        <p class="font-sans tabular text-[13px] font-medium text-ink">{{ $inicio->translatedFormat('D, d/m/Y') }}</p>
                        <p class="font-sans tabular text-[12px] text-ink-mute">
                            {{ $janela->intervalo() }}{{ $janela->viraODia() ? ' (termina em '.$janela->terminaEm()->format('d/m').')' : '' }}
                        </p>
                        @if ($acontecendo)
                            <x-badge tom="atencao" ponto class="mt-1">acontecendo agora</x-badge>
                        @else
                            <p class="text-[11px] text-ink-faint">{{ $inicio->diffForHumans() }}</p>
                        @endif
                    </td>

                    <td class="px-4 py-3 max-w-[420px]">
                        <p class="text-[13px] font-medium text-ink">{{ $janela->titulo }}</p>
                        @if (filled($janela->descricao))
                            <p class="mt-0.5 text-[12.5px] text-ink-mute line-clamp-2" title="{{ $janela->descricao }}">{{ $janela->descricao }}</p>
                        @endif
                    </td>

                    <td class="px-4 py-3">
                        @if ($janela->tarefa)
                            <a href="{{ route('tarefas.index', ['tarefa' => $janela->tarefa->id]) }}" title="{{ $janela->tarefa->titulo }}"
                               class="font-mono text-[12px] font-semibold text-brand-text hover:underline">{{ $janela->tarefa->codigo() }}</a>
                        @else
                            <span class="text-[12.5px] text-ink-faint">—</span>
                        @endif
                    </td>

                    <td class="px-4 py-3 text-[12.5px] text-ink-dim">
                        {{ $janela->participantes->pluck('name')->implode(', ') ?: '—' }}
                    </td>

                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-1">
                            <x-acao-tabela icone="calendar" titulo="Abrir na Agenda"
                                           :href="route('agenda.index', ['visao' => 'semana', 'em' => $inicio->toDateString()])" />
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </x-tabela>
@empty
    <div class="rounded-panel border border-line bg-surface px-5 py-8 text-center">
        <p class="text-[13px] font-medium text-ink">Nenhuma janela de manutenção marcada.</p>
        <p class="mt-1 text-[12.5px] text-ink-mute">
            Marque na Agenda um compromisso na categoria <b class="text-ink-dim">Deploy / manutenção</b> e escolha o sistema: ele aparece aqui até terminar.
        </p>
    </div>
@endforelse
