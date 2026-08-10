<x-app-layout>
    <x-slot name="titulo">Clientes</x-slot>
    <x-slot name="contexto">{{ $clientes->total() }} cadastrados</x-slot>
    <x-slot name="acoes">
        {{-- Só quem pode incluir: o perfil `financeiro` lê clientes mas não
             cadastra, e veria um botão que termina em 403 no POST. --}}
        @if (auth()->user()->canPermissao('clientes', 'incluir'))
            <button type="button" x-data @click="$dispatch('open-modal', 'novo-cliente')"
                    class="h-[34px] px-3 inline-flex items-center rounded-control bg-brand text-on-brand font-semibold text-[12.5px] hover:bg-brand-bright transition whitespace-nowrap">
                + Novo cliente
            </button>
        @endif
    </x-slot>

    <div class="space-y-4">
        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif

        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr))">
            <x-kpi-card rotulo="Clientes cadastrados" :valor="number_format($kpis['cadastrados']['valor'], 0, ',', '.')"
                        :delta="$kpis['cadastrados']['nota']" acento="accent" icone="users" />
            <x-kpi-card rotulo="Em contrato" :valor="number_format($kpis['contrato']['valor'], 0, ',', '.')"
                        :delta="$kpis['contrato']['nota']" acento="brand" icone="repeat" />
            <x-kpi-card rotulo="Avulsos" :valor="number_format($kpis['avulsos']['valor'], 0, ',', '.')"
                        :delta="$kpis['avulsos']['nota']" acento="amber" icone="clipboard" />
            <x-kpi-card rotulo="Ticket médio" :valor="'R$ '.number_format($kpis['ticket']['valor'], 2, ',', '.')"
                        :delta="$kpis['ticket']['nota']" acento="good" icone="banknotes" />
        </div>

        @include('clientes._tabela')
    </div>

    @include('clientes._modal-novo', ['revendas' => $revendasParaCadastro])
</x-app-layout>
