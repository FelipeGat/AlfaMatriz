<x-app-layout>
    <x-slot name="titulo">Centro de Controle</x-slot>
    <x-slot name="contexto">{{ $saudacao['competencia'] }} · fechamento em {{ $saudacao['diasParaFechamento'] }} dias</x-slot>

    {{-- Halo de marca no topo: dá profundidade sem sombra e sem custar clique. --}}
    <div class="relative -mx-4 -mt-6 px-4 pt-6 sm:-mx-7 sm:px-7">
        <div class="absolute inset-x-0 top-0 h-60 pointer-events-none"
             style="background: radial-gradient(ellipse 80% 100% at 18% 0%, var(--glow), transparent 70%)"></div>

        <div class="relative space-y-5">
            {{-- Saudação ------------------------------------------------------- --}}
            <header>
                <p class="font-mono text-[11px] uppercase tracking-caps-max text-brand-text">{{ $saudacao['data'] }}</p>
                <h2 class="mt-1.5 font-display text-[31px] font-semibold leading-none tracking-[-0.01em] text-ink">
                    {{ $saudacao['periodo'] }}, {{ $saudacao['nome'] }}
                </h2>
                <p class="mt-2 text-[13px] text-ink-mute">
                    @if (count($fila) > 0)
                        {{ count($fila) }} {{ count($fila) === 1 ? 'item pede' : 'itens pedem' }} decisão sua hoje
                    @else
                        Nada pede decisão sua hoje
                    @endif
                    · fechamento de {{ $saudacao['competencia'] }} em {{ $saudacao['diasParaFechamento'] }} dias
                </p>
            </header>

            {{-- Cards de destaque ---------------------------------------------- --}}
            <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr))">
                @foreach ($cards as $card)
                    <x-kpi-card
                        :rotulo="$card['rotulo']"
                        :valor="$card['valor']"
                        :delta="$card['delta']"
                        :sinal="$card['sinal']"
                        :acento="$card['acento']"
                        :icone="$card['icone']"
                        :serie="$card['serie']" />
                @endforeach
            </div>

            {{-- Corpo ---------------------------------------------------------- --}}
            <div class="grid gap-4 items-start" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr))">
                <div class="space-y-4" style="grid-column: span 1; min-width: 0">

                    {{-- Fila de ação -------------------------------------------- --}}
                    <x-painel titulo="Fila de ação"
                              :sub="count($fila).' '.(count($fila) === 1 ? 'aberto' : 'abertos')"
                              solto>
                        @forelse ($fila as $item)
                            @php
                                $token = match ($item['nivel']) {
                                    'critico' => 'crit',
                                    'atencao' => 'warn',
                                    default => 'brand-text',
                                };
                            @endphp

                            <div class="relative flex items-center gap-3 px-4 py-3.5 border-b border-rule last:border-0">
                                {{-- Barra de severidade: a cor diz o peso antes de o texto ser lido. --}}
                                <span class="absolute left-0 inset-y-0 w-[2px]"
                                      style="background: rgb(var(--{{ $token }}))"></span>

                                <span class="h-7 w-7 shrink-0 rounded-tile flex items-center justify-center"
                                      style="background: rgb(var(--{{ $token }}) / var(--tint-alpha)); color: rgb(var(--{{ $token }}))">
                                    <span class="h-[15px] w-[15px]"><x-nav-icon :name="$item['icone']" /></span>
                                </span>

                                <span class="min-w-0 flex-1">
                                    <span class="block text-[14px] font-medium text-ink truncate">{{ $item['titulo'] }}</span>
                                    <span class="block text-[11.5px] text-ink-mute truncate">{{ $item['subtitulo'] }}</span>
                                </span>

                                @if ($item['valor'])
                                    <span class="shrink-0 font-sans tabular text-[13px] text-ink-dim whitespace-nowrap">{{ $item['valor'] }}</span>
                                @endif

                                <a href="{{ $item['rota'] }}"
                                   class="shrink-0 h-7 px-2.5 rounded-tile border border-btn-line
                                          font-mono text-[10.5px] font-semibold uppercase tracking-[0.08em]
                                          text-ink-mute hover:text-brand hover:border-brand transition
                                          inline-flex items-center whitespace-nowrap">{{ $item['acao'] }}</a>
                            </div>
                        @empty
                            <div class="flex items-center gap-3 px-4 py-6">
                                <span class="h-7 w-7 shrink-0 rounded-tile flex items-center justify-center"
                                      style="background: rgb(var(--good) / var(--tint-alpha)); color: rgb(var(--good))">
                                    <span class="h-[15px] w-[15px]"><x-nav-icon name="check-circle" /></span>
                                </span>
                                <span class="text-[14px] text-ink-dim">Tudo em dia — nenhuma pendência aberta.</span>
                            </div>
                        @endforelse
                    </x-painel>

                    {{-- Origem da receita recorrente ----------------------------- --}}
                    <x-painel titulo="Origem do MRR" :sub="$origemMrr['competencia']">
                        @if (empty($origemMrr['linhas']))
                            <p class="text-[13px] text-ink-mute">Nenhuma receita recorrente lançada nesta competência.</p>
                        @else
                            <div class="space-y-2.5">
                                @foreach ($origemMrr['linhas'] as $linha)
                                    <div class="flex items-center gap-3">
                                        <div class="min-w-0" style="flex: 0 0 38%">
                                            <p class="text-[13px] text-ink truncate">{{ $linha['nome'] }}</p>
                                            <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint truncate">
                                                {{ $linha['clientes'] }} clientes · {{ number_format($linha['share'] * 100, 0) }}% do MRR
                                            </p>
                                        </div>

                                        {{-- A pista é comum a todas as linhas, e a barra ocupa a
                                             fração do VALOR nela. É isso que faz a maior origem
                                             ter, de fato, a maior barra. --}}
                                        <div class="relative flex-1 min-w-0 h-3 rounded-badge bg-bar-track overflow-hidden">
                                            @foreach ($origemMrr['guias'] as $guia)
                                                @continue($guia >= $origemMrr['escala'])
                                                <span class="absolute inset-y-0 w-px"
                                                      style="left: {{ round(($guia / $origemMrr['escala']) * 100, 2) }}%; background: rgb(var(--ink-faint) / 0.25)"></span>
                                            @endforeach

                                            <span class="absolute inset-y-0 left-0 rounded-badge"
                                                  data-barra="{{ $linha['nome'] }}"
                                                  style="width: {{ round($linha['largura'] * 100, 3) }}%; background: rgb(var(--{{ $linha['cor'] }}) / 0.55)"></span>
                                            <span class="absolute inset-y-0 w-[2px]"
                                                  style="left: calc({{ round($linha['largura'] * 100, 3) }}% - 2px); background: rgb(var(--{{ $linha['cor'] }}))"></span>
                                        </div>

                                        <div class="shrink-0 text-right" style="width: 84px">
                                            <p class="font-sans tabular text-[12.5px] text-ink whitespace-nowrap">{{ 'R$ '.number_format($linha['valor'], 0, ',', '.') }}</p>
                                            <p class="font-sans tabular text-[10px] text-ink-faint whitespace-nowrap">{{ $linha['delta'] }}</p>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            {{-- Eixo ao pé: sem ele a régua vira barra decorativa. --}}
                            <div class="mt-3 flex items-center gap-3">
                                <div style="flex: 0 0 38%"></div>
                                <div class="relative flex-1 min-w-0 h-4 font-sans tabular text-[10px] text-ink-faint">
                                    <span class="absolute left-0">0</span>
                                    @foreach ($origemMrr['guias'] as $guia)
                                        @continue($guia >= $origemMrr['escala'])
                                        <span class="absolute -translate-x-1/2"
                                              style="left: {{ round(($guia / $origemMrr['escala']) * 100, 2) }}%">{{ $guia / 1000 }}k</span>
                                    @endforeach
                                </div>
                                <div class="shrink-0" style="width: 84px"></div>
                            </div>
                        @endif
                    </x-painel>
                </div>

                {{-- Coluna direita -------------------------------------------- --}}
                <div class="space-y-4" style="min-width: 0">
                    <x-painel titulo="Próximos 7 dias" solto>
                        @forelse ($proximos as $item)
                            @php $token = $item['sinal'] === 'entrada' ? 'good' : 'warn'; @endphp
                            <a href="{{ $item['rota'] }}" class="flex items-center gap-3 px-4 py-3 border-b border-rule last:border-0 hover:bg-chip transition">
                                <span class="h-[7px] w-[7px] shrink-0 rounded-full"
                                      style="background: rgb(var(--{{ $token }}))"></span>
                                <span class="shrink-0 font-mono text-[11px] uppercase tracking-caps text-ink-faint">{{ $item['data']->format('d/m') }}</span>
                                <span class="min-w-0 flex-1 truncate text-[13px] text-ink-dim">{{ $item['descricao'] }}</span>
                                <span class="shrink-0 font-sans tabular text-[12.5px] whitespace-nowrap"
                                      style="color: rgb(var(--{{ $token }}))">
                                    {{ $item['sinal'] === 'entrada' ? '+' : '−' }}{{ number_format($item['valor'], 2, ',', '.') }}
                                </span>
                            </a>
                        @empty
                            <p class="px-4 py-6 text-[13px] text-ink-mute">Nada vence nos próximos 7 dias.</p>
                        @endforelse
                    </x-painel>

                    <a href="{{ route('leads.index') }}"
                       class="block rounded-panel border p-4 transition hover:border-brand"
                       style="background: rgb(var(--brand) / 0.07); border-color: rgb(var(--brand) / 0.24)">
                        <p class="text-[11px] uppercase tracking-[0.10em] text-ink-mute">Pipeline aberto</p>
                        <p class="mt-1.5 font-display text-[26px] font-semibold leading-none text-ink tabular">
                            R$ {{ number_format($pipeline['valor'], 2, ',', '.') }}
                        </p>
                        <p class="mt-2 font-mono text-[11px] uppercase tracking-caps text-brand-text">
                            {{ $pipeline['quantidade'] }} {{ $pipeline['quantidade'] === 1 ? 'lead aberto' : 'leads abertos' }} · abrir funil
                        </p>
                    </a>

                    <x-painel titulo="Entraram esta semana" solto>
                        @forelse ($entraram as $cliente)
                            <a href="{{ route('clientes.edit', $cliente) }}"
                               class="flex items-center gap-3 px-4 py-3 border-b border-rule last:border-0 hover:bg-chip transition">
                                <span class="h-7 w-7 shrink-0 rounded-full bg-brand/20 text-brand-text flex items-center justify-center font-sans tabular text-[11px] font-semibold">
                                    {{ Str::of($cliente->nome_exibicao)->substr(0, 1)->upper() }}
                                </span>
                                <span class="min-w-0 flex-1 truncate text-[13px] text-ink-dim">{{ $cliente->nome_exibicao }}</span>
                                {{-- A data de entrada, a mesma que filtrou a lista. `created_at`
                                     aqui mostrava o dia da importação, e não o do cadastro. --}}
                                <span class="shrink-0 font-sans tabular text-[11px] text-ink-faint">{{ $cliente->data_entrada?->format('d/m') }}</span>
                            </a>
                        @empty
                            <p class="px-4 py-6 text-[13px] text-ink-mute">Nenhum cliente novo nos últimos 7 dias.</p>
                        @endforelse
                    </x-painel>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
