{{--
    As assinaturas e os domínios da Hostinger (#394), só para quem tem
    `monitor_hostinger`. O controller nem consulta a rota sem a permissão:
    o dado não chega ao HTML de quem não a tem.

    O que vence primeiro vem primeiro, e o prazo é a cor: até 7 dias
    crítico, até 30 atenção. Sem renovação automática vira selo próprio —
    é ele que transforma "vence em 1 dia" em "sai do ar amanhã".

    Espera: $hostinger (RespostaDoMonitor da rota /hostinger).
--}}
@use('App\Services\Monitor\FormatoDoMonitor', 'F')
@php
    $itens = collect($hostinger->get('assinaturas', []))->map(fn ($a) => $a + ['tipo' => 'VPS / plano'])
        ->concat(collect($hostinger->get('dominios', []))->map(fn ($d) => $d + ['tipo' => 'Domínio']))
        ->sortBy(fn ($i) => is_numeric($i['dias'] ?? null) ? (int) $i['dias'] : PHP_INT_MAX)
        ->values();

    $erro = $hostinger->get('erro_integracao') ?: $hostinger->get('erro_vencimentos');
@endphp

<x-tabela min="760px" class="tabela-zebrada" titulo="Hostinger"
          :sub="$itens->count().' '.($itens->count() === 1 ? 'assinatura' : 'assinaturas e domínios')">
    @if ($hostinger->velho || ! $hostinger->temDados() || $erro)
        <x-slot name="cabecalho">
            <span class="font-mono text-[11px] text-warn">{{ $erro ?: ($hostinger->erro ?? 'sem dados') }}</span>
        </x-slot>
    @endif

    <thead>
        <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
            <th class="px-4 py-2.5 font-semibold">Nome</th>
            <th class="px-4 py-2.5 font-semibold">Tipo</th>
            <th class="px-4 py-2.5 font-semibold">Plano</th>
            <th class="px-4 py-2.5 font-semibold">Vence</th>
            <th class="px-4 py-2.5 font-semibold">Renovação automática</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($itens as $item)
            @php
                $dias = is_numeric($item['dias'] ?? null) ? (int) $item['dias'] : null;
                $vence = F::hora($item['vence_em'] ?? null);
                $tom = match (true) {
                    $dias === null => 'neutro',
                    $dias <= 7 => 'critico',
                    $dias <= 30 => 'atencao',
                    default => 'bom',
                };
            @endphp
            <tr class="border-b border-rule hover:bg-chip transition align-middle">
                <td class="px-4 py-3 text-[13px] font-medium text-ink">{{ $item['nome'] ?? '—' }}</td>
                <td class="px-4 py-3 text-[12.5px] text-ink-dim">{{ $item['tipo'] }}</td>
                <td class="px-4 py-3 text-[12.5px] text-ink-dim">{{ $item['plano'] ?? '—' }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <x-badge :tom="$tom">{{ $dias === null ? '—' : ($dias < 0 ? 'venceu há '.abs($dias).' d' : ($dias === 0 ? 'vence hoje' : 'em '.$dias.' '.($dias === 1 ? 'dia' : 'dias'))) }}</x-badge>
                    <span class="ml-1 font-sans tabular text-[12px] text-ink-faint">{{ $vence?->format('d/m/Y H:i') }}</span>
                </td>
                <td class="px-4 py-3">
                    @if ($item['renovacao_automatica'] ?? false)
                        <x-badge tom="bom">Ligada</x-badge>
                    @else
                        <x-badge :tom="$dias !== null && $dias <= 30 ? 'critico' : 'neutro'">Desligada</x-badge>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="5" class="px-4 py-6 text-center text-[13px] text-ink-mute">
                    {{ $hostinger->get('configurada') === false ? 'A integração com a Hostinger não está configurada no monitor.' : 'Nenhuma assinatura.' }}
                </td>
            </tr>
        @endforelse
    </tbody>
</x-tabela>
