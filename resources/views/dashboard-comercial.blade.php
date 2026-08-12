<x-app-layout>
    <x-slot name="titulo">Painel Comercial</x-slot>
    <x-slot name="contexto">portfólio e base instalada</x-slot>

    <div class="space-y-4">
        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))">
            {{-- Cada curva vem vazia quando não tem o que dizer, e o card
                 simplesmente não desenha a linha. Ver
                 `IndicadoresService::serieDeEntrada()` para quando isso vale. --}}
            <x-kpi-card rotulo="Sistemas ativos" :valor="number_format($totalSistemasAtivos, 0, ',', '.')"
                        :serie="$serieSistemas"
                        acento="accent" icone="cube-outline" />
            {{-- O único card desta tela com tendência: é o único indicador cujo
                 histórico o banco guarda (a data de cadastro do cliente). --}}
            <x-kpi-card rotulo="Clientes ativos" :valor="number_format($totalClientesAtivos, 0, ',', '.')"
                        :delta="$novosClientes > 0 ? '+'.$novosClientes.' no mês' : 'nenhum novo no mês'"
                        :sinal="$novosClientes > 0 ? 'bom' : 'neutro'"
                        :serie="$serieClientes"
                        acento="brand" icone="users" />
            <x-kpi-card rotulo="Revendas ativas" :valor="number_format($totalRevendasAtivas, 0, ',', '.')"
                        :serie="$serieRevendas"
                        acento="amber" icone="building" />
            {{-- A curva do MRR é o FATURADO de cada fechamento — a única
                 história que existe. O número grande é o estimado de hoje, que
                 não tem foto mensal. Por isso o delta diz de onde vem a linha:
                 sem essa palavra, seriam duas medidas no mesmo card sem aviso. --}}
            <x-kpi-card rotulo="MRR de atacado" :valor="'R$ '.number_format($mrrEstimado, 2, ',', '.')"
                        :delta="count($serieMrr) > 1 ? 'linha: fechamentos anteriores' : null"
                        :serie="$serieMrr"
                        acento="chart-out" icone="repeat" />
        </div>

        {{-- O destaque da tela: os dois rankings, em três camadas. --}}
        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(420px, 1fr))">
            {{-- "Licenças ativas", e não "Clientes ativos": este total soma o
                 nº de clientes de CADA produto, então um cliente com dois
                 sistemas entra duas vezes. Chamá-lo de clientes ativos punha
                 dois números diferentes sob o mesmo nome na mesma tela. --}}
            <x-ranking :ranking="$rankingClientes"
                       titulo="Produtos por clientes ativos"
                       nota="quem tem mais base instalada"
                       rotuloTotal="Licenças ativas" />

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
