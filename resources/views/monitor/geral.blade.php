{{--
    Monitor › Geral (#394): o retrato de agora do AlfaMonitor.
    Espera: $resposta (visao-geral) e $hostinger (nulo sem `monitor_hostinger`).
--}}
@use('App\Services\Monitor\FormatoDoMonitor', 'F')
@php
    $topo = $resposta->get('topo', []);
    $atencao = collect($resposta->get('atencao', []));
    $servidores = collect($resposta->get('servidores', []));
    $disponibilidade = collect($resposta->get('disponibilidade', []));
    $acesso = collect($resposta->get('acesso', []));

    $contexto = $resposta->temDados()
        ? ($atencao->isEmpty() ? 'tudo em ordem' : $atencao->count().' '.($atencao->count() === 1 ? 'ponto de atenção' : 'pontos de atenção'))
        : null;
@endphp

<x-monitor.tela tela="geral" :resposta="$resposta" :contexto="$contexto">
    <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(min(210px, 100%), 1fr))">
        <x-kpi-card rotulo="Servidores online"
                    :valor="($topo['servidores_online'] ?? 0).' / '.($topo['servidores_total'] ?? 0)"
                    :acento="($topo['servidores_online'] ?? 0) < ($topo['servidores_total'] ?? 0) ? 'crit' : 'good'" icone="cube" />
        <x-kpi-card rotulo="Sistemas no ar"
                    :valor="($topo['monitores_no_ar'] ?? 0).' / '.($topo['monitores_total'] ?? 0)"
                    :acento="($topo['monitores_no_ar'] ?? 0) < ($topo['monitores_total'] ?? 0) ? 'crit' : 'good'" icone="bolt" />
        <x-kpi-card rotulo="Dispositivos online"
                    :valor="($topo['dispositivos_online'] ?? 0).' / '.($topo['dispositivos_total'] ?? 0)"
                    acento="accent" icone="key" />
        <x-kpi-card rotulo="Acessos hoje" :valor="F::inteiro($topo['acessos_hoje'] ?? null)"
                    acento="accent" icone="users" />
    </div>

    @if ($hostinger)
        @include('monitor._hostinger', ['hostinger' => $hostinger])
    @endif

    <x-tabela min="760px" class="tabela-zebrada" titulo="Atenção"
              :sub="$atencao->isEmpty() ? 'nada agora' : ($topo['atencao_crit'] ?? 0).' críticos · '.($topo['atencao_warn'] ?? 0).' avisos'">
        <thead>
            <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                <th class="px-4 py-2.5 font-semibold">Gravidade</th>
                <th class="px-4 py-2.5 font-semibold">O quê</th>
                <th class="px-4 py-2.5 font-semibold">Detalhe</th>
                <th class="px-4 py-2.5 font-semibold">Desde</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($atencao as $item)
                @php
                    $idCliente = F::idDoCliente($item['ref'] ?? null);
                    $link = match (true) {
                        $idCliente !== null => route('monitor.cliente', $idCliente),
                        isset($item['ref']['servidor']) => route('monitor.servidor', $item['ref']['servidor']),
                        default => null,
                    };
                    // ISO desde 17/10/2026; antes vinha o texto pronto ("há 33 min").
                    $desde = F::hora($item['desde'] ?? null);
                @endphp
                <tr class="border-b border-rule hover:bg-chip transition align-top">
                    <td class="px-4 py-3">
                        <x-badge :tom="F::tom($item['sev'] ?? null)" ponto>{{ F::rotuloDaGravidade($item['sev'] ?? null) }}</x-badge>
                    </td>
                    <td class="px-4 py-3">
                        @if ($link)
                            <a href="{{ $link }}" class="text-[13px] font-medium text-ink hover:text-brand-text transition">{{ $item['nome'] ?? '—' }}</a>
                        @else
                            <p class="text-[13px] font-medium text-ink">{{ $item['nome'] ?? '—' }}</p>
                        @endif
                        <p class="text-[11px] text-ink-faint">{{ ($item['tipo'] ?? '') === 'dispositivos' ? 'Aparelhos de acesso' : 'Disponibilidade' }}</p>
                    </td>
                    <td class="px-4 py-3 max-w-[480px]">
                        <p class="text-[12.5px] text-ink-dim">{{ $item['detalhe'] ?? '—' }}</p>
                    </td>
                    <td class="px-4 py-3 text-[12.5px] text-ink-dim whitespace-nowrap">
                        @if ($desde)
                            <span title="{{ $desde->format('d/m/Y H:i') }}">{{ $desde->diffForHumans() }}</span>
                        @else
                            {{ $item['desde'] ?? '—' }}
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-4 py-6 text-center text-[13px] text-ink-mute">Nada pedindo atenção agora.</td>
                </tr>
            @endforelse
        </tbody>
    </x-tabela>

    {{-- 260px: os quatro servidores de hoje cabem numa linha a partir de
         ~1280px de tela, e encolhem para duas antes de o medidor espremer. --}}
    <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(min(260px, 100%), 1fr))">
        @foreach ($servidores as $servidor)
            @php $vps = $servidor['vps'] ?? null; @endphp
            <x-painel :titulo="$servidor['nome'] ?? '—'">
                <x-slot name="acoes">
                    <x-badge :tom="($servidor['online'] ?? false) ? F::tom($servidor['sev'] ?? null) : 'critico'" ponto>
                        {{ ($servidor['online'] ?? false) ? F::rotuloDaGravidade($servidor['sev'] ?? null) : 'Offline' }}
                    </x-badge>
                </x-slot>

                <div class="space-y-3">
                    <x-monitor.medidor rotulo="CPU" :pct="$servidor['cpu_pct'] ?? null"
                                       :detalhe="'média 24h '.F::pct($servidor['cpu_stat']['media_24h_pct'] ?? null).' · pico 1h '.F::pct($servidor['cpu_stat']['pico_1h_pct'] ?? null)" />
                    <x-monitor.medidor rotulo="Memória" :pct="$servidor['mem_pct'] ?? null"
                                       :detalhe="F::bytes($servidor['mem_usada_bytes'] ?? null).' de '.F::bytes($servidor['mem_total_bytes'] ?? null)" />
                    <x-monitor.medidor rotulo="Disco" :pct="$servidor['disco_pct'] ?? null"
                                       :detalhe="F::bytes($servidor['disco_usado_bytes'] ?? null).' de '.F::bytes($servidor['disco_total_bytes'] ?? null)" />
                    @if ($vps)
                        <x-monitor.medidor rotulo="Banda do mês" :pct="$vps['banda_pct'] ?? null"
                                           :detalhe="F::bytes($vps['banda_usada_bytes'] ?? null).' de '.F::bytes($vps['banda_cota_bytes'] ?? null)" />
                    @endif

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-rule pt-3 text-[11.5px] text-ink-mute">
                        <span>carga <span class="font-sans tabular text-ink-dim">{{ collect($servidor['load'] ?? [])->map(fn ($l) => number_format((float) $l, 2, ',', ''))->implode(' · ') ?: '—' }}</span></span>
                        <span>steal <span class="font-sans tabular text-ink-dim">{{ F::pct($servidor['steal_pct'] ?? null) }}</span></span>
                        @if ($vps)
                            <span>VPS <x-badge :tom="($vps['estado_tipo'] ?? '') === 'ok' ? 'bom' : 'critico'">{{ $vps['estado'] ?? '—' }}</x-badge></span>
                        @endif
                        @if (! empty($servidor['slug']))
                            <a href="{{ route('monitor.servidor', $servidor['slug']) }}"
                               class="ml-auto font-semibold text-brand-text hover:underline">Ver servidor ›</a>
                        @endif
                    </div>
                </div>
            </x-painel>
        @endforeach
    </div>

    <x-tabela min="980px" class="tabela-zebrada" titulo="Disponibilidade dos sistemas"
              :sub="$disponibilidade->count().' '.($disponibilidade->count() === 1 ? 'monitor' : 'monitores')">
        <thead>
            <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                <th class="px-4 py-2.5 font-semibold">Sistema</th>
                <th class="px-4 py-2.5 font-semibold">Agora</th>
                <th class="px-4 py-2.5 font-semibold text-right">Resposta</th>
                <th class="px-4 py-2.5 font-semibold text-right">No ar 24h</th>
                <th class="px-4 py-2.5 font-semibold text-right">No ar 30d</th>
                <th class="px-4 py-2.5 font-semibold w-[34%]">Histórico</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($disponibilidade as $monitor)
                <tr class="border-b border-rule hover:bg-chip transition align-middle">
                    <td class="px-4 py-3">
                        <p class="text-[13px] font-medium text-ink">{{ $monitor['nome'] ?? '—' }}</p>
                        <p class="max-w-[260px] truncate font-mono text-[11px] text-ink-faint" title="{{ $monitor['alvo'] ?? '' }}">{{ $monitor['tipo'] ?? '' }} · {{ $monitor['alvo'] ?? '' }}</p>
                    </td>
                    <td class="px-4 py-3">
                        <x-badge :tom="F::tom($monitor['estado'] ?? null)" ponto>{{ match ($monitor['estado'] ?? null) { 'up' => 'No ar', 'lento' => 'Lento', 'down' => 'Fora', default => $monitor['estado'] ?? '—' } }}</x-badge>
                    </td>
                    <td class="px-4 py-3 text-right font-sans tabular text-[12.5px] text-ink-dim whitespace-nowrap">
                        {{ F::ms($monitor['resp_ms'] ?? null) }}
                        <span class="block text-[11px] text-ink-faint">média {{ F::ms($monitor['media_24h_ms'] ?? null) }}</span>
                    </td>
                    <td class="px-4 py-3 text-right font-sans tabular text-[12.5px] text-ink-dim">{{ F::noAr($monitor['pct_no_ar_24h'] ?? null) }}</td>
                    <td class="px-4 py-3 text-right font-sans tabular text-[12.5px] text-ink-dim">{{ F::noAr($monitor['pct_no_ar_30d'] ?? null) }}</td>
                    <td class="px-4 py-3"><x-monitor.barra :barra="$monitor['barra'] ?? null" /></td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-6 text-center text-[13px] text-ink-mute">Nenhum sistema monitorado.</td>
                </tr>
            @endforelse
        </tbody>
    </x-tabela>

    <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(min(300px, 100%), 1fr))">
        @foreach ($acesso as $fonte)
            @php $ultima = F::hora($fonte['ultima_coleta'] ?? null); @endphp
            <x-painel :titulo="'Acesso · '.($fonte['fonte'] ?? '')">
                <x-slot name="acoes">
                    <a href="{{ route('monitor.acesso', ['fonte' => $fonte['fonte'] ?? null]) }}"
                       class="text-[12px] font-semibold text-brand-text hover:underline">Ver aparelhos ›</a>
                </x-slot>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Liberados hoje</p>
                        <p class="font-display tabular text-[20px] font-semibold text-ink">{{ F::inteiro($fonte['hoje']['liberados'] ?? null) }}</p>
                    </div>
                    <div>
                        <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Negados hoje</p>
                        <p class="font-display tabular text-[20px] font-semibold text-ink">{{ F::inteiro($fonte['hoje']['negados'] ?? null) }}</p>
                    </div>
                    <div>
                        <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Aparelhos online</p>
                        <p class="font-display tabular text-[20px] font-semibold text-ink">{{ ($fonte['online'] ?? 0) }} / {{ ($fonte['total'] ?? 0) }}</p>
                    </div>
                </div>

                <p class="mt-3 text-[11.5px] text-ink-faint">
                    @if (($fonte['coleta'] ?? null) !== 'ok')
                        <span class="font-semibold text-crit">coleta com problema</span> ·
                    @endif
                    última coleta {{ $ultima ? $ultima->format('d/m H:i') : '—' }}
                </p>
            </x-painel>
        @endforeach
    </div>
</x-monitor.tela>
