<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-ink leading-tight">Produtos</h2>
        </div>
    </x-slot>

    <div class="py-12" x-data="{ modo: localStorage.getItem('produtos-modo') || 'card' }" x-effect="localStorage.setItem('produtos-modo', modo)">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="p-4 bg-status-good/10 text-status-good rounded-md text-sm">{{ session('status') }}</div>
            @endif

            <div class="flex items-center justify-between">
                <div class="bg-panel border border-white/5 shadow-panel rounded-xl px-6 py-4 inline-block">
                    <p class="text-xs text-ink-mute uppercase tracking-wide">MRR total (todos os produtos)</p>
                    <p class="text-2xl font-display font-semibold text-ink">R$ {{ number_format($mrrTotal, 2, ',', '.') }}</p>
                </div>

                <div class="flex items-center gap-1 bg-panel border border-white/5 rounded-lg p-1">
                    <button type="button" @click="modo = 'card'" title="Cartões" class="p-1.5 rounded-md transition" :class="modo === 'card' ? 'bg-brand/15 text-brand-dim' : 'text-ink-mute hover:text-ink'">
                        <span class="block h-4 w-4"><x-nav-icon name="view-grid" /></span>
                    </button>
                    <button type="button" @click="modo = 'lista'" title="Lista" class="p-1.5 rounded-md transition" :class="modo === 'lista' ? 'bg-brand/15 text-brand-dim' : 'text-ink-mute hover:text-ink'">
                        <span class="block h-4 w-4"><x-nav-icon name="view-list" /></span>
                    </button>
                </div>
            </div>

            {{-- Modo cartões --}}
            <div x-show="modo === 'card'" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach ($produtos as $p)
                    @php $sistema = $p['sistema']; @endphp
                    <div x-data="{ editando: false }" class="bg-panel border border-white/5 shadow-panel rounded-xl p-6">
                        <div class="flex items-start justify-between mb-4">
                            <div>
                                <h3 class="font-display font-semibold text-ink text-lg">{{ $sistema->nome }}</h3>
                                <span class="px-2 py-0.5 text-xs rounded-full {{ $sistema->categoria === 'crm' ? 'bg-brand/15 text-brand-dim' : 'bg-white/5 text-ink-dim' }}">
                                    {{ strtoupper($sistema->categoria) }}
                                </span>
                            </div>
                            <span class="px-2 py-1 text-xs rounded-full {{ $sistema->ativo ? 'bg-status-good/15 text-status-good' : 'bg-white/5 text-ink-mute' }}">
                                {{ $sistema->ativo ? 'Ativo' : 'Inativo' }}
                            </span>
                        </div>

                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <p class="text-[10px] text-ink-mute uppercase">MRR</p>
                                <p class="text-lg font-semibold text-ink">R$ {{ number_format($p['mrr'], 0, ',', '.') }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] text-ink-mute uppercase">ARR</p>
                                <p class="text-lg font-semibold text-ink">R$ {{ number_format($p['arr'], 0, ',', '.') }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] text-ink-mute uppercase">Clientes ativos</p>
                                <p class="text-lg font-semibold text-ink">{{ $p['clientes_ativos'] }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] text-ink-mute uppercase">Ticket médio</p>
                                <p class="text-lg font-semibold text-ink">R$ {{ number_format($p['ticket_medio'], 2, ',', '.') }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] text-ink-mute uppercase">Cancelados (acum.)</p>
                                <p class="text-lg font-semibold text-ink">{{ $p['clientes_cancelados'] }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] text-ink-mute uppercase">% cancelamento</p>
                                <p class="text-lg font-semibold {{ $p['taxa_cancelamento'] > 10 ? 'text-status-critical' : 'text-ink' }}">{{ number_format($p['taxa_cancelamento'], 1, ',', '.') }}%</p>
                            </div>
                        </div>

                        <div class="text-xs text-ink-mute space-y-1 mb-4">
                            <p><span class="text-ink-dim">Versão:</span> {{ $sistema->versao ?? '—' }}</p>
                            <p><span class="text-ink-dim">Responsável:</span> {{ $sistema->responsavel ?? '—' }}</p>
                            @if ($sistema->roadmap)
                                <p class="line-clamp-2"><span class="text-ink-dim">Roadmap:</span> {{ $sistema->roadmap }}</p>
                            @endif
                        </div>

                        <div class="flex items-center gap-3 text-xs">
                            <a href="{{ route('sistemas.edit', $sistema) }}" class="text-brand hover:text-brand-bright">Configurar preços</a>
                            <button @click="editando = !editando" class="text-brand hover:text-brand-bright" x-text="editando ? 'Fechar' : 'Editar gestão'"></button>
                        </div>

                        <form x-show="editando" x-cloak action="{{ route('produtos.update', $sistema) }}" method="POST" class="mt-4 space-y-3 border-t border-white/5 pt-4">
                            @csrf @method('PUT')
                            <div>
                                <x-input-label value="Versão" />
                                <x-text-input name="versao" type="text" class="mt-1 block w-full text-sm" value="{{ $sistema->versao }}" />
                            </div>
                            <div>
                                <x-input-label value="Responsável" />
                                <x-text-input name="responsavel" type="text" class="mt-1 block w-full text-sm" value="{{ $sistema->responsavel }}" />
                            </div>
                            <div>
                                <x-input-label value="Roadmap / próximos passos" />
                                <textarea name="roadmap" rows="3" class="mt-1 block w-full border-white/10 rounded-md shadow-sm text-sm bg-panel-raised text-ink">{{ $sistema->roadmap }}</textarea>
                            </div>
                            <x-primary-button>Salvar</x-primary-button>
                        </form>
                    </div>
                @endforeach
            </div>

            {{-- Modo lista --}}
            <div x-show="modo === 'lista'" x-cloak class="bg-panel overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-white/5">
                    <thead class="bg-panel-raised">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-ink-dim uppercase">Sistema</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-ink-dim uppercase">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-ink-dim uppercase">MRR</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-ink-dim uppercase">ARR</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-ink-dim uppercase">Clientes ativos</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-ink-dim uppercase">Ticket médio</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-ink-dim uppercase">% cancel.</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @foreach ($produtos as $p)
                            @php $sistema = $p['sistema']; @endphp
                            <tr>
                                <td class="px-4 py-3 text-sm text-ink font-medium">
                                    {{ $sistema->nome }}
                                    <span class="ml-1 px-1.5 py-0.5 text-[10px] rounded-full {{ $sistema->categoria === 'crm' ? 'bg-brand/10 text-brand-dim' : 'bg-white/5 text-ink-mute' }}">{{ strtoupper($sistema->categoria) }}</span>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <span class="px-2 py-1 text-xs rounded-full {{ $sistema->ativo ? 'bg-status-good/15 text-status-good' : 'bg-white/5 text-ink-mute' }}">
                                        {{ $sistema->ativo ? 'Ativo' : 'Inativo' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-right text-ink">R$ {{ number_format($p['mrr'], 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-sm text-right text-ink-dim">R$ {{ number_format($p['arr'], 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-sm text-right text-ink-dim">{{ $p['clientes_ativos'] }}</td>
                                <td class="px-4 py-3 text-sm text-right text-ink-dim">R$ {{ number_format($p['ticket_medio'], 2, ',', '.') }}</td>
                                <td class="px-4 py-3 text-sm text-right {{ $p['taxa_cancelamento'] > 10 ? 'text-status-critical' : 'text-ink-dim' }}">{{ number_format($p['taxa_cancelamento'], 1, ',', '.') }}%</td>
                                <td class="px-4 py-3 text-right text-sm">
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
