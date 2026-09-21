<x-app-layout>
    <x-slot name="titulo">Agenda</x-slot>
    <x-slot name="contexto">Prazos de tarefas e compromissos do time</x-slot>

    @if (session('status'))
        <x-aviso tom="bom">{{ session('status') }}</x-aviso>
    @endif
    @if (session('erro'))
        <x-aviso tom="critico">{{ session('erro') }}</x-aviso>
    @endif

    {{--
        A tela inteira cabe na janela e rola POR DENTRO — `calc(100vh - 120px)`
        é a altura que sobra sob a topbar e o cabeçalho. A alternativa, deixar a
        página rolar, faria a barra de visão e o botão de novo compromisso
        subirem para fora da tela justamente quando há muita coisa marcada, que
        é quando eles mais servem.
    --}}
    <div x-data="agendaTela({
            visao: @js($visao),
            em: @js($em->toDateString()),
            hoje: @js($hoje->toDateString()),
            pessoas: @js($pessoas),
            compromissos: @js($compromissos),
            equipe: @js($equipe),
            podeReagendar: @js($podeReagendar),
            usuarioId: @js($usuarioId),
            tarefas: @js($tarefasVinculaveis),
         })"
         class="flex flex-col gap-3.5"
         style="height: calc(100vh - 120px)">

        @include('agenda._barra')

        @include('agenda._legenda')

        @if ($visao === 'semana')
            @include('agenda._semana')
        @elseif ($visao === 'mes')
            @include('agenda._mes')
        @else
            @include('agenda._lista')
        @endif

        @include('agenda._drawer')
        @include('agenda._modal-tarefa')
        @include('agenda._modal-compromisso')
    </div>

    @include('agenda._alpine')
</x-app-layout>
