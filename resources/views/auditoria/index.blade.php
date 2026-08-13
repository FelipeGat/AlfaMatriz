<x-app-layout>
    <x-slot name="titulo">Auditoria</x-slot>

    <x-slot name="contexto">
        {{ $registros->total() }} registros no recorte
    </x-slot>

    <div class="space-y-4">
        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr))">
            <x-kpi-card rotulo="Eventos hoje" :valor="$kpis['hoje']" acento="brand" icone="clock" />
            <x-kpi-card rotulo="Entradas hoje" :valor="$kpis['entradas']" acento="accent" icone="users" />

            {{-- As recusas são o único KPI com janela de 7 dias: uma tentativa
                 errada por dia não é notícia, quarenta na mesma semana são. O
                 número de hoje sozinho não distingue os dois casos. --}}
            <x-kpi-card rotulo="Senhas recusadas" :valor="$kpis['recusas']"
                        delta="últimos 7 dias" acento="amber" icone="cadeado-fechado" />

            <x-kpi-card rotulo="Exclusões" :valor="$kpis['exclusoes']"
                        delta="últimos 30 dias" acento="crit" icone="trash" />
        </div>

        @include('auditoria._tabela')
    </div>
</x-app-layout>
