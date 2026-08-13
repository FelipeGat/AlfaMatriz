<x-app-layout>
    <x-slot name="caminho">
        <x-migalhas :caminho="[['rotulo' => 'Produtos', 'rota' => route('produtos.index', $natureza === 'interno' ? ['aba' => 'internos'] : [])]]"
                    :atual="$natureza === 'interno' ? 'Novo sistema interno' : 'Novo produto'" />
    </x-slot>

    <div class="space-y-6" style="max-width: 1000px">
        @if ($errors->any())
            <div class="p-4 bg-status-critical/10 text-status-critical rounded-md text-sm">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="bg-panel overflow-hidden shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-ink mb-4">Cadastro</h3>

            <form method="POST" action="{{ route('sistemas.store') }}">
                @csrf

                @include('sistemas._form', ['sistema' => null, 'natureza' => $natureza])

                {{-- Tier de atacado não cabe aqui: ele depende do sistema já
                     existir para pendurar o preço. Dizer onde ele está evita a
                     conclusão de que o cadastro ficou incompleto. --}}
                <p class="mt-6 text-xs text-ink-mute">
                    Os tiers de atacado são configurados depois de salvar, na tela do sistema.
                </p>

                <div class="flex items-center justify-end gap-3 mt-6">
                    <a href="{{ route('produtos.index', $natureza === 'interno' ? ['aba' => 'internos'] : []) }}"
                       class="text-sm text-ink-dim hover:text-ink">Cancelar</a>
                    <x-primary-button>Cadastrar sistema</x-primary-button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
