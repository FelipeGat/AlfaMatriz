<x-app-layout>
    <x-slot name="titulo">Caixa</x-slot>
    <x-slot name="contexto">{{ $contasFinanceiras->where('ativo', true)->count() }} contas ativas</x-slot>
    <x-slot name="acoes">
        <button type="button" x-data @click="$dispatch('open-modal', 'nova-conta')"
                class="h-[34px] px-3 inline-flex items-center rounded-control bg-brand text-on-brand font-semibold text-[12.5px] hover:bg-brand-bright transition whitespace-nowrap">
            + Nova conta
        </button>
    </x-slot>

    <div class="space-y-4">
        @if (session('status'))
            <div class="rounded-panel border px-4 py-2.5 text-[13px]"
                 style="background: rgb(var(--good) / var(--tint-alpha)); border-color: rgb(var(--good) / 0.25); color: rgb(var(--good))">
                {{ session('status') }}
            </div>
        @endif

        {{-- Saldo consolidado ------------------------------------------------ --}}
        <section class="relative overflow-hidden rounded-panel border border-line bg-card-grad px-4 pt-[18px] pb-4">
            <div class="absolute top-0 left-4 right-4 h-px pointer-events-none"
                 style="background: linear-gradient(90deg, transparent, rgb(var(--brand)), transparent)"></div>

            <p class="text-[11px] uppercase tracking-[0.10em] text-ink-mute">Saldo total consolidado</p>
            <p class="mt-1.5 font-display text-[32px] font-semibold leading-none tracking-[-0.02em] text-ink tabular whitespace-nowrap">
                R$ {{ number_format($saldoTotal, 2, ',', '.') }}
            </p>
            <p class="mt-2 font-mono text-[11px] uppercase tracking-caps text-ink-faint">
                {{ $contasFinanceiras->where('ativo', true)->count() }} contas ativas{{ $folga ? ' · '.$folga : '' }}
            </p>
        </section>

        {{-- Uma conta por card ------------------------------------------------ --}}
        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr))">
            @forelse ($cartoes as $cartao)
                @php $conta = $cartao['conta']; @endphp

                <article class="rounded-panel border border-line bg-card-grad overflow-hidden {{ $conta->ativo ? '' : 'opacity-[0.62]' }}">
                    <div class="p-4">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <span class="h-7 w-7 shrink-0 rounded-tile bg-brand/15 text-brand-text flex items-center justify-center">
                                <span class="h-[14px] w-[14px]"><x-nav-icon name="banknotes" /></span>
                            </span>
                            <span class="min-w-0">
                                <span class="block text-[13.5px] font-medium text-ink truncate">{{ $conta->nome }}</span>
                                <span class="block font-mono text-[10.5px] uppercase tracking-caps text-ink-faint truncate">{{ $conta->tipo }}</span>
                            </span>
                        </div>

                        <p class="mt-3 font-display text-[21px] font-semibold leading-none tabular whitespace-nowrap
                                  {{ $conta->saldo < 0 ? 'text-crit' : 'text-ink' }}">
                            R$ {{ number_format($conta->saldo, 2, ',', '.') }}
                        </p>

                        <div class="mt-3 flex items-end justify-between gap-3">
                            <p class="min-w-0 font-mono text-[11px] text-ink-faint truncate">
                                {{ $cartao['variacao'] ?? 'sem histórico' }} · {{ number_format($cartao['share'] * 100, 0) }}% do caixa
                            </p>
                            <x-sparkline :pontos="$cartao['serie']" :cor="$conta->saldo < 0 ? 'crit' : 'accent'" />
                        </div>
                    </div>

                    <div class="flex items-center gap-3 px-4 py-2.5 bg-head border-t border-line">
                        <a href="{{ route('contas-financeiras.extrato', $conta) }}"
                           class="font-mono text-[10.5px] uppercase tracking-caps text-brand-text hover:underline">Extrato</a>
                        <a href="{{ route('contas-financeiras.edit', $conta) }}"
                           class="font-mono text-[10.5px] uppercase tracking-caps text-ink-mute hover:text-ink transition">Editar</a>
                        <span class="ml-auto font-mono text-[10.5px] uppercase tracking-caps text-ink-faint whitespace-nowrap">
                            {{ $conta->movimentacoes_count }} mov.
                        </span>
                    </div>
                </article>
            @empty
                <x-painel>
                    <p class="text-[13px] text-ink-mute">Nenhuma conta financeira cadastrada.</p>
                </x-painel>
            @endforelse
        </div>

        <div class="grid gap-4 items-start" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr))">
            {{-- Movimentação do mês ------------------------------------------ --}}
            <x-painel titulo="Movimentação de {{ $mes['rotulo'] }}">
                @php $maior = max(abs($mes['entradas']), abs($mes['saidas']), abs($mes['resultado']), 1); @endphp

                @foreach ([
                    ['Entradas', $mes['entradas'], 'good'],
                    ['Saídas', $mes['saidas'], 'chart-out'],
                    ['Resultado', $mes['resultado'], $mes['resultado'] >= 0 ? 'good' : 'crit'],
                ] as [$rotulo, $valor, $token])
                    <div class="py-2 first:pt-0 last:pb-0">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">{{ $rotulo }}</span>
                            <span class="font-mono text-[13px] whitespace-nowrap" style="color: rgb(var(--{{ $token }}))">
                                R$ {{ number_format($valor, 2, ',', '.') }}
                            </span>
                        </div>
                        {{-- Barras na MESMA escala: é o que deixa comparar
                             entrada com saída de relance. --}}
                        <span class="mt-1.5 block h-2 w-full rounded-badge bg-bar-track overflow-hidden">
                            <span class="block h-full rounded-badge" data-barra="{{ Str::slug($rotulo) }}"
                                  style="width: {{ round((abs($valor) / $maior) * 100, 2) }}%; background: rgb(var(--{{ $token }}))"></span>
                        </span>
                    </div>
                @endforeach
            </x-painel>

            {{-- Últimas movimentações ---------------------------------------- --}}
            <x-painel titulo="Últimas movimentações" solto>
                @forelse ($ultimas as $movimentacao)
                    @php $entrada = $movimentacao->tipo === 'entrada'; @endphp
                    <div class="flex items-center gap-3 px-4 py-2.5 border-b border-rule last:border-0">
                        <span class="shrink-0 font-mono text-[11px] uppercase tracking-caps text-ink-faint">
                            {{ \Illuminate\Support\Carbon::parse($movimentacao->data)->format('d/m') }}
                        </span>
                        <span class="h-6 w-[2px] shrink-0 rounded-badge"
                              style="background: rgb(var(--{{ $entrada ? 'good' : 'chart-out' }}))"></span>
                        <span class="min-w-0 flex-1 truncate text-[13px] text-ink-dim">{{ $movimentacao->descricao }}</span>
                        <span class="shrink-0 font-mono text-[12.5px] whitespace-nowrap"
                              style="color: rgb(var(--{{ $entrada ? 'good' : 'chart-out' }}))">
                            {{ $entrada ? '+' : '−' }}{{ number_format($movimentacao->valor, 2, ',', '.') }}
                        </span>
                    </div>
                @empty
                    <p class="px-4 py-6 text-[13px] text-ink-mute">Nenhuma movimentação registrada.</p>
                @endforelse
            </x-painel>
        </div>
    </div>

    <x-modal name="nova-conta" maxWidth="lg">
        <form method="POST" action="{{ route('contas-financeiras.store') }}" class="p-5">
            <h2 class="font-display text-[15.5px] font-semibold text-ink mb-4">Nova conta</h2>
            @include('contas-financeiras._form')
        </form>
    </x-modal>
</x-app-layout>
