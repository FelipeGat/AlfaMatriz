{{--
    Incidentes › Alertas recebidos (#394): o que o AlfaMonitor empurrou pelo
    webhook e o Matriz guardou. É aqui que aparecem servidor, fonte e
    Hostinger — a tabela de incidentes do monitor só tem disponibilidade e
    aparelhos. Só mostra — quem avisa é o monitor. Os vencimentos da
    Hostinger só chegam a quem tem `monitor_hostinger` (o controller filtra).
    Espera: $filtros, $agora, $historico, $quedas, $ligado, $ultimoRecebido, $kpis.
--}}
@use('App\Models\MonitorAlerta')
@php
    $kinds = MonitorAlerta::KINDS;
@endphp

<div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(min(210px, 100%), 1fr))">
    <x-kpi-card rotulo="Fora agora" :valor="$kpis['fora']" acento="crit" icone="alert-triangle" />
    <x-kpi-card rotulo="Críticos agora" :valor="$kpis['criticos']" acento="warn" icone="bolt" />
    <x-kpi-card rotulo="Quedas em 7 dias" :valor="$kpis['quedas7d']"
                delta="lembretes não contam" acento="amber" icone="trending-down" />
    <x-kpi-card rotulo="Voltaram em 24h" :valor="$kpis['voltas24h']" acento="accent" icone="check-circle" />
</div>

<form method="GET" class="flex flex-wrap items-center gap-2">
    <input type="hidden" name="origem" value="alertas">

    <select name="kind" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
        <option value="">Todos os tipos</option>
        @foreach ($kinds as $chave => $rotulo)
            <option value="{{ $chave }}" @selected($filtros['kind'] === $chave)>{{ $rotulo }}</option>
        @endforeach
    </select>

    <select name="periodo" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
        @foreach (\App\Http\Controllers\PainelDoMonitorController::PERIODOS_DOS_ALERTAS as $dias)
            <option value="{{ $dias }}" @selected($filtros['periodo'] === $dias)>Histórico de {{ $dias }} dias</option>
        @endforeach
    </select>

    <label class="h-[34px] inline-flex items-center gap-2 px-1 text-[13px] text-ink-dim">
        <input type="checkbox" name="lembretes" value="1" @checked($filtros['lembretes'])
               class="rounded border-line bg-input text-brand focus:ring-0">
        Mostrar lembretes
    </label>

    <button type="submit"
            class="h-[34px] px-3 rounded-control border border-btn-line text-ink-dim
                   text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
        Filtrar
    </button>

    @if ($ultimoRecebido)
        <span class="ml-auto font-mono text-[11px] text-ink-faint whitespace-nowrap">
            último alerta recebido {{ $ultimoRecebido->format('d/m H:i') }} ({{ $ultimoRecebido->diffForHumans() }})
        </span>
    @endif
</form>

@if (! $ultimoRecebido)
    <div class="rounded-panel border border-line bg-surface px-5 py-8 text-center">
        <p class="text-[13px] font-medium text-ink">O AlfaMonitor ainda não mandou nenhum alerta.</p>
        <p class="mt-1 text-[12.5px] text-ink-mute">
            @if ($ligado)
                O token já foi emitido; falta o monitor mandar o primeiro alerta pelo webhook «AlfaMatriz».
            @else
                O monitor manda os alertas com o token do <span class="font-mono">alfa:monitor-token</span>, cadastrado no webhook «AlfaMatriz» dele.
            @endif
        </p>
    </div>
