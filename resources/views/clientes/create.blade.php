<x-app-layout>
    <x-slot name="caminho">
        <x-migalhas :caminho="[['rotulo' => 'Clientes', 'rota' => route('clientes.index')]]"
                    :atual="'Novo cliente'" />
    </x-slot>
    <x-slot name="contexto">cadastro</x-slot>

    <form method="POST" action="{{ route('clientes.store') }}" style="max-width: 1000px">
        @include('clientes._form')
    </form>
</x-app-layout>
