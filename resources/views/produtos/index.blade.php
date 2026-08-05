<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-ink leading-tight">Produtos</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="p-4 bg-status-good/10 text-status-good rounded-md text-sm">{{ session('status') }}</div>
            @endif

            <div class="bg-panel border border-white/5 shadow-panel rounded-xl px-6 py-4 inline-block">
                <p class="text-xs text-ink-mute uppercase tracking-wide">MRR total (todos os produtos)</p>
                <p class="text-2xl font-display font-semibold text-ink">R$ {{ number_format($mrrTotal, 2, ',', '.') }}</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
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
        </div>
    </div>
</x-app-layout>
