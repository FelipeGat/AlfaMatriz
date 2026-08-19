<x-app-layout>
    <x-slot name="titulo">Faturamento das revendas</x-slot>
    <x-slot name="contexto">competência {{ $ciclo['rotulo'] }}</x-slot>

    {{--
        Esta tela NÃO tem largura máxima de propósito: com `max-width` o bloco
        inteiro desliza ao recolher o menu, em vez de refluir como as demais.
    --}}
    <div x-data="{ selecionadas: [] }" class="space-y-4">
        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif

        {{-- Barra do ciclo --------------------------------------------------- --}}
        <section class="rounded-panel border border-line bg-card-grad">
            <div class="flex flex-wrap items-center gap-4 p-4">
                <form method="GET" class="flex items-center gap-2.5 shrink-0">
                    <input type="month" name="competencia" value="{{ $competencia }}"
                           onchange="this.form.submit()"
                           class="h-9 py-0 text-[13px] rounded-control bg-input border-line text-ink">
                    <x-badge :tom="$ciclo['gerado'] ? 'bom' : 'atencao'" ponto>
                        {{ $ciclo['gerado'] ? 'gerado' : 'prévia · nada gerado' }}
                    </x-badge>
                </form>

                <span class="hidden md:block self-stretch w-px bg-line"></span>

                <dl class="flex flex-wrap items-center gap-x-6 gap-y-2 min-w-0 flex-1">
                    @php
                        $resumo = [
                            ['Total do ciclo', 'R$ '.number_format($ciclo['total'], 2, ',', '.'), null],
                            ['Revendas', (string) $ciclo['revendas'], null],
                            ['Linhas', (string) $ciclo['linhas'], null],
                            ['Pendências', (string) $ciclo['pendencias'], $ciclo['pendencias'] > 0 ? 'warn' : null],
                        ];
                    @endphp

                    @foreach ($resumo as [$rotulo, $valor, $token])
                        <div class="min-w-0">
                            <dt class="font-mono text-[10px] uppercase tracking-caps text-ink-faint whitespace-nowrap">{{ $rotulo }}</dt>
                            <dd class="font-display text-[19px] font-semibold leading-tight tabular whitespace-nowrap {{ $token ? '' : 'text-ink' }}"
                                @if ($token) style="color: rgb(var(--{{ $token }}))" @endif>{{ $valor }}</dd>
                        </div>
                    @endforeach
                </dl>

                <div class="flex items-center gap-2 shrink-0">
                    <a href="{{ route('faturamento.index', ['competencia' => $competencia, 'exportar' => 'csv']) }}"
                       class="h-9 px-3 inline-flex items-center rounded-control border border-btn-line
                              text-[12.5px] font-semibold text-ink-dim hover:text-brand hover:border-brand transition">
                        Exportar prévia
                    </a>

                    <form method="POST" action="{{ route('faturamento.gerar') }}">
                        @csrf
                        <input type="hidden" name="competencia" value="{{ $competencia }}">
                        {{-- A contagem vai no rótulo: "gerar" genérico não deixa
                             ninguém conferir o que está prestes a acontecer. --}}
                        <button type="submit" @disabled($ciclo['revendas'] === 0)
                                class="h-9 px-3 rounded-control bg-brand text-on-brand text-[12.5px] font-semibold
                                       hover:bg-brand-bright transition disabled:opacity-50 whitespace-nowrap">
                            Gerar {{ $ciclo['revendas'] }} {{ $ciclo['revendas'] === 1 ? 'cobrança' : 'cobranças' }}
                        </button>
                    </form>
                </div>
            </div>

            <p class="px-4 pb-3 text-[11.5px] text-ink-faint">
                Calculado em tempo real com os clientes ativos de hoje. Gerar cria uma cobrança consolidada por
                revenda com vencimento em {{ $ciclo['vencimento']->format('d/m/Y') }}.
            </p>
        </section>

        {{-- Pendências ------------------------------------------------------- --}}
        @if ($pendencias->isNotEmpty())
            <section class="rounded-panel border p-4 flex items-start gap-3"
                     style="background: var(--warn-tint); border-color: var(--warn-line); border-left: 2px solid rgb(var(--warn))">
                <span class="h-7 w-7 shrink-0 rounded-tile flex items-center justify-center"
                      style="background: rgb(var(--warn) / var(--tint-alpha)); color: rgb(var(--warn))">
                    <span class="h-[15px] w-[15px]"><x-nav-icon name="alert-triangle" /></span>
                </span>

                <div class="min-w-0 flex-1">
                    <p class="text-[13.5px] font-medium text-ink">
                        {{ $pendencias->count() }}
                        {{ $pendencias->count() === 1 ? 'linha fora do faturamento deste ciclo' : 'linhas fora do faturamento deste ciclo' }}
                    </p>
                    @foreach ($pendencias as $pendencia)
                        <p class="text-[12px] text-ink-mute">
                            {{ $pendencia['sistema'] }} não tem tier de atacado configurado —
                            {{ $pendencia['unidades'] }} {{ Str::plural($pendencia['unidade_cobranca'], $pendencia['unidades']) }}
                            da {{ $pendencia['revenda'] }} não serão cobradas.
                        </p>
                    @endforeach
                </div>

                <a href="{{ route('produtos.index') }}"
                   class="shrink-0 h-8 px-3 inline-flex items-center rounded-control border border-btn-line
                          text-[12px] font-semibold text-ink-dim hover:text-brand hover:border-brand transition">
                    Definir tier
                </a>
            </section>
        @endif

        {{-- Um painel por revenda -------------------------------------------- --}}
        @forelse ($preview as $painel)
            @php $revenda = $painel['revenda']; @endphp

            <section class="rounded-panel border border-line bg-subtle overflow-hidden">
                <header class="flex items-center gap-3 px-4 py-3 bg-head border-b border-line">
                    <input type="checkbox" value="{{ $revenda->id }}" x-model="selecionadas"
                           class="shrink-0 rounded-badge" aria-label="Incluir {{ $revenda->nome }} na geração">

                    <span class="h-8 w-8 shrink-0 rounded-ctl bg-brand/15 text-brand-text
                                 flex items-center justify-center font-display text-[12.5px] font-semibold">
                        {{ Str::of($revenda->nome)->substr(0, 2)->upper() }}
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block font-display text-[15px] font-semibold text-ink truncate">{{ $revenda->nome }}</span>
                        <span class="block font-mono text-[10.5px] uppercase tracking-caps text-ink-faint truncate">
                            {{ $painel['linhas']->count() }} sistemas · {{ number_format($painel['unidades'], 0, ',', '.') }} unidades ativas
                        </span>
                    </span>

                    <span class="shrink-0 text-right">
                        <span class="block font-display text-[19px] font-semibold leading-none text-ink tabular whitespace-nowrap">
                            R$ {{ number_format($painel['total'], 2, ',', '.') }}
                        </span>
                        <span class="block font-mono text-[10px] uppercase tracking-caps text-ink-faint">por mês</span>
                    </span>
                </header>

                <x-tabela min="720px" class="rounded-none border-0">
                    <thead>
                        <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                            <th class="px-4 py-2 font-semibold">Sistema</th>
                            <th class="px-4 py-2 font-semibold">Tier aplicado</th>
                            <th class="px-4 py-2 font-semibold">Unidades ativas</th>
                            <th class="px-4 py-2 font-semibold">Cálculo</th>
                            <th class="px-4 py-2 font-semibold text-right">Valor</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($painel['linhas'] as $linha)
                            <tr class="border-b border-rule last:border-0"
                                @if ($linha['sem_tier'])
                                    style="border-left: 2px solid rgb(var(--warn)); background: rgb(var(--warn) / 0.05)"
                                @endif>
                                <td class="px-4 py-2.5 text-[13.5px] text-ink">{{ $linha['sistema'] }}</td>

                                <td class="px-4 py-2.5">
                                    @switch ($linha['tipo_tier'])
                                        @case('fixo')
                                            <x-badge tom="marca">{{ $linha['tier'] }}</x-badge>
                                            @break
                                        @case('metrado')
                                            <x-badge>{{ $linha['tier'] }}</x-badge>
                                            @break
                                        @default
                                            <x-badge tom="atencao">sem tier</x-badge>
                                    @endswitch
                                </td>

                                <td class="px-4 py-2.5">
                                    <span class="font-sans text-[13px] text-ink tabular">{{ number_format($linha['unidades'], 0, ',', '.') }}</span>
                                    <span class="ml-1.5 font-sans tabular text-[11px] text-ink-faint">{{ Str::plural($linha['unidade_cobranca'], $linha['unidades']) }}</span>
                                </td>

                                {{-- A conta que gerou o valor, escrita por extenso:
                                     é ela que permite conferir antes de gerar. --}}
                                <td class="px-4 py-2.5 font-sans tabular text-[12.5px] text-ink-dim whitespace-nowrap">
                                    {{ $linha['calculo'] ?? '—' }}
                                </td>

                                <td class="px-4 py-2.5 text-right font-sans tabular text-[13.5px] whitespace-nowrap
                                           {{ $linha['sem_tier'] ? 'text-ink-faint' : 'text-ink' }}">
                                    {{ $linha['sem_tier'] ? '—' : 'R$ '.number_format($linha['valor'], 2, ',', '.') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>

                    <tfoot>
                        <x-linha-total>
                            <td>Subtotal</td>
                            <td></td>
                            <td>{{ number_format($painel['unidades'], 0, ',', '.') }} unidades</td>
                            <td></td>
                            <td class="text-right">R$ {{ number_format($painel['total'], 2, ',', '.') }}</td>
                        </x-linha-total>
                    </tfoot>

                    <x-slot name="rodape">
                        <span>{{ number_format($painel['unidades'], 0, ',', '.') }} clientes considerados</span>
                        @if ($painel['foraDoTotal'] > 0)
                            <span style="color: rgb(var(--warn))">
                                · {{ $painel['foraDoTotal'] }} {{ $painel['foraDoTotal'] === 1 ? 'linha fora' : 'linhas fora' }} do subtotal
                            </span>
                        @endif
                    </x-slot>
                </x-tabela>
            </section>
        @empty
            <x-painel>
                <p class="text-[13px] text-ink-mute">
                    Nenhuma revenda ativa tem cliente com sistema licenciado nesta competência — não há o que faturar.
                </p>
            </x-painel>
        @endforelse

        @if ($cobrancasGeradas->isNotEmpty())
            <x-painel titulo="Cobranças já geradas" :sub="$ciclo['rotulo']" solto>
                @foreach ($cobrancasGeradas as $cobranca)
                    <a href="{{ route('cobrancas.show', $cobranca) }}"
                       class="flex items-center gap-3 px-4 py-3 border-b border-rule last:border-0 hover:bg-chip transition">
                        <span class="min-w-0 flex-1 truncate text-[13px] text-ink-dim">
                            {{ $cobranca->revenda?->nome ?? 'sem revenda' }} · {{ $cobranca->descricao }}
                        </span>
                        <span class="shrink-0 font-sans tabular text-[13px] text-ink whitespace-nowrap">
                            R$ {{ number_format($cobranca->valor, 2, ',', '.') }}
                        </span>
                    </a>
                @endforeach
            </x-painel>
        @endif
    </div>
</x-app-layout>
