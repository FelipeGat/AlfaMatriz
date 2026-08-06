<x-app-layout>
    <x-slot name="titulo">Revendas</x-slot>
    <x-slot name="contexto">{{ $linhas->count() }} de {{ $cadastradas }} cadastradas</x-slot>
    <x-slot name="acoes">
        <a href="{{ route('revendas.create') }}"
           class="h-[34px] px-3 inline-flex items-center rounded-control bg-brand text-on-brand
                  font-semibold text-[12.5px] hover:bg-brand-bright transition whitespace-nowrap">
            + Nova revenda
        </a>
    </x-slot>

    <div class="space-y-4">
        @if (session('status'))
            <div class="rounded-panel border px-4 py-2.5 text-[13px]"
                 style="background: rgb(var(--good) / var(--tint-alpha)); border-color: rgb(var(--good) / 0.25); color: rgb(var(--good))">
                {{ session('status') }}
            </div>
        @endif

        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr))">
            <x-kpi-card rotulo="Revendas ativas" :valor="$kpis['ativas']['valor']" :delta="$kpis['ativas']['nota']"
                        acento="accent" icone="building" />
            <x-kpi-card rotulo="Clientes via revenda" :valor="number_format($kpis['clientes']['valor'], 0, ',', '.')"
                        :delta="$kpis['clientes']['nota']" acento="brand" icone="users" />
            <x-kpi-card rotulo="MRR de revenda" :valor="'R$ '.number_format($kpis['mrr']['valor'], 2, ',', '.')"
                        :delta="$kpis['mrr']['nota']" acento="good" icone="trending-up" />
            <x-kpi-card rotulo="Ticket médio" :valor="'R$ '.number_format($kpis['ticket']['valor'], 2, ',', '.')"
                        :delta="$kpis['ticket']['nota']" acento="amber" icone="repeat" />
        </div>

        {{-- Filtros: sempre na query string, para o recorte ser compartilhável. --}}
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <label class="flex items-center gap-2 h-[34px] flex-1 min-w-[220px] max-w-[380px] px-3
                          rounded-control bg-input border border-line text-ink-faint focus-within:border-brand transition">
                <span class="h-4 w-4 shrink-0"><x-nav-icon name="search" /></span>
                <input type="search" name="q" value="{{ $filtros['q'] }}"
                       placeholder="Buscar revenda ou CNPJ…"
                       class="flex-1 min-w-0 bg-transparent border-0 p-0 text-[13px] text-ink placeholder-ink-faint focus:ring-0">
            </label>

            <select name="status" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="">Todas</option>
                <option value="ativo" @selected($filtros['status'] === 'ativo')>Ativas</option>
                <option value="inativo" @selected($filtros['status'] === 'inativo')>Inativas</option>
            </select>

            <select name="ordem" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="mrr" @selected($filtros['ordem'] === 'mrr')>Maior MRR</option>
                <option value="clientes" @selected($filtros['ordem'] === 'clientes')>Mais clientes</option>
                <option value="nome" @selected($filtros['ordem'] === 'nome')>Nome</option>
            </select>

            <button type="submit"
                    class="h-[34px] px-3 rounded-control border border-btn-line text-ink-dim
                           text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
                Filtrar
            </button>
        </form>

        <x-tabela min="1000px">
            <thead>
                <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                    <th class="px-4 py-2.5 font-semibold">Revenda</th>
                    <th class="px-4 py-2.5 font-semibold">Contato</th>
                    <th class="px-4 py-2.5 font-semibold">Base de clientes</th>
                    <th class="px-4 py-2.5 font-semibold">MRR / mês</th>
                    <th class="px-4 py-2.5 font-semibold">Sistemas revendidos</th>
                    <th class="px-4 py-2.5 font-semibold">Status</th>
                    <th class="px-4 py-2.5 font-semibold text-right">Ações</th>
                </tr>
            </thead>

            <tbody>
                @php $maiorBase = max($linhas->max('clientes') ?: 1, 1); @endphp

                @forelse ($linhas as $linha)
                    @php $revenda = $linha['revenda']; @endphp
                    <tr class="border-b border-rule hover:bg-chip transition {{ $revenda->ativo ? '' : 'opacity-60' }}"
                        @if ($linha['emAtraso'] > 0)
                            style="border-left: 2px solid rgb(var(--warn)); background: rgb(var(--warn) / 0.05)"
                        @endif>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <span class="h-8 w-8 shrink-0 rounded-ctl bg-brand/15 text-brand-text
                                             flex items-center justify-center font-display text-[12.5px] font-semibold">
                                    {{ Str::of($revenda->nome)->substr(0, 2)->upper() }}
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-[13.5px] font-medium text-ink truncate">{{ $revenda->nome }}</span>
                                    <span class="block font-mono text-[11.5px] text-ink-faint truncate">{{ $revenda->cnpj ?: 'sem CNPJ' }}</span>
                                </span>
                            </div>
                        </td>

                        <td class="px-4 py-3">
                            <span class="block text-[13px] text-ink-dim truncate">{{ $revenda->contato_nome ?: '—' }}</span>
                            <span class="block text-[11.5px] text-ink-faint truncate">
                                {{ $revenda->contato_email ?: $revenda->contato_telefone ?: 'sem contato' }}
                            </span>
                        </td>

                        <td class="px-4 py-3">
                            <div class="flex items-baseline gap-2">
                                <span class="font-mono text-[13.5px] text-ink tabular">{{ number_format($linha['clientes'], 0, ',', '.') }}</span>
                                <span class="font-mono text-[11px] text-ink-faint">{{ number_format($linha['share'] * 100, 0) }}% da base</span>
                            </div>
                            <span class="mt-1.5 block h-1.5 w-full max-w-[140px] rounded-badge bg-bar-track overflow-hidden">
                                <span class="block h-full rounded-badge"
                                      data-barra="{{ $revenda->nome }}"
                                      style="width: {{ round(($linha['clientes'] / $maiorBase) * 100, 2) }}%; background: rgb(var(--accent))"></span>
                            </span>
                        </td>

                        <td class="px-4 py-3">
                            <span class="block font-mono text-[13.5px] text-ink whitespace-nowrap">R$ {{ number_format($linha['mrr'], 2, ',', '.') }}</span>
                            @if ($linha['delta'])
                                <span class="block font-mono text-[11px] text-ink-faint">{{ $linha['delta'] }} vs mês anterior</span>
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            @if (empty($linha['sistemas']))
                                <span class="text-[12.5px] text-ink-faint">nenhum</span>
                            @else
                                <div class="flex flex-wrap items-center gap-1">
                                    @foreach (array_slice($linha['sistemas'], 0, 3) as $sistema)
                                        <x-badge>{{ $sistema }}</x-badge>
                                    @endforeach
                                    @if (count($linha['sistemas']) > 3)
                                        <x-badge :title="implode(', ', $linha['sistemas'])">+{{ count($linha['sistemas']) - 3 }}</x-badge>
                                    @endif
                                </div>
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            <x-badge :tom="$revenda->ativo ? 'bom' : 'neutro'" ponto>
                                {{ $revenda->ativo ? 'Ativa' : 'Inativa' }}
                            </x-badge>
                            @if ($linha['emAtraso'] > 0)
                                <span class="mt-1 block font-mono text-[10px] uppercase tracking-caps whitespace-nowrap"
                                      style="color: rgb(var(--warn))">
                                    {{ $linha['emAtraso'] }} em atraso
                                </span>
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <x-acao-tabela icone="eye" titulo="Ver clientes da revenda"
                                               :href="route('clientes.index', ['revenda' => $revenda->id])" />
                                <x-acao-tabela icone="repeat" titulo="Faturamento da revenda"
                                               :href="route('faturamento.index')" />
                                <x-acao-tabela icone="pencil" titulo="Editar revenda"
                                               :href="route('revendas.edit', $revenda)" />
                                <x-confirmar :action="route('revendas.destroy', $revenda)" method="DELETE"
                                             icone="trash" destrutivo confirmar="Remover"
                                             :titulo="'Remover '.$revenda->nome.'?'"
                                             mensagem="A revenda sai da lista e deixa de aparecer no faturamento. Os clientes dela continuam cadastrados." />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-[13px] text-ink-mute">
                            Nenhuma revenda encontrada com esse recorte.
                        </td>
                    </tr>
                @endforelse
            </tbody>

            @if ($linhas->isNotEmpty())
                <tfoot>
                    <x-linha-total>
                        <td>Total</td>
                        <td></td>
                        <td>{{ number_format($totais['clientes'], 0, ',', '.') }} clientes</td>
                        <td>R$ {{ number_format($totais['mrr'], 2, ',', '.') }}</td>
                        <td>{{ $totais['sistemas'] }} sistemas distintos</td>
                        <td></td>
                        <td></td>
                    </x-linha-total>
                </tfoot>
            @endif

            <x-slot name="rodape">
                <span>{{ $linhas->count() }} de {{ $cadastradas }} revendas</span>
                @if ($kpis['mrr']['valor'] > 0)
                    <span>· {{ $kpis['mrr']['nota'] }} vem de revenda</span>
                @endif
            </x-slot>
        </x-tabela>
    </div>
</x-app-layout>
