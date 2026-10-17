{{--
    Monitor › Acesso (#394): as catracas e leitores do AlfaControl e do
    AlfaGym, por cliente. O sino aparece só como informação — ligar e
    desligar é no monitor.
    Espera: $resposta (acesso?fonte=), $filtros, $fonte, $clientes.
--}}
@use('App\Services\Monitor\FormatoDoMonitor', 'F')
@use('App\Http\Controllers\PainelDoMonitorController', 'Painel')
@php
    $totais = $fonte['totais'] ?? [];
    $janelas = ['hoje' => 'Hoje', 'd7' => '7 dias', 'd30' => '30 dias'];
@endphp

<x-monitor.tela tela="acesso" :resposta="$resposta"
                :contexto="$fonte ? ($fonte['online'] ?? 0).' de '.($fonte['total'] ?? 0).' aparelhos online no '.$filtros['fonte'] : null">
    <div class="flex flex-wrap items-center gap-2">
        <x-abas>
            @foreach (Painel::FONTES as $nome)
                <x-abas.item :href="route('monitor.acesso', ['fonte' => $nome, 'situacao' => $filtros['situacao'], 'busca' => $filtros['busca'] ?: null])"
                             :ativo="$filtros['fonte'] === $nome">{{ $nome }}</x-abas.item>
            @endforeach
        </x-abas>

        <form method="GET" class="flex flex-wrap items-center gap-2">
            <input type="hidden" name="fonte" value="{{ $filtros['fonte'] }}">
            <input type="search" name="busca" value="{{ $filtros['busca'] }}" placeholder="Buscar cliente, aparelho ou IP…"
                   class="h-[34px] w-[240px] py-0 text-[13px] rounded-control bg-input border-line text-ink placeholder:text-ink-faint">
            <select name="situacao" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="todos" @selected($filtros['situacao'] === 'todos')>Todos</option>
                <option value="online" @selected($filtros['situacao'] === 'online')>Online</option>
                <option value="offline" @selected($filtros['situacao'] === 'offline')>Offline</option>
            </select>
            <button type="submit"
                    class="h-[34px] px-3 rounded-control border border-btn-line text-ink-dim
                           text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
                Filtrar
            </button>
        </form>

        @if ($fonte && ($fonte['coleta'] ?? null) !== 'ok')
            <span class="ml-auto font-mono text-[11px] font-semibold text-crit">a coleta do {{ $filtros['fonte'] }} está com problema</span>
        @elseif ($fonte && F::hora($fonte['ultima_coleta'] ?? null))
            <span class="ml-auto font-mono text-[11px] text-ink-faint">última coleta {{ F::hora($fonte['ultima_coleta'])->format('d/m H:i') }}</span>
        @endif
    </div>

    <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr))">
        @foreach ($janelas as $chave => $rotulo)
            <x-kpi-card :rotulo="'Liberados · '.$rotulo" :valor="F::inteiro($totais[$chave]['liberados'] ?? null)"
                        :delta="F::inteiro($totais[$chave]['negados'] ?? null).' negados'" acento="good" icone="check-circle" />
        @endforeach
    </div>

    @forelse ($clientes as $cliente)
        @php
            $idDoCliente = isset($cliente['cliente_id']) ? $filtros['fonte'].':'.$cliente['cliente_id'] : null;
        @endphp
        <x-tabela min="980px" class="tabela-zebrada" :titulo="$cliente['nome'] ?? '—'"
                  :sub="($cliente['online'] ?? 0).' de '.($cliente['total'] ?? 0).' online · '.F::inteiro($cliente['acessos_hoje'] ?? null).' acessos hoje · '.F::inteiro($cliente['negados_hoje'] ?? null).' negados'">
            <x-slot name="cabecalho">
                @if ($cliente['horario'] ?? null)
                    <span class="hidden md:inline text-[11.5px] text-ink-faint">{{ $cliente['horario'] }}</span>
                @endif
                @if ($cliente['fechado_agora'] ?? false)
                    <x-badge>fechado agora</x-badge>
                @endif
                @if ($idDoCliente)
                    <a href="{{ route('monitor.cliente', $idDoCliente) }}" class="text-[12px] font-semibold text-brand-text hover:underline whitespace-nowrap">Ver cliente ›</a>
                @endif
            </x-slot>

            <thead>
                {{-- Larguras fixas: uma tabela por cliente, e sem elas cada uma
                     acharia as próprias colunas — a barra de histórico pularia de
                     lugar de um cliente para o outro. --}}
                <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                    <th class="px-4 py-2.5 font-semibold w-[20%]">Aparelho</th>
                    <th class="px-4 py-2.5 font-semibold w-[14%]">Modelo</th>
                    <th class="px-4 py-2.5 font-semibold w-[11%]">IP</th>
                    <th class="px-4 py-2.5 font-semibold w-[15%]">Agora</th>
                    <th class="px-4 py-2.5 font-semibold w-[24%]">Histórico</th>
                    <th class="px-4 py-2.5 font-semibold text-right w-[8%]">No ar 24h</th>
                    <th class="px-4 py-2.5 font-semibold w-[8%]">Sino</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($cliente['aparelhos'] as $aparelho)
                    @php $ping = F::hora($aparelho['ultimo_ping'] ?? null); @endphp
                    <tr class="border-b border-rule hover:bg-chip transition align-middle">
                        <td class="px-4 py-3 text-[13px] font-medium text-ink">{{ $aparelho['nome'] ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-[11.5px] text-ink-dim">{{ $aparelho['modelo'] ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-[11.5px] text-ink-dim">{{ $aparelho['ip'] ?? '—' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <x-badge :tom="F::tom($aparelho['estado'] ?? null)" ponto>{{ ($aparelho['estado'] ?? '') === 'online' ? 'Online' : 'Offline' }}</x-badge>
                            @if ($ping)
                                <p class="mt-1 text-[11px] text-ink-faint" title="{{ $ping->format('d/m/Y H:i:s') }}">
                                    ping {{ $ping->diffForHumans() }}{{ is_numeric($aparelho['ping_ms'] ?? null) ? ' · '.F::ms($aparelho['ping_ms']) : '' }}
                                </p>
                            @endif
                        </td>
                        <td class="px-4 py-3"><x-monitor.barra :barra="$aparelho['barra'] ?? null" /></td>
                        <td class="px-4 py-3 text-right font-sans tabular text-[12.5px] text-ink-dim">{{ F::noAr($aparelho['pct_no_ar_24h'] ?? null) }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-1 text-[12px] {{ ($aparelho['sino'] ?? false) ? 'text-ink-dim' : 'text-ink-faint' }}"
                                  title="{{ ($aparelho['sino'] ?? false) ? 'O monitor avisa quando este aparelho cai' : 'O monitor não avisa quando este aparelho cai' }}">
                                <span class="h-[14px] w-[14px]"><x-nav-icon :name="($aparelho['sino'] ?? false) ? 'bell' : 'speaker-x-mark'" /></span>
                                {{ ($aparelho['sino'] ?? false) ? 'ligado' : 'desligado' }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-tabela>
    @empty
        <div class="rounded-panel border border-line bg-surface px-5 py-8 text-center text-[13px] text-ink-mute">
            Nenhum aparelho {{ $filtros['situacao'] === 'todos' ? '' : $filtros['situacao'].' ' }}{{ $filtros['busca'] !== '' ? 'com «'.$filtros['busca'].'» ' : '' }}no {{ $filtros['fonte'] }}.
        </div>
    @endforelse
</x-monitor.tela>
