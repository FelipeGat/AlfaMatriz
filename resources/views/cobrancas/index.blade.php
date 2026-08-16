<x-app-layout>
    <x-slot name="titulo">Receitas</x-slot>
    <x-slot name="contexto">CONTAS A RECEBER · {{ $cobrancas->total() }} TÍTULO(S)</x-slot>
    <x-slot name="acoes">
        <button type="button" x-data @click="$dispatch('open-modal', 'nova-receita')"
                class="inline-flex items-center h-[34px] px-4 rounded-control bg-brand text-on-brand font-sans text-[12.5px] font-semibold hover:bg-brand-bright transition">
            + Nova receita
        </button>
    </x-slot>

    @if (session('status'))
        <x-aviso>{{ session('status') }}</x-aviso>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-control border border-crit-tint bg-crit-tint px-4 py-3 text-sm text-crit">{{ $errors->first() }}</div>
    @endif

    {{--
        Painel de filtros, recolhido em todo carregamento — pedido em
        16/08/2026: a tela tem que mostrar mais conteúdo (cards, tabela) e
        menos filtro. `filtrosAbertos` começa SEMPRE `false`, sem localStorage
        — quem quer filtrar clica na seta; quem não quer, vê a tela cheia.
        A trilha de "filtrando por" logo abaixo fica FORA do `x-show`, de
        propósito: com o painel fechado, é a única forma de saber o que está
        sendo aplicado sem precisar abrir.
    --}}
    @php
        $baseQuery = array_filter([
            'periodo' => $filtroPeriodo,
            'periodo_de' => $filtroPeriodo === 'personalizado' ? $periodoDe : null,
            'periodo_ate' => $filtroPeriodo === 'personalizado' ? $periodoAte : null,
            'busca' => $busca ?: null,
            'revenda_id' => $revendaId,
            'status_filtro' => $filtroStatus !== 'todos' ? $filtroStatus : null,
            'tipo_filtro' => $filtroTipo,
        ]);
        $pillPeriodo = fn ($chave) => route('cobrancas.index', $baseQuery + ['periodo' => $chave]);
        $rotuloPeriodo = [
            'mes_anterior' => 'Mês anterior', 'mes_atual' => 'Este mês', 'proximo_mes' => 'Próximo mês',
            'ontem' => 'Ontem', 'hoje' => 'Hoje', 'amanha' => 'Amanhã',
            'todos' => 'Todos os períodos', 'personalizado' => \Illuminate\Support\Carbon::parse($periodoDe)->format('d/m/Y').' até '.\Illuminate\Support\Carbon::parse($periodoAte)->format('d/m/Y'),
        ];
        $statusPills = ['todos' => 'Todos', 'pendente' => 'Pendente', 'vencido' => 'Vencido', 'pago' => 'Pago', 'cancelado' => 'Cancelado'];
        $tipoPills = ['locacao_sistema' => 'Recorrente', 'avulsa' => 'Avulsa', 'direta' => 'Direta'];

        // Cada filtro que NÃO está no estado padrão vira um chip, com o link
        // que o remove sozinho (volta o resto do estado do jeito que
        // estava). Período sempre aparece — mesmo "Este mês" — porque é a
        // pergunta mais importante da tela ("o que estou vendo?") e esconder
        // o padrão faria quem abriu a tela sem mexer em nada não saber que
        // já existe um recorte por trás dos números.
        $chips = collect([
            ['rotulo' => 'Período: '.$rotuloPeriodo[$filtroPeriodo], 'remover' => \Illuminate\Support\Arr::except($baseQuery, ['periodo', 'periodo_de', 'periodo_ate'])],
            $busca ? ['rotulo' => 'Busca: "'.$busca.'"', 'remover' => \Illuminate\Support\Arr::except($baseQuery, ['busca'])] : null,
            $revendaId ? ['rotulo' => 'Revenda: '.($revendas->firstWhere('id', (int) $revendaId)->nome ?? '—'), 'remover' => \Illuminate\Support\Arr::except($baseQuery, ['revenda_id'])] : null,
            $filtroStatus !== 'todos' ? ['rotulo' => 'Status: '.$statusPills[$filtroStatus], 'remover' => \Illuminate\Support\Arr::except($baseQuery, ['status_filtro'])] : null,
            $filtroTipo ? ['rotulo' => 'Tipo: '.$tipoPills[$filtroTipo], 'remover' => \Illuminate\Support\Arr::except($baseQuery, ['tipo_filtro'])] : null,
        ])->filter()->values();
    @endphp

    <div x-data="{ filtrosAbertos: false }" class="mb-4">
        <div class="flex flex-wrap items-center gap-2 mb-2">
            <button type="button" @click="filtrosAbertos = ! filtrosAbertos"
                    class="h-8 px-3 inline-flex items-center gap-2 rounded-control border border-line text-[12.5px] text-ink-dim hover:border-brand hover:text-brand transition">
                <span class="h-3 w-3 transition-transform" :class="filtrosAbertos && 'rotate-180'">
                    <x-nav-icon name="chevron-down" :peso="1.8" />
                </span>
                Filtros
            </button>

            {{-- "Filtrando por": sempre visível, painel aberto ou não —
                 é a resposta a "que filtro está ativo?" sem precisar abrir nada. --}}
            <span class="font-mono text-[10px] uppercase tracking-caps text-ink-faint">Filtrando por:</span>
            @foreach ($chips as $chip)
                <a href="{{ route('cobrancas.index', $chip['remover']) }}"
                   class="h-7 pl-2.5 pr-1.5 inline-flex items-center gap-1.5 rounded-badge border border-line bg-chip text-[11.5px] text-ink-dim hover:border-brand hover:text-brand transition">
                    {{ $chip['rotulo'] }}
                    <span class="h-3.5 w-3.5 rounded-full flex items-center justify-center text-ink-faint">✕</span>
                </a>
            @endforeach
            @if ($chips->count() > 1)
                <a href="{{ route('cobrancas.index') }}" class="font-mono text-[10.5px] uppercase tracking-caps text-brand-text hover:underline">Limpar tudo</a>
            @endif
        </div>

        <div x-show="filtrosAbertos" x-cloak x-transition
             class="rounded-panel border border-line bg-subtle p-4 space-y-4">
        {{-- Período, por vencimento --}}
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

        {{-- Busca + revenda --}}
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
                           placeholder="Cliente, revenda, CNPJ/CPF ou descrição…"
                           class="flex-1 min-w-0 bg-transparent border-0 p-0 text-[13px] text-ink placeholder-ink-faint focus:ring-0">
                </label>
            </div>

            @unless (auth()->user()->temEscopoDeRevenda())
                <div>
                    <p class="mb-1.5 font-mono text-[10px] uppercase tracking-caps text-ink-faint">Revenda</p>
                    <select name="revenda_id" onchange="this.form.submit()"
                            class="h-9 py-0 text-[13px] rounded-control bg-input border-line text-ink">
                        <option value="">Todas as revendas</option>
                        @foreach ($revendas as $revenda)
                            <option value="{{ $revenda->id }}" {{ (string) $revendaId === (string) $revenda->id ? 'selected' : '' }}>{{ $revenda->nome }}</option>
                        @endforeach
                    </select>
                </div>
            @endunless

            <x-secondary-button type="submit">Buscar</x-secondary-button>
            @if ($busca || $revendaId)
                <a href="{{ route('cobrancas.index', \Illuminate\Support\Arr::except($baseQuery, ['busca', 'revenda_id'])) }}"
                   class="h-9 inline-flex items-center font-mono text-[10.5px] uppercase tracking-caps text-brand-text hover:underline">Limpar</a>
            @endif
        </form>

        <div class="h-px bg-line"></div>

        {{-- Status e tipo — contadores calculados DENTRO do período e busca
             ativos (ver `CobrancaController::index`), então trocar de mês
             também troca o número em cima de cada pill. --}}
        <div class="flex flex-col gap-3">
            <div>
                <p class="mb-2 font-mono text-[10px] uppercase tracking-caps text-ink-faint">Status</p>
                <div class="flex flex-wrap items-center gap-1.5">
                    @foreach ($statusPills as $chave => $rotulo)
                        <a href="{{ route('cobrancas.index', \Illuminate\Support\Arr::except($baseQuery, ['status_filtro']) + ($chave !== 'todos' ? ['status_filtro' => $chave] : [])) }}"
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
                        <a href="{{ route('cobrancas.index', \Illuminate\Support\Arr::except($baseQuery, ['tipo_filtro']) + ($filtroTipo !== $chave ? ['tipo_filtro' => $chave] : [])) }}"
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

    {{-- Os quatro cards do Gestor.Alfa — A receber / Recebido / Vencido /
         Vence hoje —, todos sobre o recorte ativo de período/busca/revenda. --}}
    <div class="grid gap-3 mb-4" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr))">
        <x-kpi-card rotulo="A receber no período" :valor="'R$ '.number_format($kpis['a_receber'], 2, ',', '.')"
                    acento="accent" icone="clipboard" />
        <x-kpi-card rotulo="Recebido no período" :valor="'R$ '.number_format($kpis['recebido'], 2, ',', '.')"
                    acento="good" icone="trending-up" />
        <x-kpi-card rotulo="Vencido no período" :valor="'R$ '.number_format($kpis['vencido'], 2, ',', '.')"
                    acento="crit" icone="alert-triangle" />
        <x-kpi-card rotulo="Vence hoje" :valor="'R$ '.number_format($kpis['vence_hoje'], 2, ',', '.')"
                    acento="amber" icone="clock" />
    </div>

    {{--
        Faixa de aging: o total em aberto GLOBAL (não o do período navegado
        acima) repartido por vencimento, com a barra proporcional a cada
        faixa. Não existe no Gestor — é peça própria daqui, e continua.
    --}}
    @php $alphasFaixa = [0.9, 0.72, 0.58, 0.46]; @endphp
    <x-painel titulo="Em aberto por faixa de vencimento"
              :sub="'total: R$ '.number_format($emAbertoGlobal, 2, ',', '.')"
              class="mb-4">
        <div class="flex flex-wrap gap-x-6 gap-y-2 mb-3">
            @foreach (array_values($faixas) as $i => $faixa)
                <div class="flex items-center gap-2">
                    <span class="h-2.5 w-2.5 rounded-badge shrink-0"
                          style="background: rgb(var(--warn) / {{ $alphasFaixa[$i] ?? end($alphasFaixa) }})"></span>
                    <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-mute">{{ $faixa['rotulo'] }}</span>
                    <span class="font-mono text-[12px] text-ink tabular">R$ {{ number_format($faixa['valor'], 2, ',', '.') }}</span>
                </div>
            @endforeach
        </div>

        <x-faixa-segmentada :segmentos="collect($faixas)->map(fn ($f) => ['rotulo' => $f['rotulo'], 'valor' => $f['valor']])->values()->all()"
                             cor="warn" />
    </x-painel>

    {{--
        Seleção em massa. `valores` mapeia id → valor para que a soma dos
        selecionados seja calculada no cliente sem outra ida ao servidor — a
        mesma rota `cobrancas.baixarEmMassa` de sempre conclui a baixa.
    --}}
    <div x-data="{
            selecionados: [],
            valores: {{ Illuminate\Support\Js::from($cobrancas->getCollection()->pluck('valor', 'id')) }},
            get soma() {
                return this.selecionados.reduce((total, id) => total + Number(this.valores[id] ?? 0), 0);
            },
        }">
        <form id="bulk-baixa-receitas" action="{{ route('cobrancas.baixarEmMassa') }}" method="POST" class="hidden">
            @csrf
            <template x-for="id in selecionados" :key="id">
                <input type="hidden" name="ids[]" :value="id">
            </template>
        </form>

        <div x-show="selecionados.length > 0" x-cloak
             class="mb-3 flex items-center gap-3 rounded-control border border-brand bg-brand/[0.08] px-4 py-2.5">
            <span class="font-mono text-[12.5px] text-ink">
                <span x-text="selecionados.length"></span> selecionada(s) ·
                <span x-text="'R$ ' + soma.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></span>
            </span>
            <x-confirmar form="bulk-baixa-receitas" confirmar="Dar baixa"
                         titulo="Dar baixa nas receitas selecionadas?"
                         mensagem="Todas as marcadas passam a recebidas de uma vez, e as entradas vão para o caixa. Não dá para desfazer em lote."
                         class="ml-auto h-[30px] px-3 rounded-control bg-brand text-on-brand font-sans text-[12px] font-semibold hover:bg-brand-bright transition">
                Dar baixa nas receitas
            </x-confirmar>
            <button type="button" @click="selecionados = []" class="text-[12px] text-ink-mute hover:text-ink">Limpar</button>
        </div>

        <x-tabela min="1180px" titulo="Títulos">
            <thead>
                <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                    <th class="w-10 px-4 py-[13px]"></th>
                    <th class="px-4 py-[13px] text-left">Vencimento</th>
                    <th class="px-4 py-[13px] text-left">Revenda / Cliente</th>
                    <th class="px-4 py-[13px] text-left">CNPJ/CPF</th>
                    <th class="px-4 py-[13px] text-left">Descrição</th>
                    <th class="px-4 py-[13px] text-left">Tipo</th>
                    <th class="px-4 py-[13px] text-right">Valor</th>
                    <th class="px-4 py-[13px] text-left">Status</th>
                    <th class="px-4 py-[13px]"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($cobrancas as $cobranca)
                    @php
                        $pendente = $cobranca->status === 'pendente';
                        $atrasada = $pendente && $cobranca->data_vencimento->lt($hoje);
                        $diasParaVencer = $hoje->diffInDays($cobranca->data_vencimento, false);

                        $prazo = null;
                        if ($pendente) {
                            $prazo = $atrasada
                                ? 'atraso '.abs($diasParaVencer).'d'
                                : ($diasParaVencer === 0 ? 'vence hoje' : 'em '.$diasParaVencer.'d');
                        }

                        $tipoRotulo = ['locacao_sistema' => 'Recorrente', 'avulsa' => 'Avulsa', 'direta' => 'Direta'][$cobranca->tipo] ?? ucfirst($cobranca->tipo);
                        $cnpjCpf = $cobranca->revenda->cnpj ?? $cobranca->cliente->cpf_cnpj ?? null;
                    @endphp
                    <tr class="border-b border-rule hover:bg-chip transition
                               {{ $atrasada ? 'border-l-2 border-l-crit bg-crit-tint' : ($pendente ? 'border-l-2 border-l-warn bg-warn-tint' : '') }}">
                        <td class="px-4 py-[13px]">
                            @if ($pendente)
                                <input type="checkbox" x-model="selecionados" value="{{ $cobranca->id }}"
                                       class="rounded-ctl border-btn-line bg-input text-brand focus:ring-brand">
                            @endif
                        </td>
                        <td class="px-4 py-[13px]">
                            <p class="font-mono text-[13px] text-ink">{{ $cobranca->data_vencimento->format('d/m/Y') }}</p>
                            @if ($prazo)
                                <p class="font-mono text-[11px] {{ $atrasada ? 'text-crit' : 'text-warn' }}">{{ $prazo }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-[13px]">
                            <p class="text-[13.5px] text-ink">{{ $cobranca->revenda->nome ?? $cobranca->cliente->nome ?? '—' }}</p>
                            <p class="text-[11.5px] text-ink-mute">{{ $cobranca->revenda_id ? 'revenda' : ($cobranca->cliente_id ? 'cliente final' : '—') }}</p>
                        </td>
                        <td class="px-4 py-[13px] font-mono text-[12.5px] text-ink-dim whitespace-nowrap">{{ $cnpjCpf ?: '—' }}</td>
                        <td class="px-4 py-[13px]">
                            <p class="text-[13.5px] text-ink">{{ $cobranca->descricao }}</p>
                        </td>
                        <td class="px-4 py-[13px]">
                            <x-badge tom="neutro">{{ $tipoRotulo }}</x-badge>
                        </td>
                        <td class="px-4 py-[13px] text-right font-mono text-[13px] text-ink tabular whitespace-nowrap">R$ {{ number_format($cobranca->valor, 2, ',', '.') }}</td>
                        <td class="px-4 py-[13px]">
                            <x-badge :tom="['pago' => 'bom', 'cancelado' => 'neutro'][$cobranca->status] ?? 'atencao'" ponto>
                                {{ $atrasada ? 'Vencido' : ucfirst($cobranca->status) }}
                            </x-badge>
                        </td>
                        <td class="px-4 py-[13px]">
                            <div class="flex items-center justify-end gap-1">
                                <x-acao-tabela icone="paperclip" titulo="Anexos (NF/Boleto)"
                                               @click="$dispatch('open-modal', 'anexos-receita'); $dispatch('anexos-selecionar', { modal: 'anexos-receita', id: {{ $cobranca->id }} })" />
                                @if ($pendente)
                                    <x-confirmar :action="route('cobrancas.baixar', $cobranca)"
                                                 icone="check-circle" confirmar="Dar baixa"
                                                 titulo="Confirmar o recebimento?"
                                                 :mensagem="$cobranca->descricao.' — R$ '.number_format($cobranca->valor, 2, ',', '.').'. A entrada vai para o caixa e o título sai de em aberto.'" />
                                @endif
                                <x-acao-tabela icone="eye" titulo="Ver" :href="route('cobrancas.show', $cobranca)" />
                                <x-acao-tabela icone="pencil" titulo="Editar" :href="route('cobrancas.edit', $cobranca)" />
                                <x-confirmar :action="route('cobrancas.destroy', $cobranca)" method="DELETE"
                                             icone="trash" destrutivo confirmar="Remover"
                                             titulo="Remover esta receita?"
                                             :mensagem="$cobranca->descricao.' — R$ '.number_format($cobranca->valor, 2, ',', '.').'. Sai da lista e do total a receber.'" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-6 py-10 text-center text-sm text-ink-mute">Nenhuma receita encontrada neste recorte.</td></tr>
                @endforelse
            </tbody>

            <x-slot name="rodape">
                <span>{{ $cobrancas->count() }} de {{ $cobrancas->total() }} receitas</span>
                @if ($cobrancas->hasPages())
                    <span>· página {{ $cobrancas->currentPage() }} de {{ $cobrancas->lastPage() }}</span>
                @endif
                {{ $cobrancas->links() }}
            </x-slot>
        </x-tabela>
    </div>

    <x-anexos-modal name="anexos-receita" resource-url="{{ url('cobrancas') }}" anexo-url="{{ url('cobrancas/anexos') }}" />

    <x-modal name="nova-receita" maxWidth="2xl">
        <form method="POST" action="{{ route('cobrancas.store') }}" class="p-5">
            <h2 class="font-display text-[15.5px] font-semibold text-ink mb-4">Nova receita</h2>
            @include('cobrancas._form', ['emModal' => true])
        </form>
    </x-modal>
</x-app-layout>
