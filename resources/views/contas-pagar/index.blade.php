<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-ink leading-tight">Despesas</h2>
            <button x-data @click="$dispatch('open-modal', 'nova-despesa')" class="inline-flex items-center px-4 py-2 bg-brand border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-bright">
                + Nova despesa
            </button>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="p-4 bg-status-good/10 text-status-good rounded-md text-sm">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="p-4 bg-status-critical/10 text-status-critical rounded-md text-sm">{{ $errors->first() }}</div>
            @endif

            {{-- KPIs --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-4">
                    <p class="text-[10px] text-ink-mute uppercase">A pagar</p>
                    <p class="text-xl font-display font-semibold text-status-warning">R$ {{ number_format($kpis['a_pagar'], 2, ',', '.') }}</p>
                </div>
                <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-4">
                    <p class="text-[10px] text-ink-mute uppercase">Pago (este mês)</p>
                    <p class="text-xl font-display font-semibold text-status-good">R$ {{ number_format($kpis['pago_mes'], 2, ',', '.') }}</p>
                </div>
                <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-4">
                    <p class="text-[10px] text-ink-mute uppercase">Atrasado</p>
                    <p class="text-xl font-display font-semibold text-status-critical">R$ {{ number_format($kpis['atrasado'], 2, ',', '.') }}</p>
                </div>
            </div>

            <form method="GET" class="flex gap-3">
                <select name="status" class="border-white/20 rounded-md shadow-sm text-sm" onchange="this.form.submit()">
                    <option value="">Todos os status</option>
                    <option value="em_aberto" {{ request('status') === 'em_aberto' ? 'selected' : '' }}>Em aberto</option>
                    <option value="pago" {{ request('status') === 'pago' ? 'selected' : '' }}>Pago</option>
                    <option value="cancelado" {{ request('status') === 'cancelado' ? 'selected' : '' }}>Cancelado</option>
                </select>
                <select name="tipo" class="border-white/20 rounded-md shadow-sm text-sm" onchange="this.form.submit()">
                    <option value="">Todos os tipos</option>
                    <option value="avulsa" {{ request('tipo') === 'avulsa' ? 'selected' : '' }}>Pontuais</option>
                    <option value="fixa" {{ request('tipo') === 'fixa' ? 'selected' : '' }}>Recorrentes</option>
                </select>
            </form>

            <div x-data="{ selecionados: [] }">
                <form id="bulk-baixa-despesas" action="{{ route('contas-pagar.baixarEmMassa') }}" method="POST" class="hidden">
                    @csrf
                    <template x-for="id in selecionados" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                </form>

                <div x-show="selecionados.length > 0" x-cloak class="mb-3 flex items-center gap-3 bg-brand/10 ring-1 ring-brand/25 rounded-lg px-4 py-2.5">
                    <span class="text-sm text-brand-dim"><span x-text="selecionados.length"></span> selecionada(s)</span>
                    <button type="submit" form="bulk-baixa-despesas" onclick="return confirm('Dar baixa nas despesas selecionadas?');" class="ml-auto text-xs font-semibold bg-brand text-white rounded-md px-3 py-1.5 hover:bg-brand-bright">
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
                                <th class="px-4 py-3 text-left text-xs font-medium text-ink-dim uppercase">Centro de custo</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-ink-dim uppercase">Vencimento</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-ink-dim uppercase">Valor</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-ink-dim uppercase">Status</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="bg-panel divide-y divide-white/5">
                            @forelse ($contasPagar as $contaPagar)
                                @php
                                    $vencida = $contaPagar->status === 'em_aberto' && $contaPagar->data_vencimento->isPast();
                                    $venceEmBreve = $contaPagar->status === 'em_aberto' && ! $vencida && $contaPagar->data_vencimento->diffInDays(now()) <= 3;
                                    $corLinha = $vencida ? 'bg-status-critical/10' : ($venceEmBreve ? 'bg-status-warning/10' : '');
                                @endphp
                                <tr class="{{ $corLinha }}">
                                    <td class="pl-4 pr-2 py-4">
                                        @if ($contaPagar->status === 'em_aberto')
                                            <input type="checkbox" @change="$event.target.checked ? selecionados.push({{ $contaPagar->id }}) : selecionados = selecionados.filter(i => i !== {{ $contaPagar->id }})" class="rounded border-white/20 bg-panel-raised text-brand focus:ring-brand">
                                        @endif
                                    </td>
                                    <td class="px-4 py-4 text-sm text-ink">
                                        {{ $contaPagar->descricao }}
                                        <span class="ml-1 px-1.5 py-0.5 text-[10px] rounded-full {{ $contaPagar->tipo === 'fixa' ? 'bg-brand/10 text-brand-dim' : 'bg-white/5 text-ink-mute' }}">
                                            {{ $contaPagar->tipo === 'fixa' ? 'Recorrente' : 'Pontual' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-sm text-ink-dim">{{ $contaPagar->centroCusto->nome ?? '—' }}</td>
                                    <td class="px-4 py-4 text-sm text-ink-dim">{{ $contaPagar->data_vencimento->format('d/m/Y') }}</td>
                                    <td class="px-4 py-4 text-sm text-right font-medium">R$ {{ number_format($contaPagar->valor, 2, ',', '.') }}</td>
                                    <td class="px-4 py-4 text-sm">
                                        <span class="px-2 py-1 text-xs rounded-full {{ ['pago' => 'bg-status-good/15 text-status-good', 'cancelado' => 'bg-panel-raised text-ink'][$contaPagar->status] ?? 'bg-status-warning/15 text-status-warning' }}">
                                            {{ ucfirst(str_replace('_', ' ', $contaPagar->status)) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-right whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-1">
                                            @if ($contaPagar->status === 'em_aberto')
                                                <form action="{{ route('contas-pagar.baixar', $contaPagar) }}" method="POST" onsubmit="return confirm('Confirmar pagamento?');">
                                                    @csrf
                                                    <button type="submit" title="Dar baixa" class="p-1.5 rounded-md text-status-good/70 hover:text-status-good hover:bg-status-good/10 transition">
                                                        <span class="block h-4 w-4"><x-nav-icon name="check-circle" /></span>
                                                    </button>
                                                </form>
                                            @endif
                                            @if ($contaPagar->contaFixaPagar)
                                                <form action="{{ route('contas-fixas-pagar.pausar', $contaPagar->contaFixaPagar) }}" method="POST" onsubmit="return confirm('{{ $contaPagar->contaFixaPagar->ativo ? 'Pausar esta despesa recorrente? Ela para de gerar novas parcelas.' : 'Reativar esta despesa recorrente?' }}');">
                                                    @csrf
                                                    <button type="submit" title="{{ $contaPagar->contaFixaPagar->ativo ? 'Pausar recorrência' : 'Ativar recorrência' }}" class="p-1.5 rounded-md text-ink-mute/70 hover:text-ink hover:bg-white/5 transition">
                                                        <span class="block h-4 w-4"><x-nav-icon :name="$contaPagar->contaFixaPagar->ativo ? 'pause' : 'play'" /></span>
                                                    </button>
                                                </form>
                                            @endif
                                            <a href="{{ route('contas-pagar.edit', $contaPagar) }}" title="Editar" class="p-1.5 rounded-md text-brand-dim/70 hover:text-brand-dim hover:bg-brand/10 transition">
                                                <span class="block h-4 w-4"><x-nav-icon name="pencil" /></span>
                                            </a>
                                            <form action="{{ route('contas-pagar.destroy', $contaPagar) }}" method="POST" onsubmit="return confirm('Remover esta despesa?');">
                                                @csrf @method('DELETE')
                                                <button type="submit" title="Remover" class="p-1.5 rounded-md text-status-critical/70 hover:text-status-critical hover:bg-status-critical/10 transition">
                                                    <span class="block h-4 w-4"><x-nav-icon name="trash" /></span>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-6 py-8 text-center text-sm text-ink-dim">Nenhuma despesa cadastrada.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    <div class="p-4">{{ $contasPagar->links() }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Modal: nova despesa (avulsa ou recorrente) --}}
    <x-modal name="nova-despesa" maxWidth="xl">
        <div x-data="{ recorrente: false }" class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-display font-semibold text-ink text-lg">Nova despesa</h3>
                <label class="flex items-center gap-2 text-sm text-ink-dim cursor-pointer select-none">
                    É recorrente?
                    <button type="button" role="switch" :aria-checked="recorrente" @click="recorrente = !recorrente"
                            class="relative inline-flex h-5 w-9 items-center rounded-full transition"
                            :class="recorrente ? 'bg-brand' : 'bg-white/10'">
                        <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white transition" :class="recorrente ? 'translate-x-5' : 'translate-x-1'"></span>
                    </button>
                </label>
            </div>

            {{-- Pontual --}}
            <form x-show="! recorrente" x-cloak action="{{ route('contas-pagar.store') }}" method="POST" class="space-y-4">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="sm:col-span-2">
                        <x-input-label value="Descrição" />
                        <x-text-input name="descricao" type="text" class="mt-1 block w-full" required />
                    </div>
                    <div>
                        <x-input-label value="Valor (R$)" />
                        <x-text-input name="valor" type="number" step="0.01" min="0" class="mt-1 block w-full" required />
                    </div>
                    <div>
                        <x-input-label value="Vencimento" />
                        <x-text-input name="data_vencimento" type="date" class="mt-1 block w-full" value="{{ now()->toDateString() }}" required />
                    </div>
                    <div>
                        <x-input-label value="Centro de custo" />
                        <select name="centro_custo_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                            <option value="">—</option>
                            @foreach ($centrosCusto as $centro)
                                <option value="{{ $centro->id }}">{{ $centro->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label value="Categoria (conta)" />
                        <select name="conta_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                            <option value="">—</option>
                            @foreach ($contas as $conta)
                                <option value="{{ $conta->id }}">{{ $conta->subcategoria->categoria->nome }} › {{ $conta->subcategoria->nome }} › {{ $conta->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label value="Fornecedor" />
                        <select name="fornecedor_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                            <option value="">—</option>
                            @foreach ($fornecedores as $fornecedor)
                                <option value="{{ $fornecedor->id }}">{{ $fornecedor->razao_social }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label value="Conta de pagamento" />
                        <select name="conta_financeira_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                            <option value="">—</option>
                            @foreach ($contasFinanceiras as $conta)
                                <option value="{{ $conta->id }}">{{ $conta->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label value="Forma de pagamento" />
                        <x-text-input name="forma_pagamento" type="text" class="mt-1 block w-full" />
                    </div>
                </div>
                <div class="flex justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close')">Cancelar</x-secondary-button>
                    <x-primary-button>Salvar</x-primary-button>
                </div>
            </form>

            {{-- Recorrente --}}
            <form x-show="recorrente" x-cloak action="{{ route('contas-fixas-pagar.store') }}" method="POST" class="space-y-4">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="sm:col-span-2">
                        <x-input-label value="Descrição" />
                        <x-text-input name="descricao" type="text" class="mt-1 block w-full" required />
                    </div>
                    <div>
                        <x-input-label value="Valor (R$)" />
                        <x-text-input name="valor" type="number" step="0.01" min="0" class="mt-1 block w-full" required />
                    </div>
                    <div>
                        <x-input-label value="Dia do vencimento" />
                        <x-text-input name="dia_vencimento" type="number" min="1" max="31" class="mt-1 block w-full" required />
                    </div>
                    <div>
                        <x-input-label value="Centro de custo" />
                        <select name="centro_custo_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                            <option value="">—</option>
                            @foreach ($centrosCusto as $centro)
                                <option value="{{ $centro->id }}">{{ $centro->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label value="Categoria (conta)" />
                        <select name="conta_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                            <option value="">—</option>
                            @foreach ($contas as $conta)
                                <option value="{{ $conta->id }}">{{ $conta->subcategoria->categoria->nome }} › {{ $conta->subcategoria->nome }} › {{ $conta->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label value="Fornecedor" />
                        <select name="fornecedor_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                            <option value="">—</option>
                            @foreach ($fornecedores as $fornecedor)
                                <option value="{{ $fornecedor->id }}">{{ $fornecedor->razao_social }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label value="Conta de pagamento" />
                        <select name="conta_financeira_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                            <option value="">—</option>
                            @foreach ($contasFinanceiras as $conta)
                                <option value="{{ $conta->id }}">{{ $conta->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label value="Início da vigência" />
                        <x-text-input name="data_inicio" type="date" class="mt-1 block w-full" value="{{ now()->toDateString() }}" required />
                    </div>
                    <div>
                        <x-input-label value="Fim da vigência (opcional)" />
                        <x-text-input name="data_fim" type="date" class="mt-1 block w-full" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label value="Forma de pagamento" />
                        <x-text-input name="forma_pagamento" type="text" class="mt-1 block w-full" />
                    </div>
                </div>
                <div class="flex justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close')">Cancelar</x-secondary-button>
                    <x-primary-button>Salvar</x-primary-button>
                </div>
            </form>
        </div>
    </x-modal>
</x-app-layout>
