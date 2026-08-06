<x-app-layout>
    <x-slot name="titulo">Editar receita</x-slot>
    <x-slot name="contexto">CONTAS A RECEBER · {{ $cobranca->descricao }}</x-slot>

    <div class="max-w-4xl">
        <x-painel titulo="Dados da receita">
            <form method="POST" action="{{ route('cobrancas.update', $cobranca) }}">
                @method('PUT')
                @include('cobrancas._form')
            </form>
        </x-painel>
    </div>
</x-app-layout>
