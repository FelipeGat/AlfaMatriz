<x-app-layout>
    <x-slot name="titulo">Despesas recorrentes</x-slot>
    <x-slot name="contexto">modelos que geram as parcelas do mês</x-slot>

    <div class="space-y-4">
        @if (session('status'))
            <div class="rounded-panel border px-4 py-2.5 text-[13px]"
                 style="background: rgb(var(--good) / var(--tint-alpha)); border-color: rgb(var(--good) / 0.25); color: rgb(var(--good))">
                {{ session('status') }}
            </div>
        @endif
        @if ($errors->any())
            <div class="rounded-panel border px-4 py-2.5 text-[13px]"
                 style="background: var(--crit-tint); border-color: rgb(var(--crit) / 0.25); color: rgb(var(--crit))">
                {{ $errors->first() }}
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-3">
            <div class="flex-1 min-w-[240px] rounded-panel border border-line bg-card-grad px-4 py-3">
                <p class="text-[11px] uppercase tracking-[0.10em] text-ink-mute">Total mensal · recorrentes ativas</p>
                <p class="mt-1 font-display text-[24px] font-semibold leading-none text-ink tabular whitespace-nowrap">
                    R$ {{ number_format($totalMensal, 2, ',', '.') }}
                </p>
            </div>

            <form action="{{ route('contas-fixas-pagar.gerar') }}" method="POST" class="flex items-end gap-2 shrink-0">
                @csrf
                <label class="block">
                    <span class="block font-mono text-[10px] uppercase tracking-caps text-ink-faint mb-1">Gerar a competência</span>
                    <input type="month" name="competencia" value="{{ now()->format('Y-m') }}"
                           class="h-9 py-0 text-[13px] rounded-control bg-input border-line text-ink">
                </label>
                <button type="submit"
                        class="h-9 px-3 rounded-control bg-brand text-on-brand text-[12.5px] font-semibold
                               hover:bg-brand-bright transition whitespace-nowrap">
                    Gerar contas do mês
                </button>
            </form>
        </div>

        <x-tabela min="960px">
            <thead>
                <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                    <th class="px-4 py-2.5 font-semibold">Despesa</th>
                    <th class="px-4 py-2.5 font-semibold">Categoria</th>
                    <th class="px-4 py-2.5 font-semibold">Fornecedor</th>
                    <th class="px-4 py-2.5 font-semibold text-right">Valor</th>
                    <th class="px-4 py-2.5 font-semibold">Vigência</th>
                    <th class="px-4 py-2.5 font-semibold">Status</th>
                    <th class="px-4 py-2.5 font-semibold text-right">Ações</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($contasFixas as $fixa)
                    <tr class="border-b border-rule hover:bg-chip transition {{ $fixa->ativo ? '' : 'opacity-[0.62]' }}">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <span class="h-7 w-7 shrink-0 rounded-tile flex items-center justify-center bg-brand/15 text-brand-text">
                                    <span class="h-[14px] w-[14px]"><x-nav-icon name="repeat" /></span>
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-[13.5px] text-ink truncate">{{ $fixa->descricao }}</span>
                                    <span class="block font-mono text-[11px] uppercase tracking-caps text-ink-faint truncate">
                                        recorrente · todo dia {{ $fixa->dia_vencimento }}
                                    </span>
                                </span>
                            </div>
                        </td>

                        <td class="px-4 py-3 text-[13px] text-ink-dim truncate">{{ $fixa->conta?->nome ?? '—' }}</td>
                        <td class="px-4 py-3 text-[13px] text-ink-dim truncate">{{ $fixa->fornecedor?->razao_social ?? '—' }}</td>

                        <td class="px-4 py-3 text-right font-mono text-[13.5px] text-ink whitespace-nowrap">
                            R$ {{ number_format($fixa->valor, 2, ',', '.') }}
                        </td>

                        <td class="px-4 py-3 font-mono text-[12px] text-ink-dim whitespace-nowrap">
                            {{ $fixa->data_inicio->format('m/Y') }} → {{ $fixa->data_fim?->format('m/Y') ?? 'indeterminado' }}
                        </td>

                        <td class="px-4 py-3">
                            <x-badge :tom="$fixa->ativo ? 'bom' : 'neutro'" ponto>{{ $fixa->ativo ? 'Ativa' : 'Pausada' }}</x-badge>
                        </td>

                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <form action="{{ route('contas-fixas-pagar.pausar', $fixa) }}" method="POST"
                                      onsubmit="return confirm('{{ $fixa->ativo ? 'Pausar esta despesa recorrente? Ela para de gerar novas parcelas.' : 'Reativar esta despesa recorrente?' }}');">
                                    @csrf
                                    <x-acao-tabela :icone="$fixa->ativo ? 'pause' : 'play'"
                                                   :titulo="$fixa->ativo ? 'Pausar recorrência' : 'Ativar recorrência'"
                                                   type="submit" />
                                </form>
                                <form action="{{ route('contas-fixas-pagar.destroy', $fixa) }}" method="POST"
                                      onsubmit="return confirm('Remover esta despesa fixa?');">
                                    @csrf @method('DELETE')
                                    <x-acao-tabela icone="trash" titulo="Remover despesa recorrente" type="submit" destrutivo />
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-[13px] text-ink-mute">Nenhuma despesa recorrente cadastrada.</td>
                    </tr>
                @endforelse
            </tbody>

            <x-slot name="rodape">
                <span>{{ $contasFixas->count() }} {{ $contasFixas->count() === 1 ? 'modelo' : 'modelos' }} de recorrência</span>
            </x-slot>
        </x-tabela>

            <div class="rounded-panel border border-line bg-subtle p-4">
                <h3 class="font-mono text-[10.5px] font-semibold uppercase tracking-caps-wide text-ink-faint mb-3">Nova despesa fixa</h3>
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
                        <select id="centro_custo_id" name="centro_custo_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                            <option value="">—</option>
                            @foreach ($centrosCusto as $centro)
                                <option value="{{ $centro->id }}">{{ $centro->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="conta_id" value="Categoria (conta)" />
                        <select id="conta_id" name="conta_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                            <option value="">—</option>
                            @foreach ($contas as $conta)
                                <option value="{{ $conta->id }}">{{ $conta->subcategoria->categoria->nome }} › {{ $conta->subcategoria->nome }} › {{ $conta->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="fornecedor_id" value="Fornecedor" />
                        <select id="fornecedor_id" name="fornecedor_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
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
                        <select id="conta_financeira_id" name="conta_financeira_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
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
                        <button type="submit"
                                class="h-9 px-3.5 rounded-control bg-brand text-on-brand text-[12.5px] font-semibold
                                       hover:bg-brand-bright transition">
                            Adicionar despesa recorrente
                        </button>
                    </div>
                </form>
        </div>
    </div>
</x-app-layout>
