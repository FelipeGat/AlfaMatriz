<x-app-layout>
    <x-slot name="titulo">Receitas</x-slot>
    <x-slot name="contexto">CONTAS A RECEBER · {{ $cobrancas->total() }} TÍTULO(S)</x-slot>
    <x-slot name="acoes">
        <a href="{{ route('cobrancas.create') }}"
           class="inline-flex items-center h-[34px] px-4 rounded-control bg-brand text-on-brand font-sans text-[12.5px] font-semibold hover:bg-brand-bright transition">
            + Nova receita
        </a>
    </x-slot>

    @if (session('status'))
        <div class="mb-4 rounded-control border border-good-line bg-good-tint px-4 py-3 text-sm text-good">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-control border border-crit-tint bg-crit-tint px-4 py-3 text-sm text-crit">{{ $errors->first() }}</div>
    @endif

    {{-- KPIs --}}
    <div class="grid gap-3 mb-4" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr))">
        <x-kpi-card rotulo="Em aberto" :valor="'R$ '.number_format($kpis['em_aberto'], 2, ',', '.')"
                    :delta="$kpis['em_aberto_titulos'].' título(s)'" acento="warn" icone="banknotes" />
        <x-kpi-card rotulo="Vence em 7 dias" :valor="'R$ '.number_format($kpis['vence_em_7_dias'], 2, ',', '.')"
                    acento="amber" icone="clock" />
        <x-kpi-card rotulo="Recebido no mês" :valor="'R$ '.number_format($kpis['recebido_mes'], 2, ',', '.')"
                    acento="good" icone="trending-up" />
        <x-kpi-card rotulo="Atrasado" :valor="'R$ '.number_format($kpis['atrasado'], 2, ',', '.')"
                    :delta="$kpis['atrasado_titulos'].' título(s)'" acento="crit" icone="alert-triangle" />
    </div>

    {{--
        Faixa de aging: o mesmo total em aberto repartido por vencimento, com
        a barra proporcional a cada faixa e o quadrado de cor amarrando a
        legenda ao segmento — mesma alpha decrescente das duas pontas.
    --}}
    @php $alphasFaixa = [0.9, 0.72, 0.58, 0.46]; @endphp
    <x-painel titulo="Em aberto por faixa de vencimento"
              :sub="'total: R$ '.number_format($kpis['em_aberto'], 2, ',', '.')"
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

    <form method="GET" class="mb-4 flex flex-wrap gap-3">
        <select name="status" class="rounded-control border-line bg-input text-ink text-sm" onchange="this.form.submit()">
            <option value="">Todos os status</option>
            <option value="pendente" {{ request('status') === 'pendente' ? 'selected' : '' }}>Pendente</option>
            <option value="pago" {{ request('status') === 'pago' ? 'selected' : '' }}>Pago</option>
            <option value="cancelado" {{ request('status') === 'cancelado' ? 'selected' : '' }}>Cancelado</option>
        </select>
        <input type="text" name="competencia" placeholder="Competência (AAAA-MM)" value="{{ request('competencia') }}"
               class="rounded-control border-line bg-input text-ink placeholder-ink-faint text-sm" onchange="this.form.submit()">
    </form>

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

        <x-tabela min="1080px" titulo="Títulos">
            <thead>
                <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                    <th class="w-10 px-4 py-[13px]"></th>
                    <th class="px-4 py-[13px] text-left">Título</th>
                    <th class="px-4 py-[13px] text-left">Origem</th>
                    <th class="px-4 py-[13px] text-left">Vencimento</th>
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
                            <p class="text-[13.5px] text-ink">{{ $cobranca->descricao }}</p>
                            <p class="text-[11.5px] text-ink-mute">{{ $cobranca->tipo === 'locacao_sistema' ? 'cobrança consolidada' : 'serviço avulso' }}</p>
                        </td>
                        <td class="px-4 py-[13px]">
                            <p class="text-[13.5px] text-ink">{{ $cobranca->revenda->nome ?? $cobranca->cliente->nome ?? '—' }}</p>
                            <p class="text-[11.5px] text-ink-mute">{{ $cobranca->revenda_id ? 'revenda' : ($cobranca->cliente_id ? 'cliente final' : '—') }}</p>
                        </td>
                        <td class="px-4 py-[13px]">
                            <p class="font-mono text-[13px] text-ink">{{ $cobranca->data_vencimento->format('d/m/Y') }}</p>
                            @if ($prazo)
                                <p class="font-mono text-[11px] {{ $atrasada ? 'text-crit' : 'text-warn' }}">{{ $prazo }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-[13px] text-right font-mono text-[13px] text-ink tabular whitespace-nowrap">R$ {{ number_format($cobranca->valor, 2, ',', '.') }}</td>
                        <td class="px-4 py-[13px]">
                            <x-badge :tom="['pago' => 'bom', 'cancelado' => 'neutro'][$cobranca->status] ?? 'atencao'" ponto>
                                {{ ucfirst($cobranca->status) }}
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
                    <tr><td colspan="7" class="px-6 py-10 text-center text-sm text-ink-mute">Nenhuma receita cadastrada.</td></tr>
                @endforelse
            </tbody>

            <x-slot name="rodape">
                <span>{{ $cobrancas->count() }} de {{ $cobrancas->total() }} receitas</span>
            </x-slot>
        </x-tabela>

        <div class="mt-4">{{ $cobrancas->links() }}</div>
    </div>

    <x-anexos-modal name="anexos-receita" resource-url="{{ url('cobrancas') }}" anexo-url="{{ url('cobrancas/anexos') }}" />
</x-app-layout>
