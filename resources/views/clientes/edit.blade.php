<x-app-layout>
    <x-slot name="caminho">
        <x-migalhas :caminho="[['rotulo' => 'Clientes', 'rota' => route('clientes.index')]]"
                    :atual="$cliente->nome_exibicao" />
    </x-slot>
    <x-slot name="contexto">{{ $cliente->nome_exibicao }}</x-slot>

    {{-- Formulário respira melhor com largura limitada; a tabela é que não
         pode ter, porque desliza inteira ao recolher o menu. --}}
    <form method="POST" action="{{ route('clientes.update', $cliente) }}" style="max-width: 1000px">
        @method('PUT')
        @include('clientes._form', ['modo' => 'editar'])
    </form>
</x-app-layout>
