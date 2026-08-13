<x-app-layout>
    <x-slot name="caminho">
        <x-migalhas :caminho="[['rotulo' => 'Clientes', 'rota' => route('clientes.index')]]"
                    :atual="$cliente->nome_exibicao" />
    </x-slot>
    <x-slot name="contexto">{{ $cliente->nome_exibicao }}</x-slot>

    {{-- Formulário respira melhor com largura limitada; a tabela é que não
         pode ter, porque desliza inteira ao recolher o menu. --}}
    <div class="space-y-6" style="max-width: 1000px">
        <form method="POST" action="{{ route('clientes.update', $cliente) }}">
            @method('PUT')
            @include('clientes._form', ['modo' => 'editar'])
        </form>

        {{-- Depois do formulário, e não ao lado: o histórico é consulta, e
             disputar a largura com os campos empurraria o formulário para
             metade da tela em nome de algo que se abre uma vez por mês. --}}
        <x-linha-do-tempo :registro="$cliente" />
    </div>
</x-app-layout>
