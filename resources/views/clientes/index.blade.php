<x-app-layout>
    <x-slot name="titulo">Clientes</x-slot>
    <x-slot name="contexto">{{ $clientes->total() }} cadastrados</x-slot>
    <x-slot name="acoes">
        <button type="button" x-data @click="$dispatch('open-modal', 'novo-cliente')"
                class="h-[34px] px-3 inline-flex items-center rounded-control bg-brand text-on-brand font-semibold text-[12.5px] hover:bg-brand-bright transition whitespace-nowrap">
            + Novo cliente
        </button>
    </x-slot>

    <div class="space-y-4">
        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif

        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr))">
            <x-kpi-card rotulo="Clientes cadastrados" :valor="number_format($kpis['cadastrados']['valor'], 0, ',', '.')"
                        :delta="$kpis['cadastrados']['nota']" acento="accent" icone="users" />
            <x-kpi-card rotulo="Em contrato" :valor="number_format($kpis['contrato']['valor'], 0, ',', '.')"
                        :delta="$kpis['contrato']['nota']" acento="brand" icone="repeat" />
            <x-kpi-card rotulo="Avulsos" :valor="number_format($kpis['avulsos']['valor'], 0, ',', '.')"
                        :delta="$kpis['avulsos']['nota']" acento="amber" icone="clipboard" />
            <x-kpi-card rotulo="Ticket médio" :valor="'R$ '.number_format($kpis['ticket']['valor'], 2, ',', '.')"
                        :delta="$kpis['ticket']['nota']" acento="good" icone="banknotes" />
        </div>

        <form method="GET" class="flex flex-wrap items-center gap-2">
            <label class="flex items-center gap-2 h-[34px] flex-1 min-w-[220px] max-w-[360px] px-3
                          rounded-control bg-input border border-line text-ink-faint focus-within:border-brand transition">
                <span class="h-4 w-4 shrink-0"><x-nav-icon name="search" /></span>
                <input type="search" name="busca" value="{{ $filtros['busca'] }}"
                       placeholder="Buscar nome, CNPJ ou cidade…"
                       class="flex-1 min-w-0 bg-transparent border-0 p-0 text-[13px] text-ink placeholder-ink-faint focus:ring-0">
            </label>

            <select name="revenda" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="">Todas as origens</option>
                <option value="direta" @selected($filtros['revenda'] === 'direta')>Venda direta</option>
                @foreach ($revendas as $revenda)
                    <option value="{{ $revenda->id }}" @selected((string) $filtros['revenda'] === (string) $revenda->id)>{{ $revenda->nome }}</option>
                @endforeach
            </select>

            <select name="sistema" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="">Todos os sistemas</option>
                @foreach ($sistemas as $sistema)
                    <option value="{{ $sistema->id }}" @selected((string) $filtros['sistema'] === (string) $sistema->id)>{{ $sistema->nome }}</option>
                @endforeach
            </select>

            <select name="status" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="">Todos</option>
                <option value="ativo" @selected($filtros['status'] === 'ativo')>Ativos</option>
                <option value="inativo" @selected($filtros['status'] === 'inativo')>Inativos</option>
            </select>

            <button type="submit"
                    class="h-[34px] px-3 rounded-control border border-btn-line text-ink-dim
                           text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
                Filtrar
            </button>

            <span class="ml-auto flex items-center gap-1.5 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                <span class="inline-block h-3 w-[2px]" style="background: rgb(var(--warn))"></span>
                cobrança em atraso
            </span>
        </form>

        <x-tabela min="1060px">
            <thead>
                <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                    <th class="px-4 py-2.5 font-semibold">Cliente</th>
                    <th class="px-4 py-2.5 font-semibold">Revenda / praça</th>
                    <th class="px-4 py-2.5 font-semibold">Sistemas</th>
                    <th class="px-4 py-2.5 font-semibold">Cobrança</th>
                    <th class="px-4 py-2.5 font-semibold">Pagamento</th>
                    <th class="px-4 py-2.5 font-semibold">Status</th>
                    <th class="px-4 py-2.5 font-semibold text-right">Ações</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($clientes as $cliente)
                    @php
                        $pagamento = $pagamentos[$cliente->id] ?? ['estado' => 'sem_cobranca', 'dias' => 0];
                        $emContrato = $cliente->tipo_cliente === 'CONTRATO';
                    @endphp

                    <tr class="border-b border-rule hover:bg-chip transition {{ $cliente->ativo ? '' : 'opacity-[0.62]' }}"
                        @if ($pagamento['estado'] === 'atrasado')
                            style="border-left: 2px solid rgb(var(--warn)); background: rgb(var(--warn) / 0.05)"
                        @endif>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <span class="h-8 w-8 shrink-0 rounded-ctl bg-brand/15 text-brand-text
                                             flex items-center justify-center font-display text-[12.5px] font-semibold">
                                    {{ Str::of($cliente->nome_exibicao)->substr(0, 2)->upper() }}
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-[13.5px] font-medium text-ink truncate">{{ $cliente->nome_exibicao }}</span>
                                    <span class="block font-mono text-[11.5px] text-ink-faint truncate">{{ $cliente->cpf_cnpj ?: 'sem documento' }}</span>
                                </span>
                            </div>
                        </td>

                        <td class="px-4 py-3">
                            @if ($cliente->revenda)
                                <span class="block text-[13px] text-ink-dim truncate">{{ $cliente->revenda->nome }}</span>
                            @else
                                {{-- Venda direta é da casa: merece a cor da marca, não o cinza de "vazio". --}}
                                <span class="block text-[13px] text-brand-text truncate">Venda direta</span>
                            @endif
                            <span class="block font-mono text-[11px] uppercase tracking-caps text-ink-faint truncate">
                                {{ $cliente->cidade ? $cliente->cidade.'/'.$cliente->uf : 'sem praça' }}
                            </span>
                        </td>

                        <td class="px-4 py-3">
                            @php $ativos = $cliente->sistemas->where('pivot.ativo', true); @endphp
                            @if ($ativos->isEmpty())
                                <span class="text-[12.5px] text-ink-faint">nenhum</span>
                            @else
                                <div class="flex flex-wrap items-center gap-1">
                                    @foreach ($ativos->take(3) as $sistema)
                                        <x-badge>{{ $sistema->nome }}</x-badge>
                                    @endforeach
                                    @if ($ativos->count() > 3)
                                        <x-badge :title="$ativos->pluck('nome')->implode(', ')">+{{ $ativos->count() - 3 }}</x-badge>
                                    @endif
                                </div>
                                @foreach ($ativos->take(1) as $sistema)
                                    @if ($sistema->pivot->licenca_status)
                                        @php
                                            $fim = $sistema->pivot->licenca_fim_em ? \Carbon\Carbon::parse($sistema->pivot->licenca_fim_em)->endOfDay() : null;
                                            $hoje = \Carbon\Carbon::now()->endOfDay();
                                            $bloqueada = (bool) $sistema->pivot->bloqueia_acesso;
                                            $vencida = $fim && $fim->lt($hoje);
                                            $vencendo = $fim && ! $vencida && $fim->lte($hoje->copy()->addDays(15));
                                            $tom = $bloqueada || $vencida ? 'critico' : ($vencendo ? 'atencao' : 'bom');
                                        @endphp
                                        <div class="mt-1 flex flex-wrap items-center gap-1">
                                            <x-badge :tom="$tom" ponto>
                                                {{ $bloqueada ? 'bloqueada' : ($vencida ? 'vencida' : ($vencendo ? 'vencendo' : 'ativa')) }}
                                            </x-badge>
                                            <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint truncate">
                                                {{ $sistema->nome }} · {{ $sistema->pivot->plano ?? '—' }}
                                                @if ($fim)
                                                    · até {{ $fim->format('d/m/Y') }}
                                                @endif
                                            </span>
                                        </div>
                                    @endif
                                @endforeach
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            <span class="block font-mono text-[13px] text-ink whitespace-nowrap">
                                R$ {{ number_format($cliente->valor_mensal ?? 0, 2, ',', '.') }}
                            </span>
                            <span class="block font-mono text-[11px] uppercase tracking-caps text-ink-faint whitespace-nowrap">
                                {{ $emContrato ? 'contrato · dia '.($cliente->dia_vencimento ?: '—') : 'avulso' }}
                            </span>
                        </td>

                        <td class="px-4 py-3">
                            @switch ($pagamento['estado'])
                                @case('atrasado')
                                    <x-badge tom="critico" ponto>Atrasado {{ $pagamento['dias'] }}d</x-badge>
                                    @break
                                @case('em_dia')
                                    <x-badge tom="bom" ponto>Em dia</x-badge>
                                    @break
                                @default
                                    <x-badge>Sem cobrança</x-badge>
                            @endswitch
                        </td>

                        <td class="px-4 py-3">
                            <x-badge :tom="$cliente->ativo ? 'bom' : 'neutro'" ponto>
                                {{ $cliente->ativo ? 'Ativo' : 'Inativo' }}
                            </x-badge>
                        </td>

                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <x-acao-tabela icone="paperclip" titulo="Cobranças do cliente"
                                               :href="route('cobrancas.index', ['cliente' => $cliente->id])" />
                                <x-acao-tabela icone="pencil" titulo="Editar cliente"
                                               :href="route('clientes.edit', $cliente)" />
                                <x-confirmar :action="route('clientes.destroy', $cliente)" method="DELETE"
                                             icone="trash" destrutivo confirmar="Remover"
                                             :titulo="'Remover '.$cliente->nome_exibicao.'?'"
                                             mensagem="O cliente sai da lista. As cobranças já emitidas para ele continuam no financeiro." />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-[13px] text-ink-mute">
                            Nenhum cliente encontrado com esse recorte.
                        </td>
                    </tr>
                @endforelse
            </tbody>

            @if ($clientes->isNotEmpty())
                <tfoot>
                    <x-linha-total>
                        <td>Nesta página</td>
                        <td>{{ $totais['contratos'] }} contratos · {{ $totais['avulsos'] }} avulsos</td>
                        <td></td>
                        <td>R$ {{ number_format($totais['mensal'], 2, ',', '.') }}</td>
                        <td>{{ $totais['atrasados'] }} em atraso</td>
                        <td></td>
                        <td></td>
                    </x-linha-total>
                </tfoot>
            @endif

            <x-slot name="rodape">
                <span>{{ $clientes->count() }} de {{ $clientes->total() }} clientes</span>
                <span>· página {{ $clientes->currentPage() }} de {{ $clientes->lastPage() }}</span>
            </x-slot>
        </x-tabela>

        @if ($clientes->hasPages())
            <div>{{ $clientes->links() }}</div>
        @endif
    </div>

    <x-modal name="novo-cliente" maxWidth="2xl">
        <form method="POST" action="{{ route('clientes.store') }}" class="p-5">
            <h2 class="font-display text-[15.5px] font-semibold text-ink mb-4">Novo cliente</h2>
            @include('clientes._form', ['emModal' => true])
        </form>
    </x-modal>
</x-app-layout>