@else
    <x-tabela min="980px" class="tabela-zebrada" titulo="Fora agora"
              :sub="$agora->count().' '.($agora->count() === 1 ? 'item' : 'itens')">
        <thead>
            <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                <th class="px-4 py-2.5 font-semibold">O quê</th>
                <th class="px-4 py-2.5 font-semibold">Cliente</th>
                <th class="px-4 py-2.5 font-semibold">Gravidade</th>
                <th class="px-4 py-2.5 font-semibold">Detalhe</th>
                <th class="px-4 py-2.5 font-semibold">Fora desde</th>
                <th class="px-4 py-2.5 font-semibold">Último aviso</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($agora as $alerta)
                <tr class="border-b border-rule hover:bg-chip transition align-top">
                    @include('monitor._alerta-item', ['alerta' => $alerta])
                    {{-- Casou com o cadastro: o nome do Matriz, e não o que o
                         monitor mandou (que vem do AlfaControl/AlfaGym). --}}
                    <td class="px-4 py-3 text-[13px] text-ink-dim">{{ $alerta->clienteDoMatriz?->nome ?? $alerta->cliente ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <x-badge :tom="MonitorAlerta::tomDaSeveridade($alerta->severity)">{{ MonitorAlerta::rotuloDaSeveridade($alerta->severity) }}</x-badge>
                    </td>
                    <td class="px-4 py-3 max-w-[360px]">
                        <p class="text-[12.5px] text-ink-dim truncate" title="{{ $alerta->body }}">{{ $alerta->body ?: '—' }}</p>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        @if ($alerta->caiu_em)
                            <p class="font-sans tabular text-[12.5px] text-ink-dim">{{ $alerta->caiu_em->format('d/m/Y H:i') }}</p>
                            <p class="text-[11px] text-ink-faint">há {{ $alerta->caiu_em->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</p>
                        @else
                            <span class="text-[12.5px] text-ink-faint">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 font-sans tabular text-[12.5px] text-ink-dim whitespace-nowrap">
                        {{ $alerta->ultimo_aviso_em?->format('d/m H:i') ?? '—' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-6 text-center text-[13px] text-ink-mute">
                        Nada fora do ar{{ $filtros['kind'] ? ' neste tipo' : '' }}.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-tabela>

    <x-tabela min="1000px" class="tabela-zebrada" titulo="Histórico"
              :sub="'últimos '.$filtros['periodo'].' dias · '.$historico->total().' '.($historico->total() === 1 ? 'evento' : 'eventos')">
        <thead>
            <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                <th class="px-4 py-2.5 font-semibold">Quando</th>
                <th class="px-4 py-2.5 font-semibold">O quê</th>
                <th class="px-4 py-2.5 font-semibold">Evento</th>
                <th class="px-4 py-2.5 font-semibold">Gravidade</th>
                <th class="px-4 py-2.5 font-semibold">Detalhe</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($historico as $evento)
                @php
                    $caiu = $evento->resolved && $evento->incident_id ? ($quedas[$evento->incident_id] ?? null) : null;
                @endphp
                <tr class="border-b border-rule hover:bg-chip transition align-top">
                    <td class="px-4 py-3 font-sans tabular text-[12.5px] text-ink-dim whitespace-nowrap">
                        {{ $evento->ocorreu_em->format('d/m/Y H:i') }}
                    </td>
                    @include('monitor._alerta-item', ['alerta' => $evento->alerta])
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <x-badge :tom="match ($evento->rotulo()) { 'Voltou' => 'bom', 'Caiu' => 'critico', default => 'neutro' }">{{ $evento->rotulo() }}</x-badge>
                            {{-- Chegou depois de um evento mais novo (estava na fila de
                                 novas tentativas do monitor): está no histórico, mas
                                 não mudou o estado. --}}
                            @if (! $evento->aplicado && ! $evento->derived)
                                <x-badge title="Chegou depois de um evento mais novo da mesma coisa">fora de ordem</x-badge>
                            @endif
                        </div>
                        @if ($caiu)
                            <p class="mt-1 text-[11px] text-ink-faint whitespace-nowrap">
                                ficou fora {{ \Illuminate\Support\Carbon::parse($caiu)->diffForHumans($evento->ocorreu_em, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}
                            </p>
                        @elseif ($evento->resolved && ! $evento->derived)
                            <p class="mt-1 text-[11px] text-ink-faint whitespace-nowrap">sem queda registrada</p>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <x-badge :tom="MonitorAlerta::tomDaSeveridade($evento->severity)">{{ MonitorAlerta::rotuloDaSeveridade($evento->severity) }}</x-badge>
                    </td>
                    <td class="px-4 py-3 max-w-[380px]">
                        <p class="text-[12.5px] text-ink-dim truncate" title="{{ $evento->body }}">{{ $evento->body ?: '—' }}</p>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-6 text-center text-[13px] text-ink-mute">
                        Nenhum evento neste recorte.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-tabela>

    {{ $historico->links() }}
@endif
