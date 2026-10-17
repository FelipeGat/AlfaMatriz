{{--
    Monitor › Incidentes (#394). Duas origens, em duas abas:

    - "Do monitor": a tabela de incidentes do AlfaMonitor pela API —
      disponibilidade e aparelhos, inclusive as quedas curtas que nem chegaram
      a avisar. Sem sonda (decisão do dono).
    - "Alertas recebidos": o que o webhook guardou aqui, onde estão servidor,
      fonte e Hostinger, que só chegam por ele.

    Espera ($origem = monitor): $resposta, $filtros, $incidentes (todos), $pagina
    (os 50 da página, na visão em lista), $porCliente.
    Espera ($origem = alertas): o que `monitor._alertas` espera.
--}}
@use('App\Services\Monitor\FormatoDoMonitor', 'F')
@use('App\Http\Controllers\PainelDoMonitorController', 'Painel')
@php
    $doMonitor = $origem === 'monitor';

    $contexto = $doMonitor
        ? ($resposta->temDados() ? $incidentes->count().' '.($incidentes->count() === 1 ? 'incidente' : 'incidentes').($filtros['estado'] === 'aberto' ? ' em aberto' : ' em '.$filtros['dias'].' '.($filtros['dias'] === 1 ? 'dia' : 'dias')) : null)
        : $kpis['fora'].' '.($kpis['fora'] === 1 ? 'alerta aberto' : 'alertas abertos');
@endphp

<x-monitor.tela tela="incidentes" :resposta="$doMonitor ? $resposta : null" :contexto="$contexto">
    <x-abas>
        <x-abas.item :href="route('monitor.incidentes')" :ativo="$doMonitor">Do monitor</x-abas.item>
        <x-abas.item :href="route('monitor.incidentes', ['origem' => 'alertas'])" :ativo="! $doMonitor">Alertas recebidos</x-abas.item>
    </x-abas>

    @if (! $doMonitor)
        @include('monitor._alertas')
    @else
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <select name="estado" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="aberto" @selected($filtros['estado'] === 'aberto')>Em aberto</option>
                <option value="historico" @selected($filtros['estado'] === 'historico')>Histórico</option>
            </select>

            <select name="dias" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim"
                    title="Vale para o histórico">
                @foreach (Painel::DIAS_DE_HISTORICO as $dias)
                    <option value="{{ $dias }}" @selected($filtros['dias'] === $dias)>{{ $dias === 1 ? 'Último dia' : 'Últimos '.$dias.' dias' }}</option>
                @endforeach
            </select>

            <select name="sev" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="" @selected($filtros['sev'] === '')>Críticos e avisos</option>
                <option value="crit" @selected($filtros['sev'] === 'crit')>Só críticos</option>
                <option value="warn" @selected($filtros['sev'] === 'warn')>Só avisos</option>
            </select>

            <select name="visao" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="lista" @selected($filtros['visao'] === 'lista')>Em lista</option>
                <option value="cliente" @selected($filtros['visao'] === 'cliente')>Por cliente</option>
            </select>

            <button type="submit"
                    class="h-[34px] px-3 rounded-control border border-btn-line text-ink-dim
                           text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
                Filtrar
            </button>

            <span class="ml-auto text-[11.5px] text-ink-faint">Fora do horário e silenciados ficam de fora, como no monitor.</span>
        </form>

        @php
            $grupos = match (true) {
                $incidentes->isEmpty() => collect(),
                $filtros['visao'] === 'cliente' => $porCliente,
                default => collect(['' => $pagina->getCollection()]),
            };
        @endphp

        @forelse ($grupos as $grupo => $lista)
            <x-tabela min="980px" class="tabela-zebrada" :titulo="$grupo !== '' ? $grupo : null"
                      :sub="$grupo !== '' ? $lista->count().' '.($lista->count() === 1 ? 'incidente' : 'incidentes') : null">
                <thead>
                    <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                        <th class="px-4 py-2.5 font-semibold">Gravidade</th>
                        <th class="px-4 py-2.5 font-semibold">O quê</th>
                        @if ($filtros['visao'] === 'lista')
                            <th class="px-4 py-2.5 font-semibold">Cliente</th>
                        @endif
                        <th class="px-4 py-2.5 font-semibold">Caiu</th>
                        <th class="px-4 py-2.5 font-semibold">Voltou</th>
                        <th class="px-4 py-2.5 font-semibold text-right">Fora por</th>
                        <th class="px-4 py-2.5 font-semibold text-right">Na semana</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lista as $inc)
                        @php
                            $inicio = F::hora($inc['inicio'] ?? null);
                            $fim = F::hora($inc['fim'] ?? null);
                        @endphp
                        <tr class="border-b border-rule hover:bg-chip transition align-top">
                            <td class="px-4 py-3">
                                {{-- A gravidade fica mesmo depois de resolvido (é por ela que
                                     se filtra), só sem cor: cor é para o que está fora agora. --}}
                                <x-badge :tom="($inc['aberto'] ?? false) ? F::tom($inc['sev'] ?? null) : 'neutro'" ponto>
                                    {{ in_array($inc['sev'] ?? null, ['crit', 'warn'], true) ? F::rotuloDaGravidade($inc['sev']) : 'Resolvido' }}
                                </x-badge>
                            </td>
                            <td class="px-4 py-3">
                                <p class="text-[13px] font-medium text-ink">{{ $inc['nome'] ?? '—' }}</p>
                                <p class="text-[11px] text-ink-faint">{{ ($inc['tipo'] ?? '') === 'dispositivos' ? 'Aparelho · '.($inc['origem'] ?? '') : 'Disponibilidade' }}</p>
                            </td>
                            @if ($filtros['visao'] === 'lista')
                                <td class="px-4 py-3 text-[12.5px] text-ink-dim">
                                    @if ($idCliente = F::idDoCliente($inc['cliente'] ?? null))
                                        <a href="{{ route('monitor.cliente', $idCliente) }}" class="hover:text-brand-text transition">{{ $inc['cliente']['nome'] ?? '—' }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                            @endif
                            <td class="px-4 py-3 font-sans tabular text-[12.5px] text-ink-dim whitespace-nowrap">{{ $inicio?->format('d/m H:i') ?? '—' }}</td>
                            <td class="px-4 py-3 font-sans tabular text-[12.5px] text-ink-dim whitespace-nowrap">{{ $fim?->format('d/m H:i') ?? 'ainda fora' }}</td>
                            <td class="px-4 py-3 text-right font-sans tabular text-[12.5px] text-ink-dim whitespace-nowrap">{{ F::duracao($inc['duracao_s'] ?? null) }}</td>
                            <td class="px-4 py-3 text-right font-sans tabular text-[12.5px] text-ink-dim"
                                title="Outras quedas da mesma coisa nos últimos 7 dias, além desta">{{ $inc['recorrencia_7d'] ?? 0 }}×</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-tabela>
        @empty
            <div class="rounded-panel border border-line bg-surface px-5 py-8 text-center text-[13px] text-ink-mute">
                {{ $filtros['estado'] === 'aberto' ? 'Nenhum incidente em aberto.' : 'Nenhum incidente neste período.' }}
            </div>
        @endforelse

        @if ($filtros['visao'] === 'lista')
            {{ $pagina->links() }}
        @endif
    @endif
</x-monitor.tela>
