<x-app-layout>
    <x-slot name="titulo">Histórico de tarefas</x-slot>
    <x-slot name="contexto">
        {{ $tarefas->total() < $totalNoHistorico
            ? $tarefas->total().' de '.$totalNoHistorico.' tarefas no histórico'
            : $tarefas->total().' tarefas no histórico' }}
    </x-slot>

    <div class="flex flex-col gap-4">
        @include('tarefas._abas', ['ativa' => 'historico'])

        {{-- Aqui o recorte ganha um campo a mais que o quadro: concluída e
             cancelada são as duas únicas linhas possíveis, e separar uma da
             outra é a primeira pergunta de quem audita. --}}
        @include('tarefas._filtros', [
            'filtros' => $filtros,
            'sistemas' => $sistemas,
            'usuarios' => $usuarios,
            'comDesfecho' => true,
        ])

    {{--
        O quadro é só o trabalho em curso: tarefa encerrada sai de lá e passa a
        viver aqui (AC-082, AC-096). Esta tela é o caminho de auditoria — a
        listagem inteira, sem nenhum recorte por período (AC-097) — e também a
        porta de volta: reabrir uma concluída só era possível pelo menu do card
        no quadro, e sem a coluna Concluída esse caminho deixaria de existir
        (AC-131).
    --}}
    <x-tabela titulo="Histórico completo" sub="sem recorte de período" min="1040px">
        <thead>
            <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                <th class="px-4 py-2.5 font-semibold">Tarefa</th>
                <th class="px-4 py-2.5 font-semibold">Sistema</th>
                <th class="px-4 py-2.5 font-semibold">Responsável</th>
                <th class="px-4 py-2.5 font-semibold">Prioridade</th>
                <th class="px-4 py-2.5 font-semibold">Desfecho</th>
                <th class="px-4 py-2.5 font-semibold text-right">Ciclo</th>
                <th class="px-4 py-2.5 font-semibold">Encerrada em</th>
                <th class="px-4 py-2.5 font-semibold text-right">Ação</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($tarefas as $tarefa)
                <tr class="border-b border-rule hover:bg-chip transition">
                    {{-- Título e resumo na mesma célula, como no card: quem
                         audita precisa saber o QUE era a tarefa, não só o nome. --}}
                    <td class="px-4 py-3">
                        <p class="text-[13.5px] text-ink">{{ $tarefa->titulo }}</p>
                        @if (filled($tarefa->resumo))
                            <p class="mt-0.5 text-[12px] leading-snug text-ink-mute">{{ $tarefa->resumo }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                        {{ $tarefa->sistema?->nome ?? 'Sem sistema' }}
                    </td>
                    <td class="px-4 py-3 text-[13px] text-ink-dim">{{ $tarefa->responsavel?->name ?? 'Sem responsável' }}</td>
                    <td class="px-4 py-3">
                        {{-- Mesma escala de tons do card (AC-126). --}}
                        <x-badge :tom="['baixa' => 'neutro', 'media' => 'marca', 'alta' => 'atencao', 'critica' => 'critico'][$tarefa->prioridade] ?? 'neutro'">
                            {{ \App\Models\Tarefa::PRIORIDADES[$tarefa->prioridade] ?? $tarefa->prioridade }}
                        </x-badge>
                    </td>
                    <td class="px-4 py-3">
                        <x-badge :tom="$tarefa->status === 'concluida' ? 'bom' : 'critico'">
                            {{ \App\Models\Tarefa::STATUS[$tarefa->status] ?? $tarefa->status }}
                        </x-badge>
                    </td>
                    {{-- O número que justifica cronometrar cada etapa: quanto a
                         tarefa levou da criação até encerrar (AC-133). --}}
                    <td class="px-4 py-3 text-right font-mono text-[13px] text-ink-dim whitespace-nowrap"
                        title="Da criação em {{ $tarefa->created_at->format('d/m/Y H:i') }} até o encerramento">
                        {{ ($ciclo = $tarefa->duracaoDoCiclo()) === null ? '—' : \App\Models\Tarefa::duracaoCurta($ciclo) }}
                    </td>
                    <td class="px-4 py-3 font-mono text-[13px] text-ink-dim whitespace-nowrap">
                        {{ $tarefa->updated_at->format('d/m/Y') }}
                        <span class="text-ink-faint">{{ $tarefa->updated_at->format('H:i') }}</span>
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
                    {{-- Histórico vazio e busca sem resultado são duas coisas
                         diferentes: a primeira não tem o que fazer, a segunda
                         se resolve mudando o recorte. --}}
                    <td colspan="8" class="px-4 py-8 text-center text-[13px] text-ink-mute">
                        {{ $totalNoHistorico > 0
                            ? 'Nenhuma tarefa encontrada com esse recorte.'
                            : 'Nenhuma tarefa concluída ou cancelada ainda.' }}
                    </td>
                </tr>
            @endforelse
        </tbody>

        <x-slot name="rodape">
            <span>{{ $tarefas->count() }} de {{ $tarefas->total() }} tarefas no histórico</span>
            @if ($tarefas->hasPages())
                <span>· página {{ $tarefas->currentPage() }} de {{ $tarefas->lastPage() }}</span>
            @endif
        </x-slot>
    </x-tabela>

    @if ($tarefas->hasPages())
        <div>{{ $tarefas->links() }}</div>
    @endif
    </div>
</x-app-layout>
