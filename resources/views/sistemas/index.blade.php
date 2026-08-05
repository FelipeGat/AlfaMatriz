<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-ink leading-tight">Sistemas &amp; preço de atacado</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 p-4 bg-status-good/10 text-status-good rounded-md text-sm">{{ session('status') }}</div>
            @endif

            <div class="bg-panel overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-white/5">
                    <thead class="bg-panel-raised">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-ink-dim uppercase">Sistema</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-ink-dim uppercase">Categoria</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-ink-dim uppercase">Unidade de cobrança</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-ink-dim uppercase">Clientes vinculados</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-ink-dim uppercase">Atacado (Alfa → Revenda)</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-ink-dim uppercase">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-panel divide-y divide-white/5">
                        @foreach ($sistemas as $sistema)
                            <tr>
                                <td class="px-6 py-4 text-sm font-medium text-ink">{{ $sistema->nome }}</td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="px-2 py-1 text-xs rounded-full {{ $sistema->categoria === 'crm' ? 'bg-brand/15 text-brand-dim' : 'bg-white/5 text-ink-dim' }}">
                                        {{ strtoupper($sistema->categoria) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-ink-dim">{{ $sistema->unidade_cobranca }}</td>
                                <td class="px-6 py-4 text-sm text-ink-dim">{{ $sistema->clientes_count }}</td>
                                <td class="px-6 py-4 text-sm text-ink-dim">
                                    @if ($sistema->precosAtacado->isEmpty())
                                        — sem preço definido
                                    @elseif ($sistema->precosAtacado->count() === 1 && $sistema->precosAtacado->first()->unidades_inclusas == 0 && $sistema->precosAtacado->first()->valor_excedente_unidade)
                                        R$ {{ number_format($sistema->precosAtacado->first()->valor_excedente_unidade, 2, ',', '.') }} / unidade
                                    @else
                                        {{ $sistema->precosAtacado->pluck('nome')->join(' · ') }}
                                        (R$ {{ number_format($sistema->precosAtacado->min('preco_base'), 0, ',', '.') }}–{{ number_format($sistema->precosAtacado->max('preco_base'), 0, ',', '.') }}/mês)
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="px-2 py-1 text-xs rounded-full {{ $sistema->ativo ? 'bg-status-good/15 text-status-good' : 'bg-white/5 text-ink-mute' }}">
                                        {{ $sistema->ativo ? 'Ativo' : 'Inativo' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right text-sm">
                                    <a href="{{ route('sistemas.edit', $sistema) }}" class="text-brand hover:text-brand-bright">Configurar</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
