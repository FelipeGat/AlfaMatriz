<x-app-layout>
    <x-slot name="titulo">Despesas</x-slot>
    <x-slot name="contexto">contas a pagar · {{ $contasPagar->total() }} título(s)</x-slot>
    <x-slot name="acoes">
        <button type="button" x-data @click="$dispatch('open-modal', 'nova-despesa')"
                class="h-[34px] px-3 rounded-control bg-brand text-on-brand font-semibold text-[12.5px]
                       hover:bg-brand-bright transition whitespace-nowrap">
            + Nova despesa
        </button>
    </x-slot>

    <div class="space-y-4">
        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif
        @if ($errors->any())
            <div class="rounded-panel border px-4 py-2.5 text-[13px]"
                 style="background: var(--crit-tint); border-color: rgb(var(--crit) / 0.25); color: rgb(var(--crit))">
                {{ $errors->first() }}
            </div>
        @endif

        {{--
            Filtros — mesmo padrão das Receitas (16/08/2026): recolhido em
            todo carregamento, trilha de "filtrando por" sempre visível
            mesmo fechado, período por vencimento, busca ampla (fornecedor,
            descrição, valor) e pills de status/tipo com contador. O que
            substitui "revenda" aqui é Centro de Custo — despesa não nasce
            de revenda nenhuma.
        --}}
        @php
            $baseQuery = array_filter([
                'periodo' => $filtroPeriodo,
                'periodo_de' => $filtroPeriodo === 'personalizado' ? $periodoDe : null,
                'periodo_ate' => $filtroPeriodo === 'personalizado' ? $periodoAte : null,
                'busca' => $busca ?: null,
                'centro_custo_id' => $centroCustoId,
                'status_filtro' => $filtroStatus !== 'todos' ? $filtroStatus : null,
                'tipo_filtro' => $filtroTipo,
            ]);
            $pillPeriodo = fn ($chave) => route('contas-pagar.index', \Illuminate\Support\Arr::except($baseQuery, ['periodo', 'periodo_de', 'periodo_ate']) + ['periodo' => $chave]);

            // O nome sozinho ("Este mês") não diz QUAL mês — a data por trás
            // tira a ambiguidade sem obrigar a abrir o filtro pra conferir.
            $faixaPeriodo = fn () => $periodoDe && $periodoAte
                ? ($periodoDe === $periodoAte
                    ? \Illuminate\Support\Carbon::parse($periodoDe)->format('d/m/Y')
                    : \Illuminate\Support\Carbon::parse($periodoDe)->format('d/m').' – '.\Illuminate\Support\Carbon::parse($periodoAte)->format('d/m/Y'))
                : null;
            $rotuloPeriodo = [
                'mes_anterior' => 'Mês anterior', 'mes_atual' => 'Este mês', 'proximo_mes' => 'Próximo mês',
                'ontem' => 'Ontem', 'hoje' => 'Hoje', 'amanha' => 'Amanhã',
                'todos' => 'Todos os períodos', 'personalizado' => \Illuminate\Support\Carbon::parse($periodoDe)->format('d/m/Y').' até '.\Illuminate\Support\Carbon::parse($periodoAte)->format('d/m/Y'),
            ];
            $rotuloPeriodoComData = $filtroPeriodo !== 'todos' && $filtroPeriodo !== 'personalizado' && $faixaPeriodo()
                ? $rotuloPeriodo[$filtroPeriodo].' ('.$faixaPeriodo().')'
                : $rotuloPeriodo[$filtroPeriodo];
            $statusPills = ['todos' => 'Todos', 'em_aberto' => 'Em aberto', 'vencido' => 'Vencido', 'pago' => 'Pago', 'cancelado' => 'Cancelado'];
            $tipoPills = ['avulsa' => 'Pontual', 'fixa' => 'Recorrente'];

            // Mesma régua das Receitas: cada filtro fora do padrão vira chip,
            // com o link que o remove sozinho. Período sempre aparece.
            $chips = collect([
                ['rotulo' => 'Período: '.$rotuloPeriodoComData, 'remover' => \Illuminate\Support\Arr::except($baseQuery, ['periodo', 'periodo_de', 'periodo_ate'])],
                $busca ? ['rotulo' => 'Busca: "'.$busca.'"', 'remover' => \Illuminate\Support\Arr::except($baseQuery, ['busca'])] : null,
                $centroCustoId ? ['rotulo' => 'Centro de custo: '.($centrosCusto->firstWhere('id', (int) $centroCustoId)->nome ?? '—'), 'remover' => \Illuminate\Support\Arr::except($baseQuery, ['centro_custo_id'])] : null,
                $filtroStatus !== 'todos' ? ['rotulo' => 'Status: '.$statusPills[$filtroStatus], 'remover' => \Illuminate\Support\Arr::except($baseQuery, ['status_filtro'])] : null,
                $filtroTipo ? ['rotulo' => 'Tipo: '.$tipoPills[$filtroTipo], 'remover' => \Illuminate\Support\Arr::except($baseQuery, ['tipo_filtro'])] : null,
            ])->filter()->values();
        @endphp

        <div x-data="{ filtrosAbertos: false }">
            <div class="flex flex-wrap items-center gap-2 mb-2">
                <button type="button" @click="filtrosAbertos = ! filtrosAbertos"
                        class="h-8 px-3 inline-flex items-center gap-2 rounded-control border border-line text-[12.5px] text-ink-dim hover:border-brand hover:text-brand transition">
                    <span class="h-3 w-3 transition-transform" :class="filtrosAbertos && 'rotate-180'">
                        <x-nav-icon name="chevron-down" :peso="1.8" />
                    </span>
                    Filtros
                </button>

                <span class="font-mono text-[10px] uppercase tracking-caps text-ink-faint">Filtrando por:</span>
                @foreach ($chips as $chip)
                    <a href="{{ route('contas-pagar.index', $chip['remover']) }}"
                       class="h-7 pl-2.5 pr-1.5 inline-flex items-center gap-1.5 rounded-badge border border-line bg-chip text-[11.5px] text-ink-dim hover:border-brand hover:text-brand transition">
                        {{ $chip['rotulo'] }}
                        <span class="h-3.5 w-3.5 rounded-full flex items-center justify-center text-ink-faint">✕</span>
                    </a>
                @endforeach
                @if ($chips->count() > 1)
                    <a href="{{ route('contas-pagar.index') }}" class="font-mono text-[10.5px] uppercase tracking-caps text-brand-text hover:underline">Limpar tudo</a>
                @endif
            </div>

            <div x-show="filtrosAbertos" x-cloak x-transition
                 class="rounded-panel border border-line bg-subtle p-4 space-y-4">
                {{-- Período --}}
                <div>
                    <p class="mb-2 font-mono text-[10px] uppercase tracking-caps text-ink-faint">Período (por vencimento)</p>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <div class="flex items-center gap-1.5">
                            @foreach (['mes_anterior', 'mes_atual', 'proximo_mes'] as $chave)
                                <a href="{{ $pillPeriodo($chave) }}"
                                   class="h-9 px-3 inline-flex items-center rounded-control border text-[12.5px] transition
                                          {{ $filtroPeriodo === $chave ? 'border-brand bg-brand/[0.08] text-brand-text' : 'border-line text-ink-mute hover:border-brand hover:text-brand' }}">
                                    {{ $rotuloPeriodo[$chave] }}
                                </a>
                            @endforeach
                        </div>

                        <span class="mx-1 h-5 w-px bg-line shrink-0"></span>

                        <div class="flex items-center gap-1.5">
                            @foreach (['ontem', 'hoje', 'amanha'] as $chave)
                                <a href="{{ $pillPeriodo($chave) }}"
                                   class="h-9 px-3 inline-flex items-center rounded-control border text-[12.5px] transition
                                          {{ $filtroPeriodo === $chave ? 'border-brand bg-brand/[0.08] text-brand-text' : 'border-line text-ink-mute hover:border-brand hover:text-brand' }}">
                                    {{ $rotuloPeriodo[$chave] }}
                                </a>
                            @endforeach
                        </div>

                        <span class="mx-1 h-5 w-px bg-line shrink-0"></span>

                        <a href="{{ $pillPeriodo('todos') }}"
                           class="h-9 px-3 inline-flex items-center rounded-control border text-[12.5px] transition
                                  {{ $filtroPeriodo === 'todos' ? 'border-brand bg-brand/[0.08] text-brand-text' : 'border-line text-ink-mute hover:border-brand hover:text-brand' }}">
                            Todos os períodos
                        </a>

                        <form method="GET" class="flex items-center gap-1.5">
                            @foreach (\Illuminate\Support\Arr::except($baseQuery, ['periodo', 'periodo_de', 'periodo_ate']) as $chave => $valor)
                                <input type="hidden" name="{{ $chave }}" value="{{ $valor }}">
                            @endforeach
                            <input type="hidden" name="periodo" value="personalizado">

                            <button type="{{ $filtroPeriodo === 'personalizado' ? 'button' : 'submit' }}"
                                    class="h-9 px-3 inline-flex items-center rounded-control border text-[12.5px] transition
                                           {{ $filtroPeriodo === 'personalizado' ? 'border-brand bg-brand/[0.08] text-brand-text' : 'border-line text-ink-mute hover:border-brand hover:text-brand' }}">
                                Período personalizado
                            </button>

                            @if ($filtroPeriodo === 'personalizado')
                                <input type="date" name="periodo_de" value="{{ $periodoDe }}"
                                       class="h-9 py-0 text-[13px] rounded-control bg-input border-line text-ink">
                                <span class="font-mono text-[10.5px] text-ink-faint">até</span>
                                <input type="date" name="periodo_ate" value="{{ $periodoAte }}"
                                       class="h-9 py-0 text-[13px] rounded-control bg-input border-line text-ink">
                                <x-secondary-button type="submit">Aplicar</x-secondary-button>
                            @endif
                        </form>
                    </div>
                </div>

                <div class="h-px bg-line"></div>

                {{-- Busca + centro de custo --}}
                <form method="GET" class="flex flex-wrap items-end gap-3">
                    <input type="hidden" name="periodo" value="{{ $filtroPeriodo }}">
                    @if ($filtroPeriodo === 'personalizado')
                        <input type="hidden" name="periodo_de" value="{{ $periodoDe }}">
                        <input type="hidden" name="periodo_ate" value="{{ $periodoAte }}">
                    @endif
                    @if ($filtroStatus !== 'todos') <input type="hidden" name="status_filtro" value="{{ $filtroStatus }}"> @endif
                    @if ($filtroTipo) <input type="hidden" name="tipo_filtro" value="{{ $filtroTipo }}"> @endif

                    <div class="flex-1 min-w-[220px] max-w-[380px]">
                        <p class="mb-1.5 font-mono text-[10px] uppercase tracking-caps text-ink-faint">Buscar</p>
                        <label class="flex items-center gap-2 h-9 px-3 rounded-control bg-input border border-line text-ink-faint focus-within:border-brand transition">
                            <span class="h-4 w-4 shrink-0"><x-nav-icon name="search" /></span>
                            <input type="search" name="busca" value="{{ $busca }}"
                                   placeholder="Fornecedor, CNPJ/CPF ou descrição…"
                                   class="flex-1 min-w-0 bg-transparent border-0 p-0 text-[13px] text-ink placeholder-ink-faint focus:ring-0">
                        </label>
                    </div>

                    <div>
                        <p class="mb-1.5 font-mono text-[10px] uppercase tracking-caps text-ink-faint">Centro de custo</p>
                        <select name="centro_custo_id" onchange="this.form.submit()"
                                class="h-9 py-0 text-[13px] rounded-control bg-input border-line text-ink">
                            <option value="">Todos os centros</option>
                            @foreach ($centrosCusto as $centro)
                                <option value="{{ $centro->id }}" {{ (string) $centroCustoId === (string) $centro->id ? 'selected' : '' }}>{{ $centro->nome }}</option>
                            @endforeach
                        </select>
                    </div>

                    <x-secondary-button type="submit">Buscar</x-secondary-button>
                    @if ($busca || $centroCustoId)
                        <a href="{{ route('contas-pagar.index', \Illuminate\Support\Arr::except($baseQuery, ['busca', 'centro_custo_id'])) }}"
                           class="h-9 inline-flex items-center font-mono text-[10.5px] uppercase tracking-caps text-brand-text hover:underline">Limpar</a>
                    @endif
                </form>

                <div class="h-px bg-line"></div>

                {{-- Status e tipo --}}
                <div class="flex flex-col gap-3">
                    <div>
                        <p class="mb-2 font-mono text-[10px] uppercase tracking-caps text-ink-faint">Status</p>
                        <div class="flex flex-wrap items-center gap-1.5">
                            @foreach ($statusPills as $chave => $rotulo)
                                <a href="{{ route('contas-pagar.index', \Illuminate\Support\Arr::except($baseQuery, ['status_filtro']) + ($chave !== 'todos' ? ['status_filtro' => $chave] : [])) }}"
                                   class="h-8 px-3 inline-flex items-center gap-1.5 rounded-control border text-[12px] transition
                                          {{ $filtroStatus === $chave ? 'border-brand bg-brand/[0.08] text-brand-text' : 'border-line text-ink-mute hover:border-brand hover:text-brand' }}">
                                    {{ $rotulo }}
                                    <span class="font-mono text-[10.5px] tabular">{{ $chave === 'todos' ? $contagens['todos'] : $contagens[$chave] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <p class="mb-2 font-mono text-[10px] uppercase tracking-caps text-ink-faint">Tipo</p>
                        <div class="flex flex-wrap items-center gap-1.5">
                            @foreach ($tipoPills as $chave => $rotulo)
                                <a href="{{ route('contas-pagar.index', \Illuminate\Support\Arr::except($baseQuery, ['tipo_filtro']) + ($filtroTipo !== $chave ? ['tipo_filtro' => $chave] : [])) }}"
                                   class="h-8 px-3 inline-flex items-center gap-1.5 rounded-control border text-[12px] transition
                                          {{ $filtroTipo === $chave ? 'border-brand bg-brand/[0.08] text-brand-text' : 'border-line text-ink-mute hover:border-brand hover:text-brand' }}">
                                    {{ $rotulo }}
                                    <span class="font-mono text-[10.5px] tabular">{{ $contagensTipo[$chave] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Os quatro cards — mesma régua das Receitas, sempre sobre o
             recorte de período/busca/centro de custo ativo. --}}
        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr))">
            <x-kpi-card rotulo="A pagar no período" :valor="'R$ '.number_format($kpis['a_pagar'], 2, ',', '.')"
                        acento="warn" icone="trending-down" />
            <x-kpi-card rotulo="Pago no período" :valor="'R$ '.number_format($kpis['pago'], 2, ',', '.')"
                        acento="good" icone="check-circle" sinal="bom" />
            <x-kpi-card rotulo="Vencido no período" :valor="'R$ '.number_format($kpis['vencido'], 2, ',', '.')"
                        acento="crit" icone="alert-triangle" />
            <x-kpi-card rotulo="Vence hoje" :valor="'R$ '.number_format($kpis['vence_hoje'], 2, ',', '.')"
                        acento="amber" icone="clock" />
        </div>

        {{-- Faixa de atraso: onde o dinheiro está travado — GLOBAL, não o
             recorte de período (mesma escolha das Receitas). ------------- --}}
        @php
            $coresFaixa = ['a_vencer' => 'accent', '1_15' => 'warn', '16_30' => 'amber', 'mais_30' => 'crit'];
        @endphp

        @if ($totalEmAbertoGlobal > 0)
            <section class="rounded-panel border border-line bg-card-grad p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <h2 class="font-display text-[15px] font-semibold text-ink">Em aberto por faixa de vencimento</h2>
                    <span class="font-mono text-[13px] text-ink-dim whitespace-nowrap">R$ {{ number_format($totalEmAbertoGlobal, 2, ',', '.') }}</span>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-2">
                    @foreach ($faixas as $chave => $faixa)
                        <span class="flex items-center gap-2 min-w-0">
                            <span class="h-2.5 w-2.5 shrink-0 rounded-badge" style="background: rgb(var(--{{ $coresFaixa[$chave] }}))"></span>
                            <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint whitespace-nowrap">{{ $faixa['rotulo'] }}</span>
                            <span class="font-mono text-[12.5px] text-ink whitespace-nowrap">R$ {{ number_format($faixa['valor'], 2, ',', '.') }}</span>
                        </span>
                    @endforeach
                </div>

                <div class="mt-3 flex w-full gap-[2px] overflow-hidden rounded-badge" style="height: 8px">
                    @foreach ($faixas as $chave => $faixa)
                        @continue($faixa['valor'] <= 0)
                        <span class="block h-full shrink-0"
                              data-faixa="{{ $chave }}"
                              style="width: {{ round(($faixa['valor'] / $totalEmAbertoGlobal) * 100, 3) }}%; min-width: 3px;
                                     background: rgb(var(--{{ $coresFaixa[$chave] }}))"
                              title="{{ $faixa['rotulo'] }} · R$ {{ number_format($faixa['valor'], 2, ',', '.') }}"></span>
                    @endforeach
                </div>
            </section>
        @endif

        <div x-data="{ selecionados: [], valores: {} }">
            <form id="bulk-baixa-despesas" action="{{ route('contas-pagar.baixarEmMassa') }}" method="POST" class="hidden">
                @csrf
                <template x-for="id in selecionados" :key="id">
                    <input type="hidden" name="ids[]" :value="id">
                </template>
            </form>

            {{-- A barra só existe quando há seleção, e ela diz QUANTO — não só
                 quantos: dar baixa é irreversível, e o valor é a conferência. --}}
            <div x-show="selecionados.length > 0" x-cloak
                 class="mb-3 flex flex-wrap items-center gap-3 rounded-panel border px-4 py-2.5"
                 style="background: rgb(var(--brand) / 0.08); border-color: rgb(var(--brand) / 0.3)">
                <span class="font-mono text-[12px] uppercase tracking-caps text-brand-text">
                    <span x-text="selecionados.length"></span> selecionada(s) ·
                    R$ <span x-text="selecionados.reduce((soma, id) => soma + (valores[id] ?? 0), 0)
                                     .toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></span>
                </span>

                <x-confirmar form="bulk-baixa-despesas" confirmar="Dar baixa"
                             titulo="Dar baixa nas despesas selecionadas?"
                             mensagem="Todas as marcadas passam a pagas de uma vez, e as saídas vão para o caixa. Quem não tiver conta financeira definida é pulada."
                             class="ml-auto h-8 px-3 rounded-control bg-brand text-on-brand text-[12px] font-semibold hover:bg-brand-bright transition">
                    Dar baixa nas despesas
                </x-confirmar>
                <button type="button" @click="selecionados = []"
                        class="font-mono text-[10.5px] uppercase tracking-caps text-ink-mute hover:text-ink transition">
                    Limpar
                </button>
            </div>

            <x-tabela min="1040px">
                <thead>
                    <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                        <th class="pl-4 pr-2 py-2.5 w-8"></th>
                        <th class="px-4 py-2.5 font-semibold">Despesa</th>
                        <th class="px-4 py-2.5 font-semibold">Fornecedor</th>
                        <th class="px-4 py-2.5 font-semibold">Vencimento</th>
                        <th class="px-4 py-2.5 font-semibold text-right">Valor</th>
                        <th class="px-4 py-2.5 font-semibold">Status</th>
                        <th class="px-4 py-2.5 font-semibold text-right">Ações</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($contasPagar as $contaPagar)
                        @php
                            $vencimento = \Illuminate\Support\Carbon::parse($contaPagar->data_vencimento);
                            $emAberto = $contaPagar->status === 'em_aberto';
                            $dias = (int) $hoje->diffInDays($vencimento, false);
                            $atrasada = $emAberto && $dias < 0;
                            $vencendo = $emAberto && $dias >= 0 && $dias <= 3;
                            $recorrente = $contaPagar->tipo === 'fixa';
                        @endphp

                        <tr class="border-b border-rule hover:bg-chip transition"
                            @if ($atrasada)
                                style="border-left: 2px solid rgb(var(--crit)); background: rgb(var(--crit) / 0.07)"
                            @elseif ($vencendo)
                                style="border-left: 2px solid rgb(var(--warn)); background: rgb(var(--warn) / 0.05)"
                            @endif>
                            <td class="pl-4 pr-2 py-3">
                                @if ($emAberto)
                                    <input type="checkbox" class="rounded-badge"
                                           x-init="valores[{{ $contaPagar->id }}] = {{ (float) $contaPagar->valor }}"
                                           @change="$event.target.checked
                                               ? selecionados.push({{ $contaPagar->id }})
                                               : selecionados = selecionados.filter(i => i !== {{ $contaPagar->id }})"
                                           :checked="selecionados.includes({{ $contaPagar->id }})"
                                           aria-label="Selecionar {{ $contaPagar->descricao }}">
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2.5 min-w-0">
                                    <span class="h-7 w-7 shrink-0 rounded-tile flex items-center justify-center"
                                          style="background: rgb(var(--{{ $recorrente ? 'brand' : 'ink-mute' }}) / var(--tint-alpha));
                                                 color: rgb(var(--{{ $recorrente ? 'brand-text' : 'ink-mute' }}))">
                                        <span class="h-[14px] w-[14px]"><x-nav-icon :name="$recorrente ? 'repeat' : 'document'" /></span>
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block text-[13.5px] text-ink truncate">{{ $contaPagar->descricao }}</span>
                                        {{-- Recorrentes e pontuais convivem na mesma lista; o que
                                             as distingue é o subtítulo e o ícone, não uma aba. --}}
                                        <span class="block font-mono text-[11px] uppercase tracking-caps text-ink-faint truncate">
                                            {{ $recorrente
                                                ? 'recorrente · todo dia '.$vencimento->format('d')
                                                : 'pontual' }}
                                        </span>
                                    </span>
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                <span class="block text-[13px] text-ink-dim truncate">{{ $contaPagar->fornecedor->nome ?? '—' }}</span>
                                <span class="block font-mono text-[11px] uppercase tracking-caps text-ink-faint truncate">
                                    centro: {{ $contaPagar->centroCusto->nome ?? 'não informado' }}
                                </span>
                            </td>

                            <td class="px-4 py-3">
                                <span class="block font-mono text-[13px] text-ink whitespace-nowrap">{{ $vencimento->format('d/m/Y') }}</span>
                                @if ($emAberto)
                                    <span class="block font-mono text-[11px] whitespace-nowrap"
                                          style="color: rgb(var(--{{ $atrasada ? 'crit' : ($vencendo ? 'warn' : 'ink-faint') }}))">
                                        {{ $atrasada ? 'atraso '.abs($dias).'d' : 'em '.$dias.'d' }}
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-right font-mono text-[13.5px] text-ink whitespace-nowrap">
                                R$ {{ number_format($contaPagar->valor, 2, ',', '.') }}
                            </td>

                            <td class="px-4 py-3">
                                <x-badge :tom="match ($contaPagar->status) {
                                    'pago' => 'bom',
                                    'cancelado' => 'neutro',
                                    default => $atrasada ? 'critico' : 'atencao',
                                }" ponto>
                                    {{ ucfirst(str_replace('_', ' ', $contaPagar->status)) }}
                                </x-badge>
                            </td>

                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <button type="button" title="Anexos (NF/Boleto)" aria-label="Anexos"
                                            @click="$dispatch('open-modal', 'anexos-despesa'); $dispatch('anexos-selecionar', { modal: 'anexos-despesa', id: {{ $contaPagar->id }} })"
                                            class="relative inline-flex h-7 w-7 items-center justify-center rounded-tile text-ink-mute hover:text-brand hover:bg-chip transition">
                                        <span class="h-[15px] w-[15px]"><x-nav-icon name="paperclip" /></span>
                                        @if ($contaPagar->anexos_count > 0)
                                            <span class="absolute -top-1 -right-1 h-3.5 w-3.5 rounded-full bg-brand text-on-brand font-mono text-[9px] leading-[14px] font-semibold text-center">{{ $contaPagar->anexos_count }}</span>
                                        @endif
                                    </button>

                                    @if ($emAberto)
                                        <x-confirmar :action="route('contas-pagar.baixar', $contaPagar)"
                                                     icone="check-circle" confirmar="Dar baixa"
                                                     titulo="Confirmar o pagamento?"
                                                     :mensagem="$contaPagar->descricao.' — R$ '.number_format($contaPagar->valor, 2, ',', '.').'. A saída entra no caixa e a despesa deixa de constar em aberto.'" />
                                    @endif

                                    @if ($contaPagar->contaFixaPagar)
                                        <x-confirmar :action="route('contas-fixas-pagar.pausar', $contaPagar->contaFixaPagar)"
                                                     :icone="$contaPagar->contaFixaPagar->ativo ? 'pause' : 'play'"
                                                     :titulo="$contaPagar->contaFixaPagar->ativo ? 'Pausar esta recorrência?' : 'Reativar esta recorrência?'"
                                                     :mensagem="$contaPagar->contaFixaPagar->ativo
                                                         ? 'Ela para de gerar novas parcelas nos próximos meses. As parcelas já geradas continuam como estão.'
                                                         : 'Ela volta a gerar parcelas a partir da próxima competência.'"
                                                     :confirmar="$contaPagar->contaFixaPagar->ativo ? 'Pausar' : 'Reativar'" />
                                    @endif

                                    <x-acao-tabela icone="pencil" titulo="Editar despesa" :href="route('contas-pagar.edit', $contaPagar)" />

                                    <x-confirmar :action="route('contas-pagar.destroy', $contaPagar)" method="DELETE"
                                                 icone="trash" destrutivo confirmar="Remover"
                                                 titulo="Remover esta despesa?"
                                                 :mensagem="$contaPagar->descricao.' — R$ '.number_format($contaPagar->valor, 2, ',', '.').'. Sai da lista e do total a pagar.'" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-[13px] text-ink-mute">Nenhuma despesa encontrada.</td>
                        </tr>
                    @endforelse
                </tbody>

                <x-slot name="rodape">
                    <span>{{ $contasPagar->count() }} de {{ $contasPagar->total() }} despesas</span>
                    <span>· página {{ $contasPagar->currentPage() }} de {{ $contasPagar->lastPage() }}</span>
                    {{ $contasPagar->links() }}
                </x-slot>
            </x-tabela>
        </div>
    </div>


    {{-- Modal: nova despesa (avulsa ou recorrente) --}}
    <x-modal name="nova-despesa" maxWidth="xl">
        <div x-data="{ recorrente: false }" class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-display font-semibold text-ink text-lg">Nova despesa</h3>
                <label class="flex items-center gap-2 text-sm text-ink-dim cursor-pointer select-none">
                    É recorrente?
                    <button type="button" role="switch" :aria-checked="recorrente" @click="recorrente = !recorrente"
                            class="relative inline-flex h-5 w-9 items-center rounded-full transition"
                            :class="recorrente ? 'bg-brand' : 'bg-white/10'">
                        <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white transition" :class="recorrente ? 'translate-x-5' : 'translate-x-1'"></span>
                    </button>
                </label>
            </div>

            {{-- Pontual --}}
            <form x-show="! recorrente" x-cloak action="{{ route('contas-pagar.store') }}" method="POST" class="space-y-4">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="sm:col-span-2">
                        <x-input-label value="Descrição" />
                        <x-text-input name="descricao" type="text" class="mt-1 block w-full" required />
                    </div>
                    <div>
                        <x-input-label value="Valor (R$)" />
                        <x-text-input name="valor" type="number" step="0.01" min="0" class="mt-1 block w-full" required />
                    </div>
                    <div>
                        <x-input-label value="Vencimento" />
                        <x-text-input name="data_vencimento" type="date" class="mt-1 block w-full" value="{{ now()->toDateString() }}" required />
                    </div>
                    <div>
                        <x-input-label value="Centro de custo" />
                        <select name="centro_custo_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                            <option value="">—</option>
                            @foreach ($centrosCusto as $centro)
                                <option value="{{ $centro->id }}">{{ $centro->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label value="Categoria (conta)" />
                        <select name="conta_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                            <option value="">—</option>
                            @foreach ($contas as $conta)
                                <option value="{{ $conta->id }}">{{ $conta->subcategoria->categoria->nome }} › {{ $conta->subcategoria->nome }} › {{ $conta->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label value="Fornecedor" />
                        <select name="fornecedor_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                            <option value="">—</option>
                            @foreach ($fornecedores as $fornecedor)
                                <option value="{{ $fornecedor->id }}">{{ $fornecedor->razao_social }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label value="Conta de pagamento" />
                        <select name="conta_financeira_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                            <option value="">—</option>
                            @foreach ($contasFinanceiras as $conta)
                                <option value="{{ $conta->id }}">{{ $conta->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label value="Forma de pagamento" />
                        <x-text-input name="forma_pagamento" type="text" class="mt-1 block w-full" />
                    </div>
                </div>
                <div class="flex justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close')">Cancelar</x-secondary-button>
                    <x-primary-button>Salvar</x-primary-button>
                </div>
            </form>

            {{-- Recorrente --}}
            <form x-show="recorrente" x-cloak action="{{ route('contas-fixas-pagar.store') }}" method="POST" class="space-y-4">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="sm:col-span-2">
                        <x-input-label value="Descrição" />
                        <x-text-input name="descricao" type="text" class="mt-1 block w-full" required />
                    </div>
                    <div>
                        <x-input-label value="Valor (R$)" />
                        <x-text-input name="valor" type="number" step="0.01" min="0" class="mt-1 block w-full" required />
                    </div>
                    <div>
                        <x-input-label value="Dia do vencimento" />
                        <x-text-input name="dia_vencimento" type="number" min="1" max="31" class="mt-1 block w-full" required />
                    </div>
                    <div>
                        <x-input-label value="Centro de custo" />
                        <select name="centro_custo_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                            <option value="">—</option>
                            @foreach ($centrosCusto as $centro)
                                <option value="{{ $centro->id }}">{{ $centro->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label value="Categoria (conta)" />
                        <select name="conta_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                            <option value="">—</option>
                            @foreach ($contas as $conta)
                                <option value="{{ $conta->id }}">{{ $conta->subcategoria->categoria->nome }} › {{ $conta->subcategoria->nome }} › {{ $conta->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label value="Fornecedor" />
                        <select name="fornecedor_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                            <option value="">—</option>
                            @foreach ($fornecedores as $fornecedor)
                                <option value="{{ $fornecedor->id }}">{{ $fornecedor->razao_social }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label value="Conta de pagamento" />
                        <select name="conta_financeira_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                            <option value="">—</option>
                            @foreach ($contasFinanceiras as $conta)
                                <option value="{{ $conta->id }}">{{ $conta->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label value="Início da vigência" />
                        <x-text-input name="data_inicio" type="date" class="mt-1 block w-full" value="{{ now()->toDateString() }}" required />
                    </div>
                    <div>
                        <x-input-label value="Fim da vigência (opcional)" />
                        <x-text-input name="data_fim" type="date" class="mt-1 block w-full" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label value="Forma de pagamento" />
                        <x-text-input name="forma_pagamento" type="text" class="mt-1 block w-full" />
                    </div>
                </div>
                <div class="flex justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close')">Cancelar</x-secondary-button>
                    <x-primary-button>Salvar</x-primary-button>
                </div>
            </form>
        </div>
    </x-modal>

    <x-anexos-modal name="anexos-despesa" resource-url="{{ url('contas-pagar') }}" anexo-url="{{ url('contas-pagar/anexos') }}" />
</x-app-layout>
