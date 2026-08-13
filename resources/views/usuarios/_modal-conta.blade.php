{{-- Criar e editar conta. Espera: $usuarios, $perfis, $revendas.

    `:show` reabre o modal quando a validação recusa. Sem isso, o erro devolve
    a lista com o modal FECHADO — a tela parece não ter feito nada e a pessoa
    reabre para digitar tudo de novo. O sentinela `origem` diz QUAL formulário
    falhou: a página tem um modal por conta listada, e sem ele todos abriam de
    uma vez. --}}

<x-modal name="novo-usuario" maxWidth="lg" :show="$errors->any() && old('origem') === 'novo'">
    <form method="POST" action="{{ route('usuarios.store') }}" class="p-5">
        @csrf
        <input type="hidden" name="origem" value="novo">

        <h2 class="font-display text-[15.5px] font-semibold text-ink">Nova conta</h2>
        <p class="text-[12.5px] text-ink-mute mt-0.5 mb-4">
            A senha é gerada agora e aparece uma única vez, para você repassar.
        </p>

        @include('usuarios._form', ['usuario' => null, 'idForm' => 'novo'])
    </form>
</x-modal>

@foreach ($usuarios as $usuario)
    <x-modal name="editar-usuario-{{ $usuario->id }}" maxWidth="lg"
             :show="$errors->any() && old('origem') === 'editar-'.$usuario->id">
        <form method="POST" action="{{ route('usuarios.update', $usuario) }}" class="p-5">
            @csrf
            @method('PUT')
            <input type="hidden" name="origem" value="editar-{{ $usuario->id }}">

            <h2 class="font-display text-[15.5px] font-semibold text-ink mb-4">
                Conta de {{ $usuario->name }}
            </h2>

            @include('usuarios._form', ['usuario' => $usuario, 'idForm' => 'editar-'.$usuario->id])
        </form>
    </x-modal>
@endforeach
