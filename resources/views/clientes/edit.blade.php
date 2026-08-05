<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-ink leading-tight">Editar cliente — {{ $cliente->nome }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-panel overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('clientes.update', $cliente) }}">
                    @method('PUT')
                    @include('clientes._form')
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
