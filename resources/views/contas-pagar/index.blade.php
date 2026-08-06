<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2 text-[14px]">
            <span class="text-mute">Financeiro</span>
            <span class="text-line">/</span>
            <span class="font-medium text-ink">Despesas</span>
        </div>
    </x-slot>

    <div class="space-y-[18px]">
        @if (session('status'))
            <div class="rounded-control border border-line bg-raised px-4 py-2.5 text-[12.5px] text-ink">{{ session('status') }}</div>
        @endif

        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
            <x-summary-card label="Em aberto" :value="'R$ ' . number_format($emAberto, 2, ',', '.')" contexto="aguardando pagamento" />
            <x-summary-card label="Vencidas" :value="'R$ ' . number_format($vencidas, 2, ',', '.')" tom="bad" contexto="passaram do vencimento" />
            <x-summary-card label="Despesas fixas" :value="'R$ ' . number_format($fixas, 2, ',', '.')" contexto="recorrentes do mês" />
            <x-summary-card label="Total do mês" :value="'R$ ' . number_format($totalMes, 2, ',', '.')" tom="warn" contexto="competência {{ now()->format('m/Y') }}" />
        </div>

        <form method="GET" class="flex flex-wrap items-center gap-2">
            <div class="inline-flex rounded-control border border-line bg-raised p-0.5">
                @foreach (['' => 'Todas', 'em_aberto' => 'Em aberto', 'pago' => 'Pagas'] as $valor => $rotulo)
                    @php $marcado = (string) request('status') === $valor; @endphp
                    <a href="{{ request()->fullUrlWithQuery(['status' => $valor ?: null, 'page' => null]) }}"
                       class="rounded px-2.5 py-1 font-mono text-[11.5px] transition-colors {{ $marcado ? 'border border-line bg-panel text-ink' : 'text-dim hover:text-ink' }}">
                        {{ $rotulo }}
                    </a>
                @endforeach
            </div>

            <a href="{{ route('contas-pagar.create') }}"
               class="ml-auto inline-flex h-8 items-center rounded-control bg-ink px-3 text-[12.5px] font-medium text-bg transition-opacity hover:opacity-90">
                Nova despesa
            </a>
        </form>

        <x-painel-card :sem-padding="true">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[880px] border-collapse">
                    <thead>
                        <tr class="bg-raised">
                            @foreach (['Descrição' => '', 'Centro de custo' => 'w-[170px]', 'Vencimento' => 'w-[112px]', 'Valor' => 'w-[124px] text-right', 'Situação' => 'w-[104px]', '' => 'w-[180px]'] as $titulo => $largura)
                                <th class="{{ $largura }} truncate whitespace-nowrap border-b border-line px-5 py-2.5 text-left font-mono text-[10px] font-medium uppercase tracking-[.08em] text-mute">
                                    {{ $titulo }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($contasPagar as $contaPagar)
                            @php
                                $vencida = $contaPagar->status === 'em_aberto' && $contaPagar->data_vencimento->isPast();
                                $situacao = match ($contaPagar->status) {
                                    'pago' => ['tom' => 'good', 'texto' => 'Baixada'],
                                    'cancelado' => ['tom' => 'neutro', 'texto' => 'Cancelada'],
                                    default => $vencida ? ['tom' => 'bad', 'texto' => 'Vencida'] : ['tom' => 'neutro', 'texto' => 'Aberta'],
                                };
                            @endphp
                            <tr class="border-b border-line transition-colors last:border-0 hover:bg-raised">
                                <td class="px-5 py-3 text-[14px] text-ink">{{ $contaPagar->descricao }}</td>
                                <td class="px-5 py-3 text-[12.5px] text-dim">{{ $contaPagar->centroCusto->nome ?? '—' }}</td>
                                <td class="valor px-5 py-3 text-[12.5px] {{ $vencida ? 'text-bad' : 'text-dim' }}">{{ $contaPagar->data_vencimento->format('d/m/Y') }}</td>
                                <td class="valor px-5 py-3 text-right text-[12.5px] font-medium text-ink">R$ {{ number_format($contaPagar->valor, 2, ',', '.') }}</td>
                                <td class="px-5 py-3">
                                    <x-status-pill :tom="$situacao['tom']">{{ $situacao['texto'] }}</x-status-pill>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-right text-[12.5px]">
                                    @if ($contaPagar->status === 'em_aberto')
                                        <form action="{{ route('contas-pagar.baixar', $contaPagar) }}" method="POST" class="inline" onsubmit="return confirm('Confirmar pagamento?');">
                                            @csrf
                                            <button type="submit" class="text-dim transition-colors hover:text-good">Baixar</button>
                                        </form>
                                    @endif
                                    <a href="{{ route('contas-pagar.edit', $contaPagar) }}" class="ml-3 text-dim transition-colors hover:text-ink">Editar</a>
                                    <form action="{{ route('contas-pagar.destroy', $contaPagar) }}" method="POST" class="ml-3 inline" onsubmit="return confirm('Remover esta despesa?');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-dim transition-colors hover:text-bad">Remover</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-[34px] text-center text-[14px] text-mute">Nenhuma despesa encontrada.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($contasPagar->hasPages())
                <div class="border-t border-line px-5 py-3">{{ $contasPagar->links() }}</div>
            @endif
        </x-painel-card>
    </div>
</x-app-layout>
