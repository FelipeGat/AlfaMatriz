<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-ink leading-tight">Editar receita</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-panel overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('cobrancas.update', $cobranca) }}">
                    @method('PUT')
                    @include('cobrancas._form')
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
