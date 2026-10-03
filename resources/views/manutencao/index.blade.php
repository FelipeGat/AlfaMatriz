<x-app-layout>
    <x-slot name="titulo">Manutenção e atualizações</x-slot>

    @if ($aba === 'erros')
        <x-slot name="contexto">
            {{ $kpis['vigiados'] }} {{ $kpis['vigiados'] === 1 ? 'erro vigiado' : 'erros vigiados' }}
            em {{ $sistemas->count() }} {{ $sistemas->count() === 1 ? 'sistema' : 'sistemas' }}
        </x-slot>
    @endif

    <div class="space-y-4">
        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif

        @if ($errors->any())
            <x-aviso tom="critico" :segundos="0">{{ $errors->first() }}</x-aviso>
        @endif

        {{-- Erros vem primeiro porque é o que pede ação: as outras duas abas
             são registro e agenda, a de erros é o que está quebrando agora. --}}
        <x-abas>
            <x-abas.item :href="route('manutencao.index', ['aba' => 'erros'])"
                         :ativo="$aba === 'erros'" icone="alert-triangle">
                Erros
            </x-abas.item>
            <x-abas.item :href="route('manutencao.index', ['aba' => 'atualizacoes'])"
                         :ativo="$aba === 'atualizacoes'" icone="document">
                Atualizações
            </x-abas.item>
            <x-abas.item :href="route('manutencao.index', ['aba' => 'programadas'])"
                         :ativo="$aba === 'programadas'" icone="clock">
                Programadas
            </x-abas.item>
        </x-abas>

        @if ($aba === 'erros')
            @include('manutencao._erros')
        @else
            @include('manutencao._em-breve')
        @endif
    </div>
</x-app-layout>
