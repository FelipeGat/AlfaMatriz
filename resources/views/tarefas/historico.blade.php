<x-app-layout>
    <x-slot name="titulo">Histórico de tarefas</x-slot>
    <x-slot name="contexto">{{ $tarefas->count() }} tarefas no histórico</x-slot>
    <x-slot name="acoes">
        <a href="{{ route('tarefas.index') }}"
           class="h-[34px] px-3 inline-flex items-center rounded-control border border-btn-line
                  text-[12.5px] font-semibold text-ink-dim hover:text-brand hover:border-brand transition whitespace-nowrap">
            Voltar ao quadro
        </a>
    </x-slot>

    {{--
        O quadro (US-040) só mostra concluídas e canceladas dos últimos 30
        dias; aqui é o caminho de auditoria — a listagem inteira, sem
        nenhum recorte por período (AC-097).
    --}}
    <x-tabela titulo="Histórico completo" sub="sem recorte de período" min="820px">
        <thead>
            <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                <th class="px-4 py-2.5 font-semibold">Tarefa</th>
                <th class="px-4 py-2.5 font-semibold">Sistema</th>
                <th class="px-4 py-2.5 font-semibold">Responsável</th>
                <th class="px-4 py-2.5 font-semibold">Etapa final</th>
                <th class="px-4 py-2.5 font-semibold">Data</th>
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
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-[13px] text-ink-mute">
                        Nenhuma tarefa concluída ou cancelada ainda.
                    </td>
                </tr>
            @endforelse
        </tbody>

        <x-slot name="rodape">
            <span>{{ $tarefas->count() }} tarefas no histórico</span>
        </x-slot>
    </x-tabela>
</x-app-layout>
