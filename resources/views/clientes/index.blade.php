<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2 text-[13px]">
            <span class="text-mute">Comercial</span>
            <span class="text-line">/</span>
            <span class="font-medium text-ink">Clientes</span>
        </div>
    </x-slot>

    <div class="space-y-[18px]">
        @if (session('status'))
            <div class="rounded-control border border-line bg-raised px-4 py-2.5 text-[12.5px] text-ink">{{ session('status') }}</div>
        @endif

        {{-- Estes cards refletem o RECORTE, não a base inteira: é o que faz o
             número do topo bater com o que a tabela mostra logo abaixo. --}}
        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
            <x-summary-card label="Exibidos" :value="$exibidos" contexto="com os filtros atuais" />
            <x-summary-card label="Ativos" :value="$ativos" tom="good" contexto="no recorte" />
            <x-summary-card label="Inativos" :value="$inativos" contexto="no recorte" />
            <x-summary-card label="Soma dos contratos" :value="'R$ ' . number_format($somaContratos, 2, ',', '.')" contexto="mensal, só ativos" />
        </div>

        <form method="GET" class="flex flex-wrap items-center gap-2">
            <div class="relative min-w-[220px] flex-1">
                <svg class="pointer-events-none absolute left-2.5 top-1/2 h-[13px] w-[13px] -translate-y-1/2 text-mute"
                     fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
                    <circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="M20 20l-3.5-3.5" />
                </svg>
                <input type="text" name="busca" value="{{ request('busca') }}" placeholder="Buscar cliente"
                       class="h-8 w-full rounded-control border-line bg-panel pl-8 text-[12.5px] text-ink placeholder:text-mute focus:border-ink focus:ring-0">
            </div>

            <select name="revenda_id" class="h-8 rounded-control border-line bg-panel py-0 text-[12.5px] text-ink focus:border-ink focus:ring-0">
                <option value="">Todas as revendas</option>
                @foreach ($revendas as $revenda)
                    <option value="{{ $revenda->id }}" {{ (string) request('revenda_id') === (string) $revenda->id ? 'selected' : '' }}>{{ $revenda->nome }}</option>
                @endforeach
            </select>

            {{-- Situação por link: o filtro vive na query string e sobrevive ao
                 recarregar e à paginação. --}}
            <div class="inline-flex rounded-control border border-line bg-raised p-0.5">
                @foreach (['' => 'Todos', 'ativos' => 'Ativos', 'inativos' => 'Inativos'] as $valor => $rotulo)
                    @php $marcado = (string) request('status') === $valor; @endphp
                    <a href="{{ request()->fullUrlWithQuery(['status' => $valor ?: null, 'page' => null]) }}"
                       class="rounded px-2.5 py-1 font-mono text-[11.5px] transition-colors {{ $marcado ? 'border border-line bg-panel text-ink' : 'text-dim hover:text-ink' }}">
                        {{ $rotulo }}
                    </a>
                @endforeach
            </div>

            <button type="submit" class="h-8 rounded-control border border-line px-3 text-[12.5px] text-dim transition-colors hover:text-ink">
                Filtrar
            </button>

            <a href="{{ route('clientes.create') }}"
               class="ml-auto inline-flex h-8 items-center rounded-control bg-ink px-3 text-[12.5px] font-medium text-bg transition-opacity hover:opacity-90">
                Novo cliente
            </a>
        </form>

        <x-painel-card :sem-padding="true">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[820px] border-collapse">
                    <thead>
                        <tr class="bg-raised">
                            @foreach (['Nome' => '', 'Revenda' => 'w-[170px]', 'Sistemas' => 'w-[200px]', 'Contrato' => 'w-[130px]', 'Situação' => 'w-[96px]', '' => 'w-[130px]'] as $titulo => $largura)
                                <th class="{{ $largura }} truncate whitespace-nowrap border-b border-line px-5 py-2.5 text-left font-mono text-[10px] font-medium uppercase tracking-[.08em] text-mute">
                                    {{ $titulo }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($clientes as $cliente)
                            <tr class="border-b border-line transition-colors last:border-0 hover:bg-raised">
                                <td class="px-5 py-3 text-[13px] text-ink">{{ $cliente->nome }}</td>
                                <td class="px-5 py-3 text-[12.5px] text-dim">{{ $cliente->revenda->nome ?? 'Venda direta' }}</td>
                                <td class="max-w-[200px] truncate px-5 py-3 text-[12.5px] text-dim">{{ $cliente->sistemas->pluck('nome')->join(', ') ?: '—' }}</td>
                                <td class="px-5 py-3 text-[12.5px] text-dim">
                                    @if ($cliente->tipo_cliente === 'CONTRATO')
                                        <span class="valor text-ink">R$ {{ number_format($cliente->valor_mensal, 2, ',', '.') }}</span>
                                    @else
                                        Avulso
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    <x-status-pill :tom="$cliente->ativo ? 'good' : 'neutro'">
                                        {{ $cliente->ativo ? 'Ativo' : 'Inativo' }}
                                    </x-status-pill>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-right text-[12.5px]">
                                    <a href="{{ route('clientes.edit', $cliente) }}" class="text-dim transition-colors hover:text-ink">Editar</a>
                                    <form action="{{ route('clientes.destroy', $cliente) }}" method="POST" class="ml-3 inline" onsubmit="return confirm('Remover este cliente?');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-dim transition-colors hover:text-bad">Remover</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-[34px] text-center text-[13px] text-mute">Nenhum cliente encontrado com estes filtros.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($clientes->hasPages())
                <div class="border-t border-line px-5 py-3">{{ $clientes->links() }}</div>
            @endif
        </x-painel-card>
    </div>
</x-app-layout>
