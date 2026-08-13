<x-app-layout>
    <x-slot name="titulo">Usuários e permissões</x-slot>

    <x-slot name="contexto">
        @if ($aba === 'perfis')
            {{ $perfisComGrade->count() }} perfis de acesso
        @else
            {{ $usuarios->total() }} de {{ $kpis['ativos'] + $kpis['desativados'] }} contas
        @endif
    </x-slot>

    <x-slot name="acoes">
        {{-- A ação segue a aba: na de perfis não há nada a criar — os perfis
             vêm do seeder, e o que se edita ali é a grade de cada um. --}}
        @if ($aba === 'usuarios')
            <button type="button" x-data @click="$dispatch('open-modal', 'novo-usuario')"
                    class="h-[34px] px-3 inline-flex items-center rounded-control bg-brand text-on-brand font-semibold text-[12.5px] hover:bg-brand-bright transition whitespace-nowrap">
                + Nova conta
            </button>
        @endif
    </x-slot>

    <div class="space-y-4">
        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif

        @if (session('erro'))
            <x-aviso tom="critico">{{ session('erro') }}</x-aviso>
        @endif

        <x-abas>
            <x-abas.item href="{{ route('usuarios.index', ['aba' => 'usuarios']) }}"
                         :ativo="$aba === 'usuarios'" icone="users">
                Contas
            </x-abas.item>
            <x-abas.item href="{{ route('usuarios.index', ['aba' => 'perfis']) }}"
                         :ativo="$aba === 'perfis'" icone="cadeado-fechado">
                Perfis e permissões
            </x-abas.item>
        </x-abas>

        @if ($aba === 'perfis')
            @include('usuarios._permissoes')
        @else
            <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr))">
                <x-kpi-card rotulo="Contas ativas" :valor="$kpis['ativos']" acento="brand" icone="users" />
                <x-kpi-card rotulo="Desativadas" :valor="$kpis['desativados']" acento="amber" icone="cadeado-fechado" />
                <x-kpi-card rotulo="Administradores" :valor="$kpis['admins']" acento="accent" icone="settings" />
                <x-kpi-card rotulo="Aguardando 1º acesso" :valor="$kpis['pendentes']"
                            delta="senha ainda emprestada" acento="good" icone="clock" />
            </div>

            @include('usuarios._tabela')
        @endif
    </div>

    @include('usuarios._modal-conta')
    @include('usuarios._modal-senha')
</x-app-layout>
