<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-ink leading-tight">Receitas / Contas a receber</h2>
            <a href="{{ route('cobrancas.create') }}" class="inline-flex items-center px-4 py-2 bg-brand border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-bright">
                + Nova receita
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 p-4 bg-status-good/10 text-status-good rounded-md text-sm">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="mb-4 p-4 bg-status-critical/10 text-status-critical rounded-md text-sm">{{ $errors->first() }}</div>
            @endif

            {{-- KPIs --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-4">
                    <p class="text-[10px] text-ink-mute uppercase">A receber</p>
                    <p class="text-xl font-display font-semibold text-status-warning">R$ {{ number_format($kpis['a_receber'], 2, ',', '.') }}</p>
                </div>
                <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-4">
                    <p class="text-[10px] text-ink-mute uppercase">Recebido (este mês)</p>
                    <p class="text-xl font-display font-semibold text-status-good">R$ {{ number_format($kpis['recebido_mes'], 2, ',', '.') }}</p>
                </div>
                <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-4">
                    <p class="text-[10px] text-ink-mute uppercase">Atrasado</p>
                    <p class="text-xl font-display font-semibold text-status-critical">R$ {{ number_format($kpis['atrasado'], 2, ',', '.') }}</p>
                </div>
            </div>

            <form method="GET" class="mb-4 flex gap-3">
                <select name="status" class="border-white/20 rounded-md shadow-sm text-sm" onchange="this.form.submit()">
                    <option value="">Todos os status</option>
                    <option value="pendente" {{ request('status') === 'pendente' ? 'selected' : '' }}>Pendente</option>
                    <option value="pago" {{ request('status') === 'pago' ? 'selected' : '' }}>Pago</option>
                    <option value="cancelado" {{ request('status') === 'cancelado' ? 'selected' : '' }}>Cancelado</option>
                </select>
            </form>

            <div x-data="{ selecionados: [] }">
                <form id="bulk-baixa-receitas" action="{{ route('cobrancas.baixarEmMassa') }}" method="POST" class="hidden">
                    @csrf
                    <template x-for="id in selecionados" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                </form>

                <div x-show="selecionados.length > 0" x-cloak class="mb-3 flex items-center gap-3 bg-brand/10 ring-1 ring-brand/25 rounded-lg px-4 py-2.5">
                    <span class="text-sm text-brand-dim"><span x-text="selecionados.length"></span> selecionada(s)</span>
                    <button type="submit" form="bulk-baixa-receitas" onclick="return confirm('Dar baixa nas receitas selecionadas?');" class="ml-auto text-xs font-semibold bg-brand text-white rounded-md px-3 py-1.5 hover:bg-brand-bright">
                        Dar baixa em massa
                    </button>
                    <button type="button" @click="selecionados = []" class="text-xs text-ink-mute hover:text-ink">Limpar seleção</button>
                </div>

                <div class="bg-panel overflow-hidden shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-white/5">
                        <thead class="bg-panel-raised">
                            <tr>
                                <th class="pl-4 pr-2 py-3 w-8"></th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-ink-dim uppercase">Descrição</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-ink-dim uppercase">Revenda/Cliente</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-ink-dim uppercase">Vencimento</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-ink-dim uppercase">Valor</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-ink-dim uppercase">Status</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="bg-panel divide-y divide-white/5">
                            @forelse ($cobrancas as $cobranca)
                                @php
                                    $vencida = $cobranca->status === 'pendente' && $cobranca->data_vencimento->isPast();
                                    $venceEmBreve = $cobranca->status === 'pendente' && ! $vencida && $cobranca->data_vencimento->diffInDays(now()) <= 3;
                                    $corLinha = $vencida ? 'bg-status-critical/10' : ($venceEmBreve ? 'bg-status-warning/10' : '');
                                @endphp
                                <tr class="{{ $corLinha }}">
                                    <td class="pl-4 pr-2 py-4">
                                        @if ($cobranca->status === 'pendente')
                                            <input type="checkbox" @change="$event.target.checked ? selecionados.push({{ $cobranca->id }}) : selecionados = selecionados.filter(i => i !== {{ $cobranca->id }})" class="rounded border-white/20 bg-panel-raised text-brand focus:ring-brand">
                                        @endif
                                    </td>
                                    <td class="px-4 py-4 text-sm text-ink">{{ $cobranca->descricao }}</td>
                                    <td class="px-4 py-4 text-sm text-ink-dim">{{ $cobranca->revenda->nome ?? $cobranca->cliente->nome ?? '—' }}</td>
                                    <td class="px-4 py-4 text-sm text-ink-dim">{{ $cobranca->data_vencimento->format('d/m/Y') }}</td>
                                    <td class="px-4 py-4 text-sm text-right font-medium">R$ {{ number_format($cobranca->valor, 2, ',', '.') }}</td>
                                    <td class="px-4 py-4 text-sm">
                                        <span class="px-2 py-1 text-xs rounded-full {{ ['pago' => 'bg-status-good/15 text-status-good', 'cancelado' => 'bg-panel-raised text-ink'][$cobranca->status] ?? 'bg-status-warning/15 text-status-warning' }}">
                                            {{ ucfirst($cobranca->status) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-right text-sm space-x-3 whitespace-nowrap">
                                        @if ($cobranca->status === 'pendente')
                                            <form action="{{ route('cobrancas.baixar', $cobranca) }}" method="POST" class="inline" onsubmit="return confirm('Confirmar recebimento?');">
                                                @csrf
                                                <button type="submit" class="text-status-good hover:opacity-80">Baixar</button>
                                            </form>
                                        @endif
                                        <a href="{{ route('cobrancas.show', $cobranca) }}" class="text-brand hover:text-brand-bright">Ver</a>
                                        <a href="{{ route('cobrancas.edit', $cobranca) }}" class="text-brand hover:text-brand-bright">Editar</a>
                                        <form action="{{ route('cobrancas.destroy', $cobranca) }}" method="POST" class="inline" onsubmit="return confirm('Remover esta receita?');">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-status-critical hover:opacity-80">Remover</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-6 py-8 text-center text-sm text-ink-dim">Nenhuma receita cadastrada.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    <div class="p-4">{{ $cobrancas->links() }}</div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
