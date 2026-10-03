<x-app-layout>
    <x-slot name="titulo">Manutenção e atualizações</x-slot>

    <x-slot name="contexto">
        <span id="manutencao-contexto">
        @if ($aba === 'erros')
            {{ $kpis['vigiados'] }} {{ $kpis['vigiados'] === 1 ? 'erro vigiado' : 'erros vigiados' }}
            em {{ $sistemas->count() }} {{ $sistemas->count() === 1 ? 'sistema' : 'sistemas' }}
        @elseif ($aba === 'atualizacoes')
            {{ $atualizacoes->total() }} {{ $atualizacoes->total() === 1 ? 'atualização publicada' : 'atualizações publicadas' }}
        @else
            {{ $quantas }} {{ $quantas === 1 ? 'janela marcada' : 'janelas marcadas' }}
        @endif
        </span>
    </x-slot>

    {{-- Atualizar (#235): busca a própria tela e troca só o miolo. A hora vem
         do servidor (`data-gerado-em`), e não do relógio de quem olha: é a hora
         do dado, no fuso do painel. --}}
    <x-slot name="acoes">
        <div x-data class="flex items-center gap-2">
            <span class="hidden sm:inline font-mono text-[11px] text-ink-faint whitespace-nowrap"
                  x-text="'atualizado às ' + $store.manutencao.hora"></span>
            <button type="button" @click="$dispatch('manutencao-atualizar')" :disabled="$store.manutencao.carregando"
                    class="h-[34px] px-3 inline-flex items-center gap-1.5 rounded-control border border-btn-line text-ink-dim
                           text-[12.5px] font-semibold hover:text-brand hover:border-brand transition whitespace-nowrap disabled:opacity-60">
                <span class="h-[14px] w-[14px]" :class="$store.manutencao.carregando ? 'animate-spin' : ''"><x-nav-icon name="repeat" /></span>
                Atualizar
            </button>
        </div>
    </x-slot>

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

        <div id="manutencao-conteudo" class="space-y-4" data-gerado-em="{{ now()->format('H:i') }}"
             x-data="manutencaoTela({ automatico: {{ $aba === 'erros' ? 'true' : 'false' }} })"
             @manutencao-atualizar.window="atualizar()">
            @include('manutencao._'.$aba)
        </div>
    </div>

    @include('manutencao._alpine')
</x-app-layout>
