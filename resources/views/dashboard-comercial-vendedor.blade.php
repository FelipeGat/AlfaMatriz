<x-app-layout>
    <x-slot name="titulo">Meu Painel Comercial</x-slot>
    <x-slot name="contexto">competência {{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $competencia)->format('m/Y') }}</x-slot>

    {{--
        O painel de quem vende sem visão gerencial — `PainelController::comercialDoVendedor()`
        é quem decide que esta view (e não `dashboard-comercial`) é a certa: só o que é da
        própria mesa, nunca o dinheiro da casa nem a carteira de outro vendedor.
    --}}
    <div class="space-y-4">
        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))">
            <x-kpi-card rotulo="Taxa de conversão" :valor="number_format($kpis['taxa_conversao'], 1, ',', '.').'%'"
                        acento="accent" icone="trending-up"
                        :delta="$kpis['fechados'].' de '.$kpis['total'].' leads'" />
            <x-kpi-card rotulo="Pipeline aberto" :valor="'R$ '.number_format($kpis['pipeline_valor'], 2, ',', '.')"
                        acento="brand" icone="clipboard"
                        :delta="$kpis['abertos'].' em negociação'" />
            <x-kpi-card rotulo="Ticket médio fechado" :valor="'R$ '.number_format($kpis['ticket_medio'], 2, ',', '.')"
                        acento="good" icone="check-circle" />
            <x-kpi-card rotulo="Abertos / Perdidos" :valor="$kpis['abertos'].' / '.$kpis['perdidos']"
                        acento="amber" icone="view-grid" />
        </div>

        {{-- Meta: só aparece quando o gestor lançou uma para esta
             competência. Sem meta lançada, mostrar "0%" seria dizer que a
             pessoa não bateu nada, quando a pergunta certa é "ninguém
             definiu quanto era para bater". --}}
        <x-painel titulo="Minha meta" :sub="'competência '.\Illuminate\Support\Carbon::createFromFormat('Y-m', $competencia)->format('m/Y')">
            @if ($meta !== null && $meta > 0)
                @php $percentual = min(100, ($realizado / $meta) * 100); @endphp
                <div class="flex items-end justify-between gap-4 mb-2">
                    <div>
                        <p class="font-display text-[26px] font-semibold leading-none text-ink tabular">
                            R$ {{ number_format($realizado, 2, ',', '.') }}
                        </p>
                        <p class="mt-1 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                            realizado de R$ {{ number_format($meta, 2, ',', '.') }}
                        </p>
                    </div>
                    <x-badge :tom="$percentual >= 100 ? 'bom' : ($percentual >= 60 ? 'atencao' : 'critico')">
                        {{ number_format(($realizado / $meta) * 100, 0, ',', '.') }}%
                    </x-badge>
                </div>
                <div class="h-2 w-full rounded-full bg-chip overflow-hidden">
                    <div class="h-full rounded-full" style="width: {{ $percentual }}%; background: rgb(var(--{{ $percentual >= 100 ? 'good' : 'brand' }}))"></div>
                </div>
            @else
                <p class="text-[13px] text-ink-mute">Nenhuma meta definida para esta competência ainda.</p>
            @endif
        </x-painel>

        <x-ranking :ranking="$rankingFunil"
                   titulo="Avanço do meu funil"
                   nota="onde estão os meus leads agora"
                   rotuloTotal="Leads no funil" />

        <p class="text-[12.5px] text-ink-faint">
            <a href="{{ route('leads.index') }}" class="text-brand-text hover:underline">Ver meu funil completo →</a>
        </p>
    </div>
</x-app-layout>
