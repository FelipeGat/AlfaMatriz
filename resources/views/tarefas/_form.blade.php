@php
    /**
     * Formulário de tarefa, compartilhado entre o modal de criação e o de
     * edição de cada card (T-063). `$tarefa` nulo é criação; com valor, é
     * edição — o `$sufixo` evita ids duplicados quando o modal de edição se
     * repete uma vez por card no quadro.
     */
    $sufixo = $tarefa?->id ?? 'nova';
@endphp

<form method="POST"
      action="{{ $tarefa ? route('tarefas.update', $tarefa) : route('tarefas.store') }}"
      class="p-6 space-y-4">
    @csrf
    @if ($tarefa)
        @method('PUT')
    @endif

    <h3 class="font-display font-semibold text-ink text-lg">
        {{ $tarefa ? 'Editar tarefa' : 'Nova tarefa' }}
    </h3>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2">
            <x-input-label for="titulo-{{ $sufixo }}" value="Título" />
            <x-text-input id="titulo-{{ $sufixo }}" name="titulo" type="text" class="mt-1 block w-full"
                          value="{{ old('titulo', $tarefa->titulo ?? '') }}" required />
        </div>

        <div>
            <x-input-label for="sistema_id-{{ $sufixo }}" value="Sistema" />
            <select id="sistema_id-{{ $sufixo }}" name="sistema_id" class="mt-1 block w-full">
                <option value="">—</option>
                @foreach ($sistemas as $sistema)
                    <option value="{{ $sistema->id }}"
                            @selected(old('sistema_id', $tarefa->sistema_id ?? '') == $sistema->id)>
                        {{ $sistema->nome }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <x-input-label for="responsavel_id-{{ $sufixo }}" value="Responsável" />
            <select id="responsavel_id-{{ $sufixo }}" name="responsavel_id" class="mt-1 block w-full">
                <option value="">—</option>
                @foreach ($usuarios as $usuario)
                    <option value="{{ $usuario->id }}"
                            @selected(old('responsavel_id', $tarefa->responsavel_id ?? '') == $usuario->id)>
                        {{ $usuario->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <x-input-label for="prioridade-{{ $sufixo }}" value="Prioridade" />
            <select id="prioridade-{{ $sufixo }}" name="prioridade" class="mt-1 block w-full">
                @foreach (\App\Models\Tarefa::PRIORIDADES as $chave => $label)
                    <option value="{{ $chave }}"
                            @selected(old('prioridade', $tarefa->prioridade ?? 'media') === $chave)>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="flex justify-end gap-3">
        <x-secondary-button x-on:click="$dispatch('close')">Cancelar</x-secondary-button>
        <x-primary-button>Salvar</x-primary-button>
    </div>
</form>
