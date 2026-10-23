{{--
    Monitor › Servidor (#394): os números de agora, a última hora, os discos
    e os processos que mais gastam CPU. Só mostra: reiniciar, parar e ligar a
    VPS continuam no monitor (decisão do dono).
    Espera: $resposta (servidores/{slug}).
--}}
@use('App\Services\Monitor\FormatoDoMonitor', 'F')
@php
    $s = $resposta->dados ?? [];
    $vps = $s['vps'] ?? null;
    $discos = collect($s['discos'] ?? []);
    $processos = collect($s['processos'] ?? []);
    $load = collect($s['load'] ?? [])->map(fn ($l) => number_format((float) $l, 2, ',', ''));
@endphp

<x-monitor.tela tela="servidor" :resposta="$resposta"
                :migalhas="[['rotulo' => 'Monitor', 'rota' => route('monitor.geral')]]"
                :atual="$s['nome'] ?? 'Servidor'"
                :contexto="$resposta->temDados() ? (($s['online'] ?? false) ? 'online' : 'offline').($vps ? ' · '.($vps['hostname'] ?? '') : '') : null">
    <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(min(210px, 100%), 1fr))">
        <x-kpi-card rotulo="CPU agora" :valor="F::pct($s['cpu_pct'] ?? null)" acento="accent" icone="bolt"
                    :delta="'média 24h '.F::pct($s['cpu_stat']['media_24h_pct'] ?? null)" />
        <x-kpi-card rotulo="Carga (1 · 5 · 15 min)" :valor="$load->implode(' · ') ?: '—'" acento="accent" icone="trending-up" />
        <x-kpi-card rotulo="Steal" :valor="F::pct($s['steal_pct'] ?? null)" acento="amber" icone="alert-triangle"
                    delta="CPU tomada pelo provedor" />
        <x-kpi-card rotulo="Memória agora" :valor="F::pct($s['mem_pct'] ?? null)" acento="accent" icone="cube"
                    :delta="F::bytes($s['mem_usada_bytes'] ?? null).' de '.F::bytes($s['mem_total_bytes'] ?? null)" />
    </div>

    <x-painel titulo="Última hora" sub="CPU e memória, por minuto">
        <x-monitor.grafico-linha :pontos="$s['serie_1h']['pontos'] ?? []"
                                 :series="[['chave' => 'cpu', 'rotulo' => 'CPU', 'cor' => 'accent'], ['chave' => 'mem', 'rotulo' => 'Memória', 'cor' => 'amber']]" />
    </x-painel>

    <div class="grid gap-3 lg:grid-cols-2">
        <x-painel titulo="Discos">
            <div class="space-y-3">
                @forelse ($discos as $disco)
                    <x-monitor.medidor :rotulo="$disco['ponto'] ?? '—'" :pct="$disco['pct'] ?? null"
                                       :detalhe="F::bytes($disco['usado_bytes'] ?? null).' de '.F::bytes($disco['total_bytes'] ?? null)" />
                @empty
                    <p class="text-[12.5px] text-ink-mute">O monitor não mandou os discos deste servidor.</p>
                @endforelse
            </div>
        </x-painel>

        @if ($vps)
            <x-painel titulo="VPS na Hostinger" :sub="$vps['hostname'] ?? null">
                <div class="space-y-3">
                    <div class="flex items-center gap-2 text-[13px] text-ink-dim">
                        Estado <x-badge :tom="($vps['estado_tipo'] ?? '') === 'ok' ? 'bom' : 'critico'" ponto>{{ $vps['estado'] ?? '—' }}</x-badge>
                    </div>
                    <x-monitor.medidor rotulo="Banda do mês" :pct="$vps['banda_pct'] ?? null"
                                       :detalhe="F::bytes($vps['banda_usada_bytes'] ?? null).' de '.F::bytes($vps['banda_cota_bytes'] ?? null)" />
                </div>
            </x-painel>
        @endif
    </div>

    <x-tabela min="720px" class="tabela-zebrada" titulo="Processos" sub="os que mais gastam CPU">
        <thead>
            <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                <th class="px-4 py-2.5 font-semibold">Programa</th>
                <th class="px-4 py-2.5 font-semibold">Usuário</th>
                <th class="px-4 py-2.5 font-semibold text-right">PID</th>
                <th class="px-4 py-2.5 font-semibold text-right">CPU</th>
                <th class="px-4 py-2.5 font-semibold text-right">Memória</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($processos as $p)
                <tr class="border-b border-rule hover:bg-chip transition">
                    <td class="px-4 py-2.5 max-w-[420px] truncate font-mono text-[12px] text-ink" title="{{ $p['comando'] ?? '' }}">{{ $p['comando'] ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-[12.5px] text-ink-dim">{{ $p['usuario'] ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-right font-sans tabular text-[12.5px] text-ink-faint">{{ $p['pid'] ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-right font-sans tabular text-[12.5px] text-ink-dim">{{ F::pct($p['cpu_pct'] ?? null) }}</td>
                    <td class="px-4 py-2.5 text-right font-sans tabular text-[12.5px] text-ink-dim">{{ F::pct($p['mem_pct'] ?? null) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-6 text-center text-[13px] text-ink-mute">
                        {{ ($s['agente'] ?? false) ? 'Nenhum processo listado.' : 'Este servidor não tem o agente do monitor, que é quem lista os processos.' }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-tabela>
</x-monitor.tela>
