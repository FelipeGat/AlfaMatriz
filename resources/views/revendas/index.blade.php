<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2 text-[13px]">
            <span class="text-mute">Comercial</span>
            <span class="text-line">/</span>
            <span class="font-medium text-ink">Revendas</span>
        </div>
    </x-slot>

    <div class="space-y-[18px]">
        @if (session('status'))
            <div class="rounded-control border border-line bg-raised px-4 py-2.5 text-[12.5px] text-ink">{{ session('status') }}</div>
        @endif

        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
            <x-summary-card label="Revendas ativas" :value="$revendasAtivas" contexto="de {{ $revendas->total() }} cadastradas" />
            <x-summary-card label="Clientes na base" :value="$clientesEmRevenda" contexto="atendidos por revenda" />
            <x-summary-card label="MRR de revenda" :value="'R$ ' . number_format($mrrRevendas, 2, ',', '.')" contexto="competência {{ now()->format('m/Y') }}" />
            <x-summary-card label="Ticket médio" :value="'R$ ' . number_format($ticketMedio, 2, ',', '.')" contexto="por revenda ativa" />
        </div>

        <div class="flex justify-end">
            <a href="{{ route('revendas.create') }}"
               class="inline-flex items-center rounded-control bg-ink px-3 py-1.5 text-[12.5px] font-medium text-bg transition-opacity hover:opacity-90">
                Nova revenda
            </a>
        </div>

        <x-painel-card :sem-padding="true">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] border-collapse">
                    <thead>
                        <tr class="bg-raised">
                            @foreach (['Nome' => '', 'CNPJ' => 'w-[150px]', 'Contato' => 'w-[170px]', 'Clientes' => 'w-[88px]', 'Situação' => 'w-[104px]', '' => 'w-[130px]'] as $titulo => $largura)
                                <th class="{{ $largura }} truncate whitespace-nowrap border-b border-line px-5 py-2.5 text-left font-mono text-[10px] font-medium uppercase tracking-[.08em] text-mute">
                                    {{ $titulo }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($revendas as $revenda)
                            <tr class="border-b border-line transition-colors last:border-0 hover:bg-raised">
                                <td class="px-5 py-3 text-[13px] text-ink">{{ $revenda->nome }}</td>
                                <td class="valor px-5 py-3 text-[12.5px] text-dim">{{ $revenda->cnpj ?? '—' }}</td>
                                <td class="px-5 py-3 text-[12.5px] text-dim">{{ $revenda->contato_nome ?? '—' }}</td>
                                <td class="valor px-5 py-3 text-[12.5px] text-ink">{{ $revenda->clientes_count }}</td>
                                <td class="px-5 py-3">
                                    <x-status-pill :tom="$revenda->ativo ? 'good' : 'neutro'">
                                        {{ $revenda->ativo ? 'Ativa' : 'Inativa' }}
                                    </x-status-pill>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-right text-[12.5px]">
                                    <a href="{{ route('revendas.edit', $revenda) }}" class="text-dim transition-colors hover:text-ink">Editar</a>
                                    <form action="{{ route('revendas.destroy', $revenda) }}" method="POST" class="ml-3 inline" onsubmit="return confirm('Remover esta revenda?');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-dim transition-colors hover:text-bad">Remover</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-[34px] text-center text-[13px] text-mute">Nenhuma revenda cadastrada.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($revendas->hasPages())
                <div class="border-t border-line px-5 py-3">{{ $revendas->links() }}</div>
            @endif
        </x-painel-card>
    </div>
</x-app-layout>
