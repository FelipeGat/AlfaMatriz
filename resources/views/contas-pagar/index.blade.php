<x-app-layout>
    <x-slot name="titulo">Despesas</x-slot>
    <x-slot name="contexto">contas a pagar · {{ $kpis['a_pagar_titulos'] }} em aberto</x-slot>
    <x-slot name="acoes">
        <button type="button" x-data @click="$dispatch('open-modal', 'nova-despesa')"
                class="h-[34px] px-3 rounded-control bg-brand text-on-brand font-semibold text-[12.5px]
                       hover:bg-brand-bright transition whitespace-nowrap">
            + Nova despesa
        </button>
    </x-slot>

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

        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr))">
            <x-kpi-card rotulo="A pagar" :valor="'R$ '.number_format($kpis['a_pagar'], 2, ',', '.')"
                        :delta="$kpis['a_pagar_titulos'].' '.($kpis['a_pagar_titulos'] === 1 ? 'título' : 'títulos')"
                        acento="warn" icone="trending-down" />
            <x-kpi-card rotulo="Vence em 7 dias" :valor="'R$ '.number_format($kpis['vence_em_7_dias'], 2, ',', '.')"
                        acento="amber" icone="clock" />
            <x-kpi-card rotulo="Pago no mês" :valor="'R$ '.number_format($kpis['pago_mes'], 2, ',', '.')"
                        acento="good" icone="check-circle" sinal="bom" />
            <x-kpi-card rotulo="Atrasado" :valor="'R$ '.number_format($kpis['atrasado'], 2, ',', '.')"
                        :delta="$kpis['atrasado_titulos'] > 0 ? $kpis['atrasado_titulos'].' '.($kpis['atrasado_titulos'] === 1 ? 'título' : 'títulos') : 'nada em atraso'"
                        :sinal="$kpis['atrasado_titulos'] > 0 ? 'ruim' : 'bom'"
                        acento="crit" icone="alert-triangle" />
        </div>

        {{-- Faixa de atraso: onde o dinheiro está travado ------------------- --}}
        @php
            $totalEmAberto = collect($faixas)->sum('valor');
            $coresFaixa = ['a_vencer' => 'accent', '1_15' => 'warn', '16_30' => 'amber', 'mais_30' => 'crit'];
        @endphp

        @if ($totalEmAberto > 0)
            <section class="rounded-panel border border-line bg-card-grad p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <h2 class="font-display text-[15px] font-semibold text-ink">Em aberto por faixa de vencimento</h2>
                    <span class="font-mono text-[13px] text-ink-dim whitespace-nowrap">R$ {{ number_format($totalEmAberto, 2, ',', '.') }}</span>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-2">
                    @foreach ($faixas as $chave => $faixa)
                        <span class="flex items-center gap-2 min-w-0">
                            <span class="h-2.5 w-2.5 shrink-0 rounded-badge" style="background: rgb(var(--{{ $coresFaixa[$chave] }}))"></span>
                            <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint whitespace-nowrap">{{ $faixa['rotulo'] }}</span>
                            <span class="font-mono text-[12.5px] text-ink whitespace-nowrap">R$ {{ number_format($faixa['valor'], 2, ',', '.') }}</span>
                        </span>
                    @endforeach
                </div>

                <div class="mt-3 flex w-full gap-[2px] overflow-hidden rounded-badge" style="height: 8px">
                    @foreach ($faixas as $chave => $faixa)
                        @continue($faixa['valor'] <= 0)
                        <span class="block h-full shrink-0"
                              data-faixa="{{ $chave }}"
                              style="width: {{ round(($faixa['valor'] / $totalEmAberto) * 100, 3) }}%; min-width: 3px;
                                     background: rgb(var(--{{ $coresFaixa[$chave] }}))"
                              title="{{ $faixa['rotulo'] }} · R$ {{ number_format($faixa['valor'], 2, ',', '.') }}"></span>
                    @endforeach
                </div>
            </section>
        @endif

        <form method="GET" class="flex flex-wrap items-center gap-2">
            <select name="status" onchange="this.form.submit()"
                    class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="">Todos os status</option>
                <option value="em_aberto" @selected(request('status') === 'em_aberto')>Em aberto</option>
                <option value="pago" @selected(request('status') === 'pago')>Pago</option>
                <option value="cancelado" @selected(request('status') === 'cancelado')>Cancelado</option>
            </select>

            <select name="tipo" onchange="this.form.submit()"
                    class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="">Pontuais e recorrentes</option>
                <option value="avulsa" @selected(request('tipo') === 'avulsa')>Pontuais</option>
                <option value="fixa" @selected(request('tipo') === 'fixa')>Recorrentes</option>
            </select>
        </form>

        <div x-data="{ selecionados: [], valores: {} }">
            <form id="bulk-baixa-despesas" action="{{ route('contas-pagar.baixarEmMassa') }}" method="POST" class="hidden">
                @csrf
                <template x-for="id in selecionados" :key="id">
                    <input type="hidden" name="ids[]" :value="id">
                </template>
            </form>

            {{-- A barra só existe quando há seleção, e ela diz QUANTO — não só
                 quantos: dar baixa é irreversível, e o valor é a conferência. --}}
            <div x-show="selecionados.length > 0" x-cloak
                 class="mb-3 flex flex-wrap items-center gap-3 rounded-panel border px-4 py-2.5"
                 style="background: rgb(var(--brand) / 0.08); border-color: rgb(var(--brand) / 0.3)">
                <span class="font-mono text-[12px] uppercase tracking-caps text-brand-text">
                    <span x-text="selecionados.length"></span> selecionada(s) ·
                    R$ <span x-text="selecionados.reduce((soma, id) => soma + (valores[id] ?? 0), 0)
                                     .toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></span>
                </span>

                <button type="submit" form="bulk-baixa-despesas"
                        onclick="return confirm('Dar baixa nas despesas selecionadas?');"
                        class="ml-auto h-8 px-3 rounded-control bg-brand text-on-brand text-[12px] font-semibold hover:bg-brand-bright transition">
                    Dar baixa nas despesas
                </button>
                <button type="button" @click="selecionados = []"
                        class="font-mono text-[10.5px] uppercase tracking-caps text-ink-mute hover:text-ink transition">
                    Limpar
                </button>
            </div>

            <x-tabela min="1040px">
                <thead>
                    <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                        <th class="pl-4 pr-2 py-2.5 w-8"></th>
                        <th class="px-4 py-2.5 font-semibold">Despesa</th>
                        <th class="px-4 py-2.5 font-semibold">Fornecedor</th>
                        <th class="px-4 py-2.5 font-semibold">Vencimento</th>
                        <th class="px-4 py-2.5 font-semibold text-right">Valor</th>
                        <th class="px-4 py-2.5 font-semibold">Status</th>
                        <th class="px-4 py-2.5 font-semibold text-right">Ações</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($contasPagar as $contaPagar)
                        @php
                            $vencimento = \Illuminate\Support\Carbon::parse($contaPagar->data_vencimento);
                            $emAberto = $contaPagar->status === 'em_aberto';
                            $dias = (int) $hoje->diffInDays($vencimento, false);
                            $atrasada = $emAberto && $dias < 0;
                            $vencendo = $emAberto && $dias >= 0 && $dias <= 3;
                            $recorrente = $contaPagar->tipo === 'fixa';
                        @endphp

                        <tr class="border-b border-rule hover:bg-chip transition"
                            @if ($atrasada)
                                style="border-left: 2px solid rgb(var(--crit)); background: rgb(var(--crit) / 0.07)"
                            @elseif ($vencendo)
                                style="border-left: 2px solid rgb(var(--warn)); background: rgb(var(--warn) / 0.05)"
                            @endif>
                            <td class="pl-4 pr-2 py-3">
                                @if ($emAberto)
                                    <input type="checkbox" class="rounded-badge"
                                           x-init="valores[{{ $contaPagar->id }}] = {{ (float) $contaPagar->valor }}"
                                           @change="$event.target.checked
                                               ? selecionados.push({{ $contaPagar->id }})
                                               : selecionados = selecionados.filter(i => i !== {{ $contaPagar->id }})"
                                           :checked="selecionados.includes({{ $contaPagar->id }})"
                                           aria-label="Selecionar {{ $contaPagar->descricao }}">
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2.5 min-w-0">
                                    <span class="h-7 w-7 shrink-0 rounded-tile flex items-center justify-center"
                                          style="background: rgb(var(--{{ $recorrente ? 'brand' : 'ink-mute' }}) / var(--tint-alpha));
                                                 color: rgb(var(--{{ $recorrente ? 'brand-text' : 'ink-mute' }}))">
                                        <span class="h-[14px] w-[14px]"><x-nav-icon :name="$recorrente ? 'repeat' : 'document'" /></span>
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block text-[13.5px] text-ink truncate">{{ $contaPagar->descricao }}</span>
                                        {{-- Recorrentes e pontuais convivem na mesma lista; o que
                                             as distingue é o subtítulo e o ícone, não uma aba. --}}
                                        <span class="block font-mono text-[11px] uppercase tracking-caps text-ink-faint truncate">
                                            {{ $recorrente
                                                ? 'recorrente · todo dia '.$vencimento->format('d')
                                                : 'pontual' }}
                                        </span>
                                    </span>
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                <span class="block text-[13px] text-ink-dim truncate">{{ $contaPagar->fornecedor->nome ?? '—' }}</span>
                                <span class="block font-mono text-[11px] uppercase tracking-caps text-ink-faint truncate">
                                    centro: {{ $contaPagar->centroCusto->nome ?? 'não informado' }}
                                </span>
                            </td>

                            <td class="px-4 py-3">
                                <span class="block font-mono text-[13px] text-ink whitespace-nowrap">{{ $vencimento->format('d/m/Y') }}</span>
                                @if ($emAberto)
                                    <span class="block font-mono text-[11px] whitespace-nowrap"
                                          style="color: rgb(var(--{{ $atrasada ? 'crit' : ($vencendo ? 'warn' : 'ink-faint') }}))">
                                        {{ $atrasada ? 'atraso '.abs($dias).'d' : 'em '.$dias.'d' }}
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-right font-mono text-[13.5px] text-ink whitespace-nowrap">
                                R$ {{ number_format($contaPagar->valor, 2, ',', '.') }}
                            </td>

                            <td class="px-4 py-3">
                                <x-badge :tom="match ($contaPagar->status) {
                                    'pago' => 'bom',
                                    'cancelado' => 'neutro',
                                    default => $atrasada ? 'critico' : 'atencao',
                                }" ponto>
                                    {{ ucfirst(str_replace('_', ' ', $contaPagar->status)) }}
                                </x-badge>
                            </td>

                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <button type="button" title="Anexos (NF/Boleto)" aria-label="Anexos"
                                            @click="$dispatch('open-modal', 'anexos-despesa'); $dispatch('anexos-selecionar', { modal: 'anexos-despesa', id: {{ $contaPagar->id }} })"
                                            class="relative inline-flex h-7 w-7 items-center justify-center rounded-tile text-ink-mute hover:text-brand hover:bg-chip transition">
                                        <span class="h-[15px] w-[15px]"><x-nav-icon name="paperclip" /></span>
                                        @if ($contaPagar->anexos_count > 0)
                                            <span class="absolute -top-1 -right-1 h-3.5 w-3.5 rounded-full bg-brand text-on-brand font-mono text-[9px] leading-[14px] font-semibold text-center">{{ $contaPagar->anexos_count }}</span>
                                        @endif
                                    </button>

                                    @if ($emAberto)
                                        <form action="{{ route('contas-pagar.baixar', $contaPagar) }}" method="POST"
                                              onsubmit="return confirm('Confirmar pagamento?');">
                                            @csrf
                                            <x-acao-tabela icone="check-circle" titulo="Dar baixa" type="submit" />
                                        </form>
                                    @endif

                                    @if ($contaPagar->contaFixaPagar)
                                        <form action="{{ route('contas-fixas-pagar.pausar', $contaPagar->contaFixaPagar) }}" method="POST"
                                              onsubmit="return confirm('{{ $contaPagar->contaFixaPagar->ativo ? 'Pausar esta despesa recorrente? Ela para de gerar novas parcelas.' : 'Reativar esta despesa recorrente?' }}');">
                                            @csrf
                                            <x-acao-tabela :icone="$contaPagar->contaFixaPagar->ativo ? 'pause' : 'play'"
                                                           :titulo="$contaPagar->contaFixaPagar->ativo ? 'Pausar recorrência' : 'Ativar recorrência'"
                                                           type="submit" />
                                        </form>
                                    @endif

                                    <x-acao-tabela icone="pencil" titulo="Editar despesa" :href="route('contas-pagar.edit', $contaPagar)" />

                                    <form action="{{ route('contas-pagar.destroy', $contaPagar) }}" method="POST"
                                          onsubmit="return confirm('Remover esta despesa?');">
                                        @csrf @method('DELETE')
                                        <x-acao-tabela icone="trash" titulo="Remover despesa" type="submit" destrutivo />
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-[13px] text-ink-mute">Nenhuma despesa encontrada.</td>
                        </tr>
                    @endforelse
                </tbody>

                <x-slot name="rodape">
                    <span>{{ $contasPagar->count() }} de {{ $contasPagar->total() }} despesas</span>
                    <span>· página {{ $contasPagar->currentPage() }} de {{ $contasPagar->lastPage() }}</span>
                </x-slot>
            </x-tabela>

            @if ($contasPagar->hasPages())
                <div class="mt-3">{{ $contasPagar->links() }}</div>
            @endif
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

    <x-anexos-modal name="anexos-despesa" resource-url="{{ url('contas-pagar') }}" anexo-url="{{ url('contas-pagar/anexos') }}" />
</x-app-layout>
