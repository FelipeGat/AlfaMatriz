<x-app-layout>
    <x-slot name="caminho">
        <x-migalhas :caminho="[['rotulo' => 'Receitas', 'rota' => route('cobrancas.index')]]"
                    :atual="$cobranca->descricao" />
    </x-slot>
    <x-slot name="contexto">RECEITA · {{ ucfirst($cobranca->status) }}</x-slot>

    <div class="max-w-4xl space-y-4">
        <x-painel titulo="Dados da receita">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div>
                    <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Revenda/Cliente</p>
                    <p class="mt-1 text-[13.5px] text-ink">{{ $cobranca->revenda->nome ?? $cobranca->cliente->nome ?? '—' }}</p>
                </div>
                <div>
                    <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Competência</p>
                    <p class="mt-1 text-[13.5px] text-ink">{{ $cobranca->competencia ?? '—' }}</p>
                </div>
                <div>
                    <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Vencimento</p>
                    <p class="mt-1 font-mono text-[13px] text-ink">{{ $cobranca->data_vencimento->format('d/m/Y') }}</p>
                </div>
                <div>
                    <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Status</p>
                    <x-badge class="mt-1" :tom="['pago' => 'bom', 'cancelado' => 'neutro'][$cobranca->status] ?? 'atencao'" ponto>
                        {{ ucfirst($cobranca->status) }}
                    </x-badge>
                </div>
            </div>
            <div class="mt-4 pt-4 border-t border-rule">
                <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Valor total</p>
                <p class="mt-1 font-display text-[24px] font-semibold text-ink tabular">R$ {{ number_format($cobranca->valor, 2, ',', '.') }}</p>
            </div>
        </x-painel>

        @if ($cobranca->detalhamento)
            <x-tabela titulo="Detalhamento por sistema" min="640px">
                <thead>
                    <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                        <th class="px-4 py-[13px] text-left">Sistema</th>
                        <th class="px-4 py-[13px] text-left">Tier</th>
                        <th class="px-4 py-[13px] text-right">Clientes ativos</th>
                        <th class="px-4 py-[13px] text-right">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($cobranca->detalhamento as $linha)
                        <tr class="border-b border-rule">
                            <td class="px-4 py-[13px] text-[13.5px] text-ink">{{ $linha['sistema'] ?? '—' }}</td>
                            <td class="px-4 py-[13px] text-[13.5px] text-ink-dim">{{ $linha['tier'] ?? '—' }}</td>
                            <td class="px-4 py-[13px] text-right text-[13.5px] text-ink-dim">
                                <details class="inline">
                                    <summary class="cursor-pointer">{{ $linha['clientes_ativos'] ?? '—' }}</summary>
                                    <div class="text-[11.5px] text-ink-mute mt-1 text-left">{{ collect($linha['clientes'] ?? [])->join(', ') }}</div>
                                </details>
                            </td>
                            <td class="px-4 py-[13px] text-right font-mono text-[13px] text-ink tabular whitespace-nowrap">R$ {{ number_format($linha['valor'] ?? 0, 2, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-tabela>
        @endif

        <div class="flex items-center gap-4">
            <a href="{{ route('cobrancas.index') }}" class="text-sm text-ink-dim hover:text-ink">&larr; Voltar</a>
            @if ($cobranca->status === 'pendente')
                <x-confirmar :action="route('cobrancas.baixar', $cobranca)" confirmar="Dar baixa"
                             titulo="Confirmar o recebimento?"
                             :mensagem="$cobranca->descricao.' — R$ '.number_format($cobranca->valor, 2, ',', '.').'. A entrada vai para o caixa e o título sai de em aberto.'"
                             class="text-good hover:opacity-80 text-sm">
                    Dar baixa
                </x-confirmar>
            @endif
            <a href="{{ route('cobrancas.edit', $cobranca) }}" class="text-brand hover:text-brand-bright text-sm">Editar</a>
        </div>
    </div>
</x-app-layout>
