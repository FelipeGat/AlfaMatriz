@php
    // Quem já tem cobrança gerada nesta competência: define a situação de cada
    // linha e quantas revendas ainda faltam.
    $geradas = $cobrancasGeradas->pluck('revenda_id')->filter()->all();
    $pendentes = $preview->reject(fn ($g) => in_array($g['revenda']->id, $geradas, true));
    $totalPrevia = $preview->sum('total');
    $clientesConsiderados = $preview->sum(fn ($g) => $g['linhas']->sum('clientes_ativos'));
    $vencimentoPadrao = \Carbon\Carbon::createFromFormat('Y-m', $competencia)->endOfMonth()->addDays(5);
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2 text-[13px]">
            <span class="text-mute">Comercial</span>
            <span class="text-line">/</span>
            <span class="font-medium text-ink">Faturamento</span>
        </div>
    </x-slot>

    <div class="space-y-[18px]">
        @if (session('status'))
            <div class="rounded-control border border-line bg-raised px-4 py-2.5 text-[12.5px] text-ink">{{ session('status') }}</div>
        @endif

        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
            <x-summary-card label="Revendas a faturar" :value="$pendentes->count()" contexto="de {{ $preview->count() }} com clientes ativos" />
            <x-summary-card label="Clientes considerados" :value="$clientesConsiderados" contexto="ativos hoje" />
            <x-summary-card label="Prévia total" :value="'R$ ' . number_format($totalPrevia, 2, ',', '.')" contexto="competência {{ $competencia }}" />
            <x-summary-card label="Vencimento padrão" :value="$vencimentoPadrao->format('d/m/Y')" contexto="fim do mês + 5 dias" />
        </div>

        <x-painel-card>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="min-w-0" style="flex: 1 1 320px;">
                    <form method="GET" class="flex items-center gap-2">
                        <label for="competencia" class="font-mono text-[10px] font-medium uppercase tracking-[.08em] text-mute">Competência</label>
                        <input type="month" id="competencia" name="competencia" value="{{ $competencia }}"
                               onchange="this.form.submit()"
                               class="h-8 rounded-control border-line bg-panel py-0 text-[12.5px] text-ink focus:border-ink focus:ring-0">
                    </form>
                    <p class="mt-2 text-[12.5px] text-dim">
                        A prévia é calculada agora, com os clientes ativos de hoje. Gerar cria uma cobrança
                        consolidada por revenda — quem já tem cobrança nesta competência é ignorado.
                    </p>
                </div>

                <form action="{{ route('faturamento.gerar') }}" method="POST" class="shrink-0">
                    @csrf
                    <input type="hidden" name="competencia" value="{{ $competencia }}">
                    {{-- Sem pendências, o botão fica inerte em vez de sumir: some
                         a ação e a pessoa fica procurando por ela. --}}
                    <button type="submit" @disabled($pendentes->isEmpty())
                            class="inline-flex h-8 items-center rounded-control px-3 text-[12.5px] font-medium transition-opacity
                                   {{ $pendentes->isEmpty() ? 'cursor-default bg-raised text-mute' : 'bg-ink text-bg hover:opacity-90' }}">
                        @if ($pendentes->isEmpty())
                            Nada a gerar
                        @else
                            Gerar faturamento ({{ $pendentes->count() }})
                        @endif
                    </button>
                </form>
            </div>
        </x-painel-card>

        <x-painel-card titulo="Prévia por revenda" :sem-padding="true">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] border-collapse">
                    <thead>
                        <tr class="bg-raised">
                            @foreach (['Revenda' => '', 'Sistemas' => 'w-[220px]', 'Clientes' => 'w-[88px]', 'Prévia' => 'w-[124px] text-right', 'Situação' => 'w-[104px]'] as $titulo => $largura)
                                <th class="{{ $largura }} truncate whitespace-nowrap border-b border-line px-5 py-2.5 text-left font-mono text-[10px] font-medium uppercase tracking-[.08em] text-mute">
                                    {{ $titulo }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($preview as $grupo)
                            @php $jaGerada = in_array($grupo['revenda']->id, $geradas, true); @endphp
                            <tr class="border-b border-line transition-colors last:border-0 hover:bg-raised">
                                <td class="px-5 py-3 text-[13px] text-ink">{{ $grupo['revenda']->nome }}</td>
                                <td class="max-w-[220px] truncate px-5 py-3 text-[12.5px] text-dim">
                                    {{ $grupo['linhas']->pluck('sistema')->join(', ') }}
                                </td>
                                <td class="valor px-5 py-3 text-[12.5px] text-dim">{{ $grupo['linhas']->sum('clientes_ativos') }}</td>
                                <td class="valor px-5 py-3 text-right text-[12.5px] font-medium text-ink">
                                    R$ {{ number_format($grupo['total'], 2, ',', '.') }}
                                </td>
                                <td class="px-5 py-3">
                                    <x-status-pill :tom="$jaGerada ? 'brand' : 'neutro'">
                                        {{ $jaGerada ? 'Gerada' : 'Pendente' }}
                                    </x-status-pill>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-[34px] text-center text-[13px] text-mute">
                                Nenhuma revenda com clientes ativos nesta competência.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-painel-card>

        @if ($cobrancasGeradas->isNotEmpty())
            <x-painel-card titulo="Cobranças já geradas em {{ $competencia }}">
                <ul class="divide-y divide-line">
                    @foreach ($cobrancasGeradas as $cobranca)
                        <li class="flex items-center justify-between gap-3 py-2.5">
                            <span class="truncate text-[13px] text-ink">{{ $cobranca->revenda->nome ?? '—' }}</span>
                            <span class="valor shrink-0 text-[12.5px] font-medium text-ink">R$ {{ number_format($cobranca->valor, 2, ',', '.') }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-painel-card>
        @endif
    </div>
</x-app-layout>
