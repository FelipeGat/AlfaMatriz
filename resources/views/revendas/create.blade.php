<x-app-layout>
    <x-slot name="caminho">
        <x-migalhas :caminho="[['rotulo' => 'Revendas', 'rota' => route('revendas.index')]]"
                    :atual="'Nova revenda'" />
    </x-slot>

    <div class="" style="max-width: 1000px">
            <div class="bg-panel overflow-hidden sm:rounded-card p-6">
                <form method="POST" action="{{ route('revendas.store') }}">
                    @include('revendas._form')
                </form>
            </div>
        </div>
</x-app-layout>
