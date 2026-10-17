{{--
    Monitor › Clientes (#394): quem o monitor acompanha, com o que está
    ligado a cada um. A contagem de sondas fica de fora (regra de nada de
    sonda, decisão do dono).
    Espera: $resposta (clientes), $clientes, $busca.
--}}
@use('App\Services\Monitor\FormatoDoMonitor', 'F')
@php
    $total = count($resposta->get('clientes', []));
@endphp

<x-monitor.tela tela="clientes" :resposta="$resposta"
                :contexto="$resposta->temDados() ? $total.' '.($total === 1 ? 'cliente' : 'clientes').' no monitor' : null">
    <form method="GET" class="flex flex-wrap items-center gap-2">
        <input type="search" name="busca" value="{{ $busca }}" placeholder="Buscar cliente…"
               class="h-[34px] w-[240px] py-0 text-[13px] rounded-control bg-input border-line text-ink placeholder:text-ink-faint">
        <button type="submit"
                class="h-[34px] px-3 rounded-control border border-btn-line text-ink-dim
                       text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
            Buscar
        </button>
    </form>

    <x-tabela min="900px" class="tabela-zebrada">
        <thead>
            <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                <th class="px-4 py-2.5 font-semibold">Cliente</th>
                <th class="px-4 py-2.5 font-semibold">Situação</th>
                <th class="px-4 py-2.5 font-semibold text-right">Aparelhos online</th>
                <th class="px-4 py-2.5 font-semibold text-right">Acessos hoje</th>
                <th class="px-4 py-2.5 font-semibold text-right">Negados hoje</th>
                <th class="px-4 py-2.5 font-semibold text-right">Servidores</th>
                <th class="px-4 py-2.5 font-semibold text-right">Monitores</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($clientes as $cliente)
                @php
                    $id = F::idDoCliente($cliente);
                @endphp
                <tr class="border-b border-rule hover:bg-chip transition align-middle">
                    <td class="px-4 py-3">
                        @if ($id)
                            <a href="{{ route('monitor.cliente', $id) }}" class="text-[13px] font-medium text-ink hover:text-brand-text transition">{{ $cliente['nome'] ?? '—' }}</a>
                        @else
                            <span class="text-[13px] font-medium text-ink">{{ $cliente['nome'] ?? '—' }}</span>
                        @endif
                        <p class="text-[11px] text-ink-faint">{{ implode(' · ', $cliente['fontes'] ?? []) }}{{ ($cliente['horario'] ?? null) ? ' · '.$cliente['horario'] : '' }}</p>
                    </td>
                    <td class="px-4 py-3">
                        <x-badge :tom="F::tom($cliente['sev'] ?? null)" ponto>{{ F::rotuloDaGravidade($cliente['sev'] ?? null) }}</x-badge>
                    </td>
                    <td class="px-4 py-3 text-right font-sans tabular text-[12.5px] text-ink-dim">{{ $cliente['dispositivos_online'] ?? 0 }} / {{ $cliente['dispositivos_total'] ?? 0 }}</td>
                    <td class="px-4 py-3 text-right font-sans tabular text-[12.5px] text-ink-dim">{{ F::inteiro($cliente['acessos_hoje'] ?? null) }}</td>
                    <td class="px-4 py-3 text-right font-sans tabular text-[12.5px] text-ink-dim">{{ F::inteiro($cliente['negados_hoje'] ?? null) }}</td>
                    <td class="px-4 py-3 text-right font-sans tabular text-[12.5px] text-ink-dim">{{ $cliente['servidores'] ?? 0 }}</td>
                    <td class="px-4 py-3 text-right font-sans tabular text-[12.5px] text-ink-dim">{{ $cliente['monitores'] ?? 0 }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-4 py-6 text-center text-[13px] text-ink-mute">
                        {{ $busca !== '' ? 'Nenhum cliente com «'.$busca.'».' : 'O monitor não acompanha nenhum cliente.' }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-tabela>
</x-monitor.tela>
