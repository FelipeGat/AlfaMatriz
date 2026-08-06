<x-app-layout>
    <x-slot name="caminho">
        <x-migalhas :caminho="[['rotulo' => 'Caixa', 'rota' => route('contas-financeiras.index')]]"
                    :atual="$contaFinanceira->nome" />
    </x-slot>

    <div class="" style="max-width: 1000px">
            <div class="bg-panel overflow-hidden sm:rounded-card p-6">
                <form method="POST" action="{{ route('contas-financeiras.update', $contaFinanceira) }}">
                    @method('PUT')
                    @include('contas-financeiras._form')
                </form>
            </div>
        </div>
</x-app-layout>
