<x-app-layout>
    <x-slot name="caminho">
        <x-migalhas :caminho="[['rotulo' => 'Receitas', 'rota' => route('cobrancas.index')]]"
                    :atual="$cobranca->descricao" />
    </x-slot>
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
