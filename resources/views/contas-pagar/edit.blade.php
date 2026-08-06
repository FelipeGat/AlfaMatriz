<x-app-layout>
    <x-slot name="caminho">
        <x-migalhas :caminho="[['rotulo' => 'Despesas', 'rota' => route('contas-pagar.index')]]"
                    :atual="'Editar despesa'" />
    </x-slot>

    <div class="" style="max-width: 1000px">
            <div class="bg-panel overflow-hidden sm:rounded-card p-6">
                <form method="POST" action="{{ route('contas-pagar.update', $contaPagar) }}">
                    @method('PUT')
                    @include('contas-pagar._form')
                </form>
            </div>
        </div>
</x-app-layout>
