<x-app-layout>
    <x-slot name="titulo">Nova receita</x-slot>
    <x-slot name="contexto">CONTAS A RECEBER</x-slot>

    <div class="max-w-4xl">
        <x-painel titulo="Dados da receita">
            <form method="POST" action="{{ route('cobrancas.store') }}">
                @include('cobrancas._form')
            </form>
        </x-painel>
    </div>
</x-app-layout>
