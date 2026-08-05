<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-ink leading-tight">Funil de Vendas</h2>
            <button x-data @click="$dispatch('open-modal', 'novo-lead')" class="inline-flex items-center px-4 py-2 bg-brand border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-bright">
                + Novo lead
            </button>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="p-4 bg-status-good/10 text-status-good rounded-md text-sm">{{ session('status') }}</div>
            @endif

            {{-- KPIs --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-4">
                    <p class="text-[10px] text-ink-mute uppercase">Taxa de conversão</p>
                    <p class="text-xl font-display font-semibold text-ink">{{ number_format($kpis['taxa_conversao'], 1, ',', '.') }}%</p>
                </div>
                <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-4">
                    <p class="text-[10px] text-ink-mute uppercase">Pipeline (aberto)</p>
                    <p class="text-xl font-display font-semibold text-ink">R$ {{ number_format($kpis['pipeline_valor'], 0, ',', '.') }}</p>
                </div>
                <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-4">
                    <p class="text-[10px] text-ink-mute uppercase">Ticket médio (fechados)</p>
                    <p class="text-xl font-display font-semibold text-ink">R$ {{ number_format($kpis['ticket_medio'], 0, ',', '.') }}</p>
                </div>
                <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-4">
                    <p class="text-[10px] text-ink-mute uppercase">Abertos / Perdidos</p>
                    <p class="text-xl font-display font-semibold text-ink">{{ $kpis['abertos'] }} <span class="text-ink-mute text-sm">/ {{ $kpis['perdidos'] }}</span></p>
                </div>
            </div>

            {{-- Kanban --}}
            <div class="flex gap-4 overflow-x-auto pb-4 items-start">
                @foreach (App\Models\Lead::ESTAGIOS as $key => $label)
                    @php $cards = $colunas[$key]; @endphp
                    <div class="w-72 shrink-0 bg-white/[0.02] border border-white/5 rounded-xl flex flex-col max-h-[calc(100vh-20rem)]">
                        <div class="flex items-center justify-between px-3 py-3 border-b border-white/5">
                            <div class="flex items-center gap-2 min-w-0">
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-ink-dim truncate">{{ $label }}</h3>
                                <span class="shrink-0 h-5 min-w-5 px-1.5 rounded-full bg-panel-raised text-ink-mute text-[11px] flex items-center justify-center">{{ $cards->count() }}</span>
                            </div>
                            @if ($cards->sum('valor_estimado') > 0)
                                <span class="shrink-0 text-[11px] text-ink-mute">R$ {{ number_format($cards->sum('valor_estimado'), 0, ',', '.') }}</span>
                            @endif
                        </div>

                        <div class="flex-1 overflow-y-auto p-2 space-y-2">
                            @forelse ($cards as $lead)
                                @php $temp = $lead->temperatura(); @endphp
                                <div x-data="{ movendo: false, novoEstagio: '{{ $key }}' }" class="bg-panel-raised border border-white/5 rounded-lg p-3 text-sm shadow-panel hover:border-brand/20 transition">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="text-ink font-medium truncate">{{ $lead->nome }}</p>
                                        @if ($temp)
                                            <span class="shrink-0 h-2 w-2 rounded-full mt-1.5 {{ ['quente' => 'bg-status-good', 'esfriando' => 'bg-status-warning', 'frio' => 'bg-status-critical'][$temp] }}" title="{{ ucfirst($temp) }} · {{ $lead->diasNoEstagio() }} dias no estágio"></span>
                                        @endif
                                    </div>
                                    <p class="text-xs text-ink-mute mt-0.5">{{ \App\Models\Lead::TIPOS_INTERESSE[$lead->tipo_interesse] }} @if($lead->origem) · {{ $lead->origem }} @endif</p>
                                    @if ($lead->valor_estimado)
                                        <p class="text-xs text-brand-dim font-medium mt-1">R$ {{ number_format($lead->valor_estimado, 0, ',', '.') }}</p>
                                    @endif
                                    @if ($lead->revenda)
                                        <p class="text-[10px] text-ink-mute mt-0.5">{{ $lead->revenda->nome }}</p>
                                    @endif

                                    <div class="mt-2 pt-2 border-t border-white/5">
                                        <button @click="movendo = !movendo" class="text-[11px] text-brand hover:text-brand-bright">Mover ▾</button>

                                        <form x-show="movendo" x-cloak action="{{ route('leads.mover', $lead) }}" method="POST" class="mt-2 space-y-2">
                                            @csrf
                                            <select name="estagio" x-model="novoEstagio" class="w-full text-xs border-white/10 rounded-md shadow-sm bg-panel-raised text-ink">
                                                @foreach (App\Models\Lead::ESTAGIOS as $ek => $el)
                                                    <option value="{{ $ek }}">{{ $el }}</option>
                                                @endforeach
                                            </select>
                                            <template x-if="novoEstagio === 'perdido'">
                                                <select name="motivo_perda" class="w-full text-xs border-white/10 rounded-md shadow-sm bg-panel-raised text-ink" required>
                                                    <option value="">Motivo da perda...</option>
                                                    @foreach (App\Models\Lead::MOTIVOS_PERDA as $mk => $ml)
                                                        <option value="{{ $mk }}">{{ $ml }}</option>
                                                    @endforeach
                                                </select>
                                            </template>
                                            <button type="submit" class="w-full text-[11px] bg-brand text-white rounded-md py-1 hover:bg-brand-bright">Confirmar</button>
                                        </form>
                                    </div>
                                </div>
                            @empty
                                <div class="flex flex-col items-center justify-center text-center gap-2 py-8 px-2 border border-dashed border-white/10 rounded-lg">
                                    <p class="text-xs text-ink-mute">Nenhum lead nesta etapa</p>
                                    @if ($key === 'lead')
                                        <button x-data @click="$dispatch('open-modal', 'novo-lead')" class="text-[11px] font-semibold text-brand-dim hover:text-brand-bright">+ Adicionar lead</button>
                                    @endif
                                </div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Modal: novo lead --}}
    <x-modal name="novo-lead" maxWidth="lg">
        <form action="{{ route('leads.store') }}" method="POST" class="p-6 space-y-4">
            @csrf
            <h3 class="font-display font-semibold text-ink text-lg">Novo lead</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <x-input-label for="nome" value="Nome / Empresa" />
                    <x-text-input id="nome" name="nome" type="text" class="mt-1 block w-full" required />
                </div>
                <div>
                    <x-input-label for="email" value="E-mail" />
                    <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="telefone" value="Telefone" />
                    <x-text-input id="telefone" name="telefone" type="text" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="tipo_interesse" value="Interesse" />
                    <select id="tipo_interesse" name="tipo_interesse" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                        @foreach (\App\Models\Lead::TIPOS_INTERESSE as $tk => $tl)
                            <option value="{{ $tk }}">{{ $tl }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="sistema_id" value="Sistema (se for SaaS)" />
                    <select id="sistema_id" name="sistema_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                        <option value="">—</option>
                        @foreach ($sistemas as $sistema)
                            <option value="{{ $sistema->id }}">{{ $sistema->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="origem" value="Origem" />
                    <select id="origem" name="origem" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                        <option value="">—</option>
                        @foreach (\App\Models\Lead::ORIGENS as $origem)
                            <option value="{{ $origem }}">{{ $origem }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="valor_estimado" value="Valor estimado (R$)" />
                    <x-text-input id="valor_estimado" name="valor_estimado" type="number" step="0.01" min="0" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="revenda_id" value="Revenda (se aplicável)" />
                    <select id="revenda_id" name="revenda_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                        <option value="">— Venda direta —</option>
                        @foreach ($revendas as $revenda)
                            <option value="{{ $revenda->id }}">{{ $revenda->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="observacoes" value="Observações" />
                    <textarea id="observacoes" name="observacoes" rows="2" class="mt-1 block w-full border-white/10 rounded-md shadow-sm bg-panel-raised text-ink"></textarea>
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button x-on:click="$dispatch('close')">Cancelar</x-secondary-button>
                <x-primary-button>Salvar</x-primary-button>
            </div>
        </form>
    </x-modal>
</x-app-layout>
