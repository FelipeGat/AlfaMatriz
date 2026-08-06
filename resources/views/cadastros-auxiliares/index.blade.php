<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-ink leading-tight">Cadastros auxiliares</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="p-4 bg-good/12 text-good rounded-control text-sm">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="p-4 bg-bad/10 text-bad rounded-control text-sm">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Centros de custo --}}
                <div class="bg-panel sm:rounded-card p-6">
                    <h3 class="font-semibold text-ink mb-4">Centros de custo</h3>
                    <ul class="divide-y divide-line mb-4">
                        @forelse ($centrosCusto as $centro)
                            <li class="py-2 flex items-center justify-between text-sm">
                                <span>{{ $centro->nome }}</span>
                                <form action="{{ route('centros-custo.destroy', $centro) }}" method="POST" onsubmit="return confirm('Remover?');">
                                    @csrf @method('DELETE')
                                    <button class="text-bad hover:opacity-80 text-xs">Remover</button>
                                </form>
                            </li>
                        @empty
                            <li class="py-2 text-sm text-dim">Nenhum centro de custo.</li>
                        @endforelse
                    </ul>
                    <form action="{{ route('centros-custo.store') }}" method="POST" class="flex gap-2">
                        @csrf
                        <input type="text" name="nome" placeholder="Nome do centro de custo" class="flex-1 text-sm border-line rounded-control" required>
                        <button class="px-3 py-2 bg-brand text-white text-xs rounded-control uppercase font-semibold">Adicionar</button>
                    </form>
                </div>

                {{-- Fornecedores --}}
                <div class="bg-panel sm:rounded-card p-6">
                    <h3 class="font-semibold text-ink mb-4">Fornecedores</h3>
                    <ul class="divide-y divide-line mb-4 max-h-56 overflow-y-auto">
                        @forelse ($fornecedores as $fornecedor)
                            <li class="py-2 flex items-center justify-between text-sm">
                                <span>{{ $fornecedor->razao_social }}</span>
                                <form action="{{ route('fornecedores.destroy', $fornecedor) }}" method="POST" onsubmit="return confirm('Remover?');">
                                    @csrf @method('DELETE')
                                    <button class="text-bad hover:opacity-80 text-xs">Remover</button>
                                </form>
                            </li>
                        @empty
                            <li class="py-2 text-sm text-dim">Nenhum fornecedor.</li>
                        @endforelse
                    </ul>
                    <form action="{{ route('fornecedores.store') }}" method="POST" class="flex gap-2">
                        @csrf
                        <input type="text" name="razao_social" placeholder="Razão social" class="flex-1 text-sm border-line rounded-control" required>
                        <button class="px-3 py-2 bg-brand text-white text-xs rounded-control uppercase font-semibold">Adicionar</button>
                    </form>
                </div>
            </div>

            {{-- Árvore de categorias --}}
            <div class="bg-panel sm:rounded-card p-6">
                <h3 class="font-semibold text-ink mb-4">Categorias → Subcategorias → Contas</h3>

                <div class="space-y-4 mb-6">
                    @forelse ($categorias as $categoria)
                        <div class="border border-line rounded-control p-4">
                            <div class="flex items-center justify-between">
                                <span class="font-medium text-sm">
                                    {{ $categoria->nome }}
                                    <span class="ml-2 px-2 py-0.5 text-xs rounded-full {{ $categoria->tipo === 'receita' ? 'bg-good/12 text-good' : 'bg-bad/15 text-bad' }}">
                                        {{ ucfirst($categoria->tipo) }}
                                    </span>
                                </span>
                                <form action="{{ route('categorias.destroy', $categoria) }}" method="POST" onsubmit="return confirm('Remover categoria e subcategorias?');">
                                    @csrf @method('DELETE')
                                    <button class="text-bad hover:opacity-80 text-xs">Remover</button>
                                </form>
                            </div>

                            <div class="mt-3 ml-4 space-y-2">
                                @foreach ($categoria->subcategorias as $subcategoria)
                                    <div class="text-sm">
                                        <div class="flex items-center justify-between">
                                            <span class="text-dim">↳ {{ $subcategoria->nome }}</span>
                                            <form action="{{ route('subcategorias.destroy', $subcategoria) }}" method="POST" onsubmit="return confirm('Remover subcategoria e contas?');">
                                                @csrf @method('DELETE')
                                                <button class="text-bad hover:opacity-80 text-xs">Remover</button>
                                            </form>
                                        </div>
                                        <div class="ml-6 mt-1 flex flex-wrap gap-2">
                                            @foreach ($subcategoria->contas as $conta)
                                                <form action="{{ route('contas.destroy', $conta) }}" method="POST" onsubmit="return confirm('Remover conta?');" class="inline-flex items-center gap-1 bg-raised rounded-full px-2 py-1 text-xs">
                                                    @csrf @method('DELETE')
                                                    <span>{{ $conta->nome }}</span>
                                                    <button class="text-bad">×</button>
                                                </form>
                                            @endforeach
                                            <form action="{{ route('contas.store') }}" method="POST" class="inline-flex items-center gap-1">
                                                @csrf
                                                <input type="hidden" name="subcategoria_id" value="{{ $subcategoria->id }}">
                                                <input type="text" name="nome" placeholder="+ conta" class="text-xs border-line rounded-control w-28">
                                                <button class="text-xs text-brand">Add</button>
                                            </form>
                                        </div>
                                    </div>
                                @endforeach

                                <form action="{{ route('subcategorias.store') }}" method="POST" class="flex gap-2 mt-2">
                                    @csrf
                                    <input type="hidden" name="categoria_id" value="{{ $categoria->id }}">
                                    <input type="text" name="nome" placeholder="+ subcategoria" class="text-xs border-line rounded-control flex-1">
                                    <button class="text-xs text-brand">Adicionar</button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-dim">Nenhuma categoria cadastrada.</p>
                    @endforelse
                </div>

                <form action="{{ route('categorias.store') }}" method="POST" class="flex gap-2">
                    @csrf
                    <input type="text" name="nome" placeholder="Nova categoria" class="flex-1 text-sm border-line rounded-control" required>
                    <select name="tipo" class="text-sm border-line rounded-control">
                        <option value="despesa">Despesa</option>
                        <option value="receita">Receita</option>
                    </select>
                    <button class="px-3 py-2 bg-brand text-white text-xs rounded-control uppercase font-semibold">Adicionar</button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
