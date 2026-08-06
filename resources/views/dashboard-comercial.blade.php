<x-app-layout>
    <x-slot name="titulo">Painel Comercial</x-slot>
    <x-slot name="contexto">portfólio e base instalada</x-slot>

    <div class="space-y-4">
        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))">
            <x-kpi-card rotulo="Sistemas ativos" :valor="number_format($totalSistemasAtivos, 0, ',', '.')"
                        acento="accent" icone="cube-outline" />
            <x-kpi-card rotulo="Clientes ativos" :valor="number_format($totalClientesAtivos, 0, ',', '.')"
                        acento="brand" icone="users" />
            <x-kpi-card rotulo="Revendas ativas" :valor="number_format($totalRevendasAtivas, 0, ',', '.')"
                        acento="amber" icone="building" />
            <x-kpi-card rotulo="MRR de atacado" :valor="'R$ '.number_format($mrrEstimado, 2, ',', '.')"
                        acento="chart-out" icone="repeat" />
        </div>

        {{-- O destaque da tela: os dois rankings, em três camadas. --}}
        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(420px, 1fr))">
            <x-ranking :ranking="$rankingClientes"
                       titulo="Produtos por clientes ativos"
                       nota="quem tem mais base instalada"
                       rotuloTotal="Clientes ativos" />

            <x-ranking :ranking="$rankingValor"
                       titulo="Produtos por valor gerado"
                       nota="quem sustenta o faturamento"
                       rotuloTotal="MRR estimado"
                       formato="reais" />
        </div>

        {{-- Mesma gramática, sem o bloco de topo: são recortes de apoio. --}}
        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr))">
            <x-ranking :ranking="$rankingRevendas"
                       titulo="Clientes por revenda"
                       nota="inclui venda direta"
                       compacto />

            <x-ranking :ranking="$rankingCategorias"
                       titulo="Portfólio por categoria"
                       nota="sistemas por categoria"
                       compacto />
        </div>
    </div>
</x-app-layout>
