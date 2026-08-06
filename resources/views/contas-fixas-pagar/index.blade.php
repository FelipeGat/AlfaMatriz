<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2 text-[14px]">
            <span class="text-mute">Financeiro</span>
            <span class="text-line">/</span>
            <span class="font-medium text-ink">Despesas Fixas</span>
        </div>
    </x-slot>

    <div class="space-y-[18px]" x-data="{ novaDespesa: false }">
        @if ($errors->any())
            <div class="rounded-control border border-line bg-raised px-4 py-2.5 text-[12.5px] text-bad">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
            <x-summary-card label="Total mensal" :value="'R$ ' . number_format($totalMensal, 2, ',', '.')" tom="warn" contexto="somando as ativas" />
            <x-summary-card label="Despesas ativas" :value="$quantidadeAtivas" contexto="de {{ $contasFixas->count() }} cadastradas" />
            <x-summary-card label="Geradas no mês" :value="$geradasNoMes" tom="good" contexto="competência {{ $competencia }}" />
            <x-summary-card
                label="A gerar"
                :value="$pendentesDeGeracao"
                :tom="$pendentesDeGeracao > 0 ? 'warn' : 'ink'"
                contexto="ainda sem conta a pagar" />
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2">
            <form action="{{ route('contas-fixas-pagar.gerar') }}" method="POST" class="flex items-center gap-2">
                @csrf
                <label for="competencia" class="font-mono text-[10px] font-medium uppercase tracking-[.08em] text-mute">Competência</label>
                <input type="month" id="competencia" name="competencia" value="{{ $competencia }}"
                       class="h-8 rounded-control border-line bg-panel py-0 text-[12.5px] text-ink focus:border-ink focus:ring-0">
                {{-- Inerte quando não há o que gerar, em vez de sumir. --}}
                <button type="submit" @disabled($pendentesDeGeracao === 0)
                        class="inline-flex h-8 items-center rounded-control px-3 text-[12.5px] font-medium transition-opacity
                               {{ $pendentesDeGeracao === 0 ? 'cursor-default bg-raised text-mute' : 'bg-ink text-bg hover:opacity-90' }}">
                    {{ $pendentesDeGeracao === 0 ? 'Nada a gerar' : 'Gerar contas do mês ('.$pendentesDeGeracao.')' }}
                </button>
            </form>

            <button type="button" @click="novaDespesa = !novaDespesa"
                    class="inline-flex h-8 items-center rounded-control border border-line px-3 text-[12.5px] text-dim transition-colors hover:text-ink">
                <span x-text="novaDespesa ? 'Cancelar' : 'Nova despesa fixa'"></span>
            </button>
        </div>

        {{-- O formulário fica recolhido: ele é longo e a lista é o que se
             consulta no dia a dia. --}}
        <div x-show="novaDespesa" x-cloak class="anim-fade">
            <x-painel-card titulo="Nova despesa fixa">
                <form action="{{ route('contas-fixas-pagar.store') }}" method="POST" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
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
                        <select id="centro_custo_id" name="centro_custo_id" class="mt-1 block h-8 w-full py-0 text-[12.5px]">
                            <option value="">—</option>
                            @foreach ($centrosCusto as $centro)
                                <option value="{{ $centro->id }}">{{ $centro->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="conta_id" value="Categoria (conta)" />
                        <select id="conta_id" name="conta_id" class="mt-1 block h-8 w-full py-0 text-[12.5px]">
                            <option value="">—</option>
                            @foreach ($contas as $conta)
                                <option value="{{ $conta->id }}">{{ $conta->subcategoria->categoria->nome }} › {{ $conta->subcategoria->nome }} › {{ $conta->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="fornecedor_id" value="Fornecedor" />
                        <select id="fornecedor_id" name="fornecedor_id" class="mt-1 block h-8 w-full py-0 text-[12.5px]">
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
                        <select id="conta_financeira_id" name="conta_financeira_id" class="mt-1 block h-8 w-full py-0 text-[12.5px]">
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
                    <div class="flex justify-end sm:col-span-3">
                        <x-primary-button>Adicionar despesa fixa</x-primary-button>
                    </div>
                </form>
            </x-painel-card>
        </div>

        <x-painel-card :sem-padding="true">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[940px] border-collapse">
                    <thead>
                        <tr class="bg-raised">
                            @foreach (['Descrição' => '', 'Categoria' => 'w-[170px]', 'Fornecedor' => 'w-[160px]', 'Valor' => 'w-[124px] text-right', 'Dia' => 'w-[74px]', 'Vigência' => 'w-[150px]', 'Situação' => 'w-[104px]', '' => 'w-[110px]'] as $titulo => $largura)
                                <th class="{{ $largura }} truncate whitespace-nowrap border-b border-line px-5 py-2.5 text-left font-mono text-[10px] font-medium uppercase tracking-[.08em] text-mute">
                                    {{ $titulo }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($contasFixas as $fixa)
                            <tr class="border-b border-line transition-colors last:border-0 hover:bg-raised">
                                <td class="px-5 py-3 text-[14px] text-ink">{{ $fixa->descricao }}</td>
                                <td class="max-w-[170px] truncate px-5 py-3 text-[12.5px] text-dim">{{ $fixa->conta?->nome ?? '—' }}</td>
                                <td class="max-w-[160px] truncate px-5 py-3 text-[12.5px] text-dim">{{ $fixa->fornecedor?->razao_social ?? '—' }}</td>
                                <td class="valor px-5 py-3 text-right text-[12.5px] font-medium text-ink">R$ {{ number_format($fixa->valor, 2, ',', '.') }}</td>
                                <td class="valor px-5 py-3 text-[12.5px] text-dim">{{ $fixa->dia_vencimento }}</td>
                                <td class="valor px-5 py-3 text-[11.5px] text-mute">
                                    {{ $fixa->data_inicio->format('m/Y') }} → {{ $fixa->data_fim?->format('m/Y') ?? '∞' }}
                                </td>
                                <td class="px-5 py-3">
                                    <x-status-pill :tom="$fixa->ativo ? 'good' : 'neutro'">
                                        {{ $fixa->ativo ? 'Ativa' : 'Inativa' }}
                                    </x-status-pill>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-right text-[12.5px]">
                                    <form action="{{ route('contas-fixas-pagar.destroy', $fixa) }}" method="POST" class="inline" onsubmit="return confirm('Remover esta despesa fixa?');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-dim transition-colors hover:text-bad">Remover</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-5 py-[34px] text-center text-[14px] text-mute">Nenhuma despesa fixa cadastrada.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-painel-card>
    </div>
</x-app-layout>
