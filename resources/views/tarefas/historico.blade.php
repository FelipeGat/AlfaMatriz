<x-app-layout>
    <x-slot name="titulo">Histórico de tarefas</x-slot>
    <x-slot name="contexto">{{ $tarefas->count() }} tarefas no histórico</x-slot>

    <div class="flex flex-col gap-4">
        @include('tarefas._abas', ['ativa' => 'historico'])

    {{--
        O quadro é só o trabalho em curso: tarefa encerrada sai de lá e passa a
        viver aqui (AC-082, AC-096). Esta tela é o caminho de auditoria — a
        listagem inteira, sem nenhum recorte por período (AC-097) — e também a
        porta de volta: reabrir uma concluída só era possível pelo menu do card
        no quadro, e sem a coluna Concluída esse caminho deixaria de existir
        (AC-118).
    --}}
    <x-tabela titulo="Histórico completo" sub="sem recorte de período" min="820px">
        <thead>
            <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                <th class="px-4 py-2.5 font-semibold">Tarefa</th>
                <th class="px-4 py-2.5 font-semibold">Sistema</th>
                <th class="px-4 py-2.5 font-semibold">Responsável</th>
                <th class="px-4 py-2.5 font-semibold">Etapa final</th>
                <th class="px-4 py-2.5 font-semibold">Data</th>
                <th class="px-4 py-2.5 font-semibold text-right">Ação</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($tarefas as $tarefa)
                <tr class="border-b border-rule hover:bg-chip transition">
                    <td class="px-4 py-3 text-[13.5px] text-ink">{{ $tarefa->titulo }}</td>
                    <td class="px-4 py-3 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                        {{ $tarefa->sistema?->nome ?? 'Sem sistema' }}
                    </td>
                    <td class="px-4 py-3 text-[13px] text-ink-dim">{{ $tarefa->responsavel?->name ?? 'Sem responsável' }}</td>
                    <td class="px-4 py-3">
                        <x-badge :tom="$tarefa->status === 'concluida' ? 'bom' : 'critico'">
                            {{ \App\Models\Tarefa::STATUS[$tarefa->status] ?? $tarefa->status }}
                        </x-badge>
                    </td>
                    <td class="px-4 py-3 font-mono text-[13px] text-ink-dim whitespace-nowrap">
                        {{ $tarefa->updated_at->format('d/m/Y') }}
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        {{-- Cancelada não tem saída no mapa de transições: nada a oferecer. --}}
                        @if ($tarefa->status === 'concluida')
                            <form method="POST" action="{{ route('tarefas.mover', $tarefa) }}">
                                @csrf
                                <input type="hidden" name="status" value="em_desenvolvimento">
                                <button type="submit"
                                        class="h-[28px] px-2.5 rounded-control border border-btn-line
                                               font-medium text-[12px] text-ink-dim hover:text-brand hover:border-brand transition">
                                    Reabrir
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-[13px] text-ink-mute">
                        Nenhuma tarefa concluída ou cancelada ainda.
                    </td>
                </tr>
            @endforelse
        </tbody>

        <x-slot name="rodape">
            <span>{{ $tarefas->count() }} tarefas no histórico</span>
        </x-slot>
    </x-tabela>
    </div>
</x-app-layout>
