<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2 text-[14px]">
            <span class="text-mute">Comercial</span>
            <span class="text-line">/</span>
            <span class="font-medium text-ink">Sistemas</span>
        </div>
    </x-slot>

    <div class="space-y-[18px]">

        {{-- Flex com quebra, não grid fixo: em tela estreita o catálogo e o
             detalhe empilham sozinhos. --}}
        <div class="flex flex-wrap gap-[14px]">

            <x-painel-card titulo="Catálogo" style="flex: 1 1 260px;" :sem-padding="true">
                @php $maxClientes = max($sistemas->max('clientes_count') ?? 0, 1); @endphp
                <ul class="px-2 pb-2">
                    @forelse ($sistemas as $sistema)
                        @php $ativo = $selecionado && $sistema->id === $selecionado->id; @endphp
                        <li>
                            <a href="{{ route('sistemas.index', ['sistema' => $sistema->id]) }}"
                               class="block rounded-control px-3 py-2 transition-colors {{ $ativo ? 'bg-raised' : 'hover:bg-nav-hover' }}"
                               @if ($ativo) style="box-shadow: inset 2px 0 0 0 var(--brand);" @endif>
                                <div class="flex items-baseline justify-between gap-2">
                                    <span class="truncate text-[14px] {{ $ativo ? 'font-medium text-ink' : 'text-dim' }}">{{ $sistema->nome }}</span>
                                    <span class="valor shrink-0 text-[11.5px] text-mute">{{ $sistema->clientes_count }}</span>
                                </div>
                                <div class="mt-1.5 h-1 w-full overflow-hidden rounded-[2px] bg-track">
                                    <div class="h-full rounded-[2px] bg-chart" style="width: {{ ($sistema->clientes_count / $maxClientes) * 100 }}%"></div>
                                </div>
                                <p class="mt-1 font-mono text-[10px] uppercase tracking-[.08em] text-mute">{{ $sistema->categoria }}</p>
                            </a>
                        </li>
                    @empty
                        <li class="py-[34px] text-center text-[14px] text-mute">Nenhum sistema cadastrado.</li>
                    @endforelse
                </ul>
            </x-painel-card>

            @if ($selecionado && $detalhe)
                <div class="min-w-0 space-y-[14px]" style="flex: 5 1 420px;">

                    <x-painel-card>
                        {{-- flex-wrap + base no bloco do título: sem isso o badge
                             colide com o botão em telas estreitas. --}}
                        <div class="flex flex-wrap items-center gap-4">
                            <span class="grid h-13 w-13 shrink-0 place-items-center rounded-control border border-line bg-raised text-[20px] font-medium text-ink"
                                  style="height: 52px; width: 52px;">
                                {{ Str::of($selecionado->nome)->substr(0, 1)->upper() }}
                            </span>

                            <div class="min-w-0" style="flex: 1 1 220px;">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="truncate text-[20px] font-semibold text-ink">{{ $selecionado->nome }}</h2>
                                    <x-status-pill :tom="$selecionado->ativo ? 'good' : 'neutro'">
                                        {{ $selecionado->ativo ? 'Ativo' : 'Inativo' }}
                                    </x-status-pill>
                                </div>
                                <p class="mt-1 text-[12.5px] text-mute">
                                    {{ $selecionado->categoria }} ·
                                    {{ $detalhe['top_revendas']->count() + $detalhe['outras_revendas'] }} revenda(s) ·
                                    cobrança por {{ $selecionado->unidade_cobranca }}
                                </p>
                            </div>

                            <a href="{{ route('sistemas.edit', $selecionado) }}"
                               class="shrink-0 rounded-control border border-line px-3 py-1.5 text-[12.5px] text-dim transition-colors hover:text-ink">
                                Editar sistema
                            </a>
                        </div>

                        <div class="mt-5 grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
                            <div>
                                <p class="font-mono text-[10px] font-medium uppercase tracking-[.08em] text-mute">Clientes ativos</p>
                                <p class="valor mt-1 text-[17px] font-medium text-ink">{{ $detalhe['clientes_ativos'] }}</p>
                            </div>
                            <div>
                                <p class="font-mono text-[10px] font-medium uppercase tracking-[.08em] text-mute">MRR atacado</p>
                                <p class="valor mt-1 text-[17px] font-medium text-ink">R$ {{ number_format($detalhe['mrr'], 2, ',', '.') }}</p>
                            </div>
                            <div>
                                <p class="font-mono text-[10px] font-medium uppercase tracking-[.08em] text-mute">Preço médio</p>
                                <p class="valor mt-1 text-[17px] font-medium text-ink">R$ {{ number_format($detalhe['preco_medio'], 2, ',', '.') }}</p>
                            </div>
                            <div>
                                <p class="font-mono text-[10px] font-medium uppercase tracking-[.08em] text-mute">Participação</p>
                                <p class="valor mt-1 text-[17px] font-medium text-ink">{{ number_format($detalhe['participacao'], 1, ',', '.') }}%</p>
                            </div>
                        </div>
                    </x-painel-card>

                    <div class="flex flex-wrap gap-[14px]">
                        <x-painel-card titulo="Preço de atacado por faixa" style="flex: 1 1 320px;">
                            @php $maxPreco = max($detalhe['tiers']->max('preco_base') ?? 0, 1); @endphp
                            <div class="space-y-3">
                                @forelse ($detalhe['tiers'] as $tier)
                                    @php $vigente = $detalhe['tier_vigente'] && $tier->id === $detalhe['tier_vigente']->id; @endphp
                                    <div>
                                        <div class="flex items-baseline justify-between gap-2">
                                            <span class="flex items-center gap-2 truncate text-[12.5px] {{ $vigente ? 'text-ink' : 'text-dim' }}">
                                                {{ $tier->nome }}
                                                @if ($vigente)
                                                    <x-status-pill tom="brand">Faixa atual</x-status-pill>
                                                @endif
                                            </span>
                                            <span class="valor shrink-0 text-[12.5px] font-medium text-ink">R$ {{ number_format($tier->preco_base, 2, ',', '.') }}</span>
                                        </div>
                                        <div class="mt-1.5 h-1 w-full overflow-hidden rounded-[2px] bg-track">
                                            <div class="h-full rounded-[2px] {{ $vigente ? 'bg-ink' : 'bg-track2' }}"
                                                 style="width: {{ ($tier->preco_base / $maxPreco) * 100 }}%"></div>
                                        </div>
                                    </div>
                                @empty
                                    <p class="py-[34px] text-center text-[14px] text-mute">Nenhuma faixa de preço cadastrada.</p>
                                @endforelse
                            </div>
                        </x-painel-card>

                        <x-painel-card titulo="Quem revende" style="flex: 1 1 320px;">
                            <x-slot name="acao">
                                <span class="valor text-mute">{{ $detalhe['top_revendas']->count() + $detalhe['outras_revendas'] }}</span>
                            </x-slot>

                            @php $maxRevenda = max($detalhe['top_revendas']->max('clientes') ?? 0, 1); @endphp
                            <div class="space-y-3">
                                @forelse ($detalhe['top_revendas'] as $item)
                                    <div>
                                        <div class="flex items-baseline justify-between gap-2">
                                            <span class="truncate text-[12.5px] text-ink">{{ $item['nome'] }}</span>
                                            <span class="valor shrink-0 text-[12.5px] text-dim">{{ $item['clientes'] }}</span>
                                        </div>
                                        <div class="mt-1.5 h-1 w-full overflow-hidden rounded-[2px] bg-track">
                                            <div class="h-full rounded-[2px] bg-ink" style="width: {{ ($item['clientes'] / $maxRevenda) * 100 }}%"></div>
                                        </div>
                                    </div>
                                @empty
                                    <p class="py-[34px] text-center text-[14px] text-mute">Nenhuma revenda com clientes ativos.</p>
                                @endforelse
                            </div>

                            {{-- Só as cinco maiores aparecem; o resto vira uma linha.
                                 É o que mantém este card do mesmo tamanho com 50 revendas. --}}
                            @if ($detalhe['outras_revendas'] > 0)
                                <div class="mt-4 flex items-center justify-between border-t border-line pt-3 text-[11.5px]">
                                    <span class="text-mute">
                                        {{ $detalhe['outras_revendas'] }} outra(s) revenda(s) ·
                                        <span class="valor">{{ $detalhe['clientes_em_outras'] }}</span> clientes
                                    </span>
                                    <a href="{{ route('revendas.index') }}" class="text-dim transition-colors hover:text-ink">Ver todas</a>
                                </div>
                            @endif
                        </x-painel-card>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
