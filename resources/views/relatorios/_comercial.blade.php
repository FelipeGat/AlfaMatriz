{{-- Os cards misturam duas fotografias, e cada rótulo diz qual: "em aberto" e
     "taxa de conversão" são o funil de HOJE (aberto não tem data — ou está lá,
     ou não está); fechados, perdidos e novos clientes são o que a competência
     navegada moveu. --}}
<div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))">
    <x-kpi-card rotulo="Leads em aberto" :valor="number_format($kpis['abertos'], 0, ',', '.')"
                :delta="'R$ '.number_format($kpis['pipeline_valor'], 2, ',', '.').' em pipeline'"
                acento="accent" icone="view-grid" />
    <x-kpi-card rotulo="Fechados na competência" :valor="number_format($fechadosQtd, 0, ',', '.')"
                :delta="'R$ '.number_format($fechadosValor, 2, ',', '.')"
                :sinal="$fechadosQtd > 0 ? 'bom' : 'neutro'"
                acento="good" icone="check-circle" />
    <x-kpi-card rotulo="Perdidos na competência" :valor="number_format($perdidosQtd, 0, ',', '.')"
                :delta="'R$ '.number_format($perdidosValor, 2, ',', '.')"
                :sinal="$perdidosQtd > 0 ? 'ruim' : 'neutro'"
                acento="crit" icone="x-mark" />
    {{-- Entrada de verdade na base — cliente cadastrado —, não movimento de
         funil: o fechado vira cliente, mas cliente também chega por fora. --}}
    <x-kpi-card rotulo="Novos clientes na competência" :valor="number_format($novosClientesQtd, 0, ',', '.')"
                :sinal="$novosClientesQtd > 0 ? 'bom' : 'neutro'"
                :delta="$novosClientesQtd > 0 ? 'entraram na base' : 'nenhum novo'"
                acento="brand" icone="user-plus" />
    <x-kpi-card rotulo="Taxa de conversão" :valor="number_format($kpis['taxa_conversao'], 1, ',', '.').'%'"
                delta="funil inteiro, desde o início"
                acento="brand" icone="trending-up" />
</div>

<div class="grid gap-3 items-start" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr))">
    {{-- A régua é a de `Lead::temperatura()`: quente < 7 dias parado no
         estágio, esfriando de 7 a 15, frio dali em diante. É o painel de
         "quem precisa de ligação hoje". --}}
    <x-painel titulo="Em aberto por temperatura" :sub="number_format($abertosQtd, 0, ',', '.').' leads'">
        <dl class="divide-y divide-rule">
            @foreach ($temperaturas as $temperatura)
                <div class="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0">
                    <dt>
                        <x-badge :tom="['quente' => 'bom', 'esfriando' => 'atencao', 'frio' => 'critico'][$temperatura['chave']]">
                            {{ $temperatura['rotulo'] }}
                        </x-badge>
                    </dt>
                    <dd class="flex items-baseline gap-3">
                        <span class="font-mono text-[13.5px] text-ink tabular">{{ number_format($temperatura['quantidade'], 0, ',', '.') }}</span>
                        <span class="font-mono text-[11px] text-ink-faint tabular">R$ {{ number_format($temperatura['valor'], 2, ',', '.') }}</span>
                    </dd>
                </div>
            @endforeach
        </dl>

        <x-faixa-segmentada class="mt-3" cor="warn"
                            :segmentos="collect($temperaturas)->map(fn ($t) => ['rotulo' => $t['rotulo'], 'valor' => $t['quantidade']])->values()->all()" />
    </x-painel>

    <x-ranking :ranking="$rankingFunil"
               titulo="Avanço do funil"
               nota="foto de hoje"
               rotuloTotal="Leads no funil"
               compacto />

    <x-ranking :ranking="$rankingOrigens"
               titulo="Em aberto por origem"
               nota="de onde o pipeline vem"
               compacto />
</div>

<div class="grid gap-3 items-start" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr))">
    <x-ranking :ranking="$rankingVendedores"
               titulo="Vendas por vendedor"
               nota="fechado na competência"
               rotuloTotal="Total fechado"
               formato="reais"
               compacto />

    {{-- O ranking que existe para mostrar buraco: perda sem motivo aparece
         como "Sem motivo", não some — ver `secaoComercial()`. --}}
    <x-ranking :ranking="$rankingMotivosPerda"
               titulo="Perdas por motivo"
               nota="perdidos na competência"
               compacto />
</div>

{{-- A mesa de negociação: os dez maiores valores parados no funil, com a
     idade de cada um — é onde o gestor decide a próxima ligação. --}}
<x-tabela min="820px" titulo="Maiores negócios em aberto" sub="top 10 por valor estimado">
    <thead>
        <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
            <th class="px-4 py-2.5 font-semibold">Lead</th>
            <th class="px-4 py-2.5 font-semibold">Estágio</th>
            <th class="px-4 py-2.5 font-semibold">Vendedor</th>
            <th class="px-4 py-2.5 font-semibold text-right">Dias no estágio</th>
            <th class="px-4 py-2.5 font-semibold">Temperatura</th>
            <th class="px-4 py-2.5 font-semibold text-right">Valor estimado</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($maioresLeads as $lead)
            <tr class="border-b border-rule hover:bg-chip transition">
                <td class="px-4 py-3 text-[13.5px] text-ink">{{ $lead->nome }}</td>
                <td class="px-4 py-3 text-[13px] text-ink-dim">{{ \App\Models\Lead::ESTAGIOS[$lead->estagio] ?? $lead->estagio }}</td>
                <td class="px-4 py-3 text-[13px] text-ink-dim">{{ $lead->vendedor?->name ?? 'Sem vendedor' }}</td>
                <td class="px-4 py-3 font-mono text-[13px] text-ink tabular text-right">{{ number_format($lead->diasNoEstagio(), 0, ',', '.') }}</td>
                <td class="px-4 py-3">
                    @php $temperatura = $lead->temperatura(); @endphp
                    @if ($temperatura)
                        <x-badge :tom="['quente' => 'bom', 'esfriando' => 'atencao', 'frio' => 'critico'][$temperatura]">
                            {{ ucfirst($temperatura) }}
                        </x-badge>
                    @endif
                </td>
                <td class="px-4 py-3 font-mono text-[13px] text-ink tabular text-right whitespace-nowrap">
                    R$ {{ number_format($lead->valor_estimado, 2, ',', '.') }}
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-4 py-6 text-[13px] text-ink-mute">Nenhum lead em aberto neste recorte.</td></tr>
        @endforelse
    </tbody>
</x-tabela>
