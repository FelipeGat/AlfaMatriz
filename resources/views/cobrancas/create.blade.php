<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-ink leading-tight">Nova receita</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-panel overflow-hidden sm:rounded-card p-6">
                <form method="POST" action="{{ route('cobrancas.store') }}">
                    @include('cobrancas._form')
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
