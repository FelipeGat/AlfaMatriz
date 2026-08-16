{{-- Concluído se mede pelo EVENTO de chegada em `concluida` — é ele que sabe a
     data. Os cards de WIP e fila são a foto de agora, sem competência: ou a
     tarefa está lá, ou não está. --}}
<div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))">
    <x-kpi-card rotulo="Concluídas na competência" :valor="number_format($concluidasQtd, 0, ',', '.')"
                :delta="$cicloMedioDias !== null ? 'ciclo médio de '.number_format($cicloMedioDias, 0, ',', '.').' dia(s)' : null"
                :sinal="$concluidasQtd > 0 ? 'bom' : 'neutro'"
                acento="good" icone="check-circle" />
    <x-kpi-card rotulo="Criadas na competência" :valor="number_format($criadasQtd, 0, ',', '.')"
                acento="accent" icone="plus" />
    {{-- Reprovação em portão devolvendo a tarefa à bancada: é a medida de
         retrabalho do mês. --}}
    <x-kpi-card rotulo="Devolvidas de portão" :valor="number_format($devolvidasQtd, 0, ',', '.')"
                delta="na competência"
                :sinal="$devolvidasQtd > 0 ? 'ruim' : 'neutro'"
                acento="crit" icone="arrow-uturn-left" />
    <x-kpi-card rotulo="Em andamento agora" :valor="number_format($emAndamentoQtd, 0, ',', '.')"
                delta="do desenvolvimento à porta da produção"
                acento="brand" icone="view-grid" />
    <x-kpi-card rotulo="Na fila agora" :valor="number_format($naFilaQtd, 0, ',', '.')"
                delta="abertas e backlog"
                acento="amber" icone="clock" />
</div>

<div class="grid gap-4 items-start" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr))">
    {{-- Na ordem do quadro, e não por volume: este painel espelha as colunas
         de `/tarefas`, e reordenar por contagem quebraria o espelho. --}}
    <x-painel titulo="O quadro agora" sub="tarefas por etapa">
        <dl class="divide-y divide-rule">
            @foreach ($quadroPorEtapa as $etapa)
                <div class="flex items-center justify-between py-2.5 first:pt-0 last:pb-0">
                    <dt class="text-[13px] text-ink-dim">{{ $etapa['rotulo'] }}</dt>
                    <dd class="font-mono text-[13.5px] text-ink tabular">{{ number_format($etapa['quantidade'], 0, ',', '.') }}</dd>
                </div>
            @endforeach
        </dl>
    </x-painel>

    {{-- Onde o tempo mora: média de permanência pelos eventos já fechados.
         Histórico inteiro, não a competência — permanência atravessa meses,
         e cortar por mês contaria só as estadias curtas. --}}
    <x-painel titulo="Permanência média por etapa" sub="histórico">
        <dl class="divide-y divide-rule">
            @foreach ($tempoPorEtapa as $etapa)
                <div class="flex items-center justify-between py-2.5 first:pt-0 last:pb-0">
                    <dt class="text-[13px] text-ink-dim">{{ $etapa['rotulo'] }}</dt>
                    <dd class="font-mono text-[13.5px] tabular {{ $etapa['dias'] !== null ? 'text-ink' : 'text-ink-faint' }}">
                        @if ($etapa['dias'] === null)
                            sem registro
                        @elseif ($etapa['dias'] < 1)
                            {{ number_format($etapa['dias'] * 24, 0, ',', '.') }} h
                        @else
                            {{ number_format($etapa['dias'], 1, ',', '.') }} d
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    </x-painel>

    <x-ranking :ranking="$rankingSistemas"
               titulo="Concluídas por sistema"
               nota="na competência"
               compacto />

    <x-ranking :ranking="$rankingResponsaveis"
               titulo="Concluídas por responsável"
               nota="na competência"
               compacto />
</div>

{{-- O diário de entrega do mês: cada conclusão com o ciclo dela — é o que se
     lê em retrospectiva e o que se manda no fechamento. --}}
<x-tabela min="820px" titulo="Concluídas na competência" :sub="number_format($concluidasQtd, 0, ',', '.').' entregas'">
    <thead>
        <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
            <th class="px-4 py-2.5 font-semibold">Tarefa</th>
            <th class="px-4 py-2.5 font-semibold">Sistema</th>
            <th class="px-4 py-2.5 font-semibold">Responsável</th>
            <th class="px-4 py-2.5 font-semibold">Prioridade</th>
            <th class="px-4 py-2.5 font-semibold">Concluída em</th>
            <th class="px-4 py-2.5 font-semibold text-right">Ciclo (dias)</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($conclusoes as $evento)
            <tr class="border-b border-rule hover:bg-chip transition">
                <td class="px-4 py-3 text-[13.5px] text-ink">{{ $evento->tarefa?->titulo ?? '—' }}</td>
                <td class="px-4 py-3 text-[13px] text-ink-dim">{{ $evento->tarefa?->sistema?->nome ?? 'Sem sistema' }}</td>
                <td class="px-4 py-3 text-[13px] text-ink-dim">{{ $evento->tarefa?->responsavel?->name ?? 'Sem responsável' }}</td>
                <td class="px-4 py-3 text-[13px] text-ink-dim">{{ \App\Models\Tarefa::PRIORIDADES[$evento->tarefa?->prioridade] ?? '—' }}</td>
                <td class="px-4 py-3 font-mono text-[13px] text-ink-dim whitespace-nowrap">{{ $evento->entrou_em->format('d/m/Y') }}</td>
                <td class="px-4 py-3 font-mono text-[13px] text-ink tabular text-right">
                    @if ($evento->tarefa !== null)
                        {{ number_format(abs($evento->entrou_em->diffInDays($evento->tarefa->iniciada_em ?? $evento->tarefa->created_at)), 0, ',', '.') }}
                    @else
                        —
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-4 py-6 text-[13px] text-ink-mute">Nenhuma conclusão neste recorte.</td></tr>
        @endforelse
    </tbody>
</x-tabela>
