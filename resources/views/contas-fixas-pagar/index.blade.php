<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-ink leading-tight">Despesas fixas (recorrentes)</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="p-4 bg-good/12 text-good rounded-control text-sm">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="p-4 bg-bad/10 text-bad rounded-control text-sm">{{ $errors->first() }}</div>
            @endif

            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="bg-panel border border-line rounded-card px-6 py-4">
                    <p class="text-xs text-mute uppercase tracking-wide">Total mensal (ativas)</p>
                    <p class="text-2xl font-display font-semibold text-ink">R$ {{ number_format($totalMensal, 2, ',', '.') }}</p>
                </div>

                <form action="{{ route('contas-fixas-pagar.gerar') }}" method="POST" class="flex items-end gap-2">
                    @csrf
                    <div>
                        <x-input-label for="competencia" value="Gerar despesas da competência" />
                        <input type="month" id="competencia" name="competencia" value="{{ now()->format('Y-m') }}" class="mt-1 border-line rounded-control text-sm">
                    </div>
                    <x-primary-button>Gerar contas a pagar do mês</x-primary-button>
                </form>
            </div>

            <div class="bg-panel overflow-hidden sm:rounded-card">
                <table class="min-w-full divide-y divide-line">
                    <thead class="bg-raised">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-dim uppercase">Descrição</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-dim uppercase">Categoria</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-dim uppercase">Fornecedor</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-dim uppercase">Valor</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-dim uppercase">Dia venc.</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-dim uppercase">Vigência</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-dim uppercase">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($contasFixas as $fixa)
                            <tr>
                                <td class="px-4 py-3 text-sm text-ink">{{ $fixa->descricao }}</td>
                                <td class="px-4 py-3 text-sm text-dim">{{ $fixa->conta?->nome ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm text-dim">{{ $fixa->fornecedor?->razao_social ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm text-ink text-right">R$ {{ number_format($fixa->valor, 2, ',', '.') }}</td>
                                <td class="px-4 py-3 text-sm text-dim text-center">{{ $fixa->dia_vencimento }}</td>
                                <td class="px-4 py-3 text-sm text-dim">
                                    {{ $fixa->data_inicio->format('m/Y') }} até {{ $fixa->data_fim?->format('m/Y') ?? 'indeterminado' }}
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <span class="px-2 py-1 text-xs rounded-full {{ $fixa->ativo ? 'bg-good/12 text-good' : 'bg-raised text-mute' }}">
                                        {{ $fixa->ativo ? 'Ativa' : 'Inativa' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <form action="{{ route('contas-fixas-pagar.destroy', $fixa) }}" method="POST" onsubmit="return confirm('Remover esta despesa fixa?');">
                                        @csrf @method('DELETE')
                                        <button class="text-bad hover:opacity-80 text-sm">Remover</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-8 text-center text-sm text-mute">Nenhuma despesa fixa cadastrada.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="bg-panel overflow-hidden sm:rounded-card p-6">
                <h3 class="font-semibold text-ink mb-4">Nova despesa fixa</h3>
                <form action="{{ route('contas-fixas-pagar.store') }}" method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    @csrf
                    <div class="sm:col-span-2">
                        <x-input-label for="descricao" value="Descrição" />
                        <x-text-input id="descricao" name="descricao" type="text" class="mt-1 block w-full" required />
                    </div>
                    <div>
                        <x-input-label for="valor" value="Valor (R$)" />
                        <x-text-input id="valor" name="valor" type="number" step="0.01" min="0" class="mt-1 block w-full" required />
                    </div>
                    <div>
                        <x-input-label for="centro_custo_id" value="Centro de custo" />
                        <select id="centro_custo_id" name="centro_custo_id" class="mt-1 block w-full border-line rounded-control">
                            <option value="">—</option>
                            @foreach ($centrosCusto as $centro)
                                <option value="{{ $centro->id }}">{{ $centro->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="conta_id" value="Categoria (conta)" />
                        <select id="conta_id" name="conta_id" class="mt-1 block w-full border-line rounded-control">
                            <option value="">—</option>
                            @foreach ($contas as $conta)
                                <option value="{{ $conta->id }}">{{ $conta->subcategoria->categoria->nome }} › {{ $conta->subcategoria->nome }} › {{ $conta->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="fornecedor_id" value="Fornecedor" />
                        <select id="fornecedor_id" name="fornecedor_id" class="mt-1 block w-full border-line rounded-control">
                            <option value="">—</option>
                            @foreach ($fornecedores as $fornecedor)
                                <option value="{{ $fornecedor->id }}">{{ $fornecedor->razao_social }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="dia_vencimento" value="Dia do vencimento" />
                        <x-text-input id="dia_vencimento" name="dia_vencimento" type="number" min="1" max="31" class="mt-1 block w-full" required />
                    </div>
                    <div>
                        <x-input-label for="conta_financeira_id" value="Conta de pagamento" />
                        <select id="conta_financeira_id" name="conta_financeira_id" class="mt-1 block w-full border-line rounded-control">
                            <option value="">—</option>
                            @foreach ($contasFinanceiras as $conta)
                                <option value="{{ $conta->id }}">{{ $conta->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="data_inicio" value="Início da vigência" />
                        <x-text-input id="data_inicio" name="data_inicio" type="date" class="mt-1 block w-full" value="{{ now()->toDateString() }}" required />
                    </div>
                    <div>
                        <x-input-label for="data_fim" value="Fim da vigência (opcional)" />
                        <x-text-input id="data_fim" name="data_fim" type="date" class="mt-1 block w-full" />
                    </div>
                    <div class="sm:col-span-3 flex justify-end">
                        <x-primary-button>Adicionar despesa fixa</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
