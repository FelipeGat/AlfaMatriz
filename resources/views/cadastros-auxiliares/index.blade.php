<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2 text-[14px]">
            <span class="text-mute">Sistema</span>
            <span class="text-line">/</span>
            <span class="font-medium text-ink">Cadastros</span>
        </div>
    </x-slot>

    <div class="space-y-[18px]">
        @if ($errors->any())
            <div class="rounded-control border border-line bg-raised px-4 py-2.5 text-[12.5px] text-bad">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
            <x-summary-card label="Centros de custo" :value="$centrosCusto->count()" contexto="para classificar despesas" />
            <x-summary-card label="Fornecedores" :value="$fornecedores->count()" contexto="cadastrados" />
            <x-summary-card label="Categorias" :value="$categorias->count()" contexto="de receita e despesa" />
            <x-summary-card
                label="Contas contábeis"
                :value="$categorias->sum(fn ($c) => $c->subcategorias->sum(fn ($s) => $s->contas->count()))"
                contexto="no nível mais fino" />
        </div>

        <div class="flex flex-wrap gap-[14px]">
            <x-painel-card titulo="Centros de custo" style="flex: 1 1 320px;">
                <ul class="divide-y divide-line">
                    @forelse ($centrosCusto as $centro)
                        <li class="flex items-center justify-between gap-3 py-2.5">
                            <span class="truncate text-[14px] text-ink">{{ $centro->nome }}</span>
                            <form action="{{ route('centros-custo.destroy', $centro) }}" method="POST" onsubmit="return confirm('Remover este centro de custo?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="shrink-0 text-[12px] text-dim transition-colors hover:text-bad">Remover</button>
                            </form>
                        </li>
                    @empty
                        <li class="py-[34px] text-center text-[14px] text-mute">Nenhum centro de custo.</li>
                    @endforelse
                </ul>

                <form action="{{ route('centros-custo.store') }}" method="POST" class="mt-4 flex gap-2 border-t border-line pt-4">
                    @csrf
                    <input type="text" name="nome" placeholder="Nome do centro de custo" required
                           class="h-8 flex-1 rounded-control text-[12.5px]">
                    <x-primary-button>Adicionar</x-primary-button>
                </form>
            </x-painel-card>

            <x-painel-card titulo="Fornecedores" style="flex: 1 1 320px;">
                <x-slot name="acao">
                    <span class="valor text-mute">{{ $fornecedores->count() }}</span>
                </x-slot>

                <ul class="sem-scrollbar max-h-56 divide-y divide-line overflow-y-auto">
                    @forelse ($fornecedores as $fornecedor)
                        <li class="flex items-center justify-between gap-3 py-2.5">
                            <span class="truncate text-[14px] text-ink">{{ $fornecedor->razao_social }}</span>
                            <form action="{{ route('fornecedores.destroy', $fornecedor) }}" method="POST" onsubmit="return confirm('Remover este fornecedor?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="shrink-0 text-[12px] text-dim transition-colors hover:text-bad">Remover</button>
                            </form>
                        </li>
                    @empty
                        <li class="py-[34px] text-center text-[14px] text-mute">Nenhum fornecedor.</li>
                    @endforelse
                </ul>

                <form action="{{ route('fornecedores.store') }}" method="POST" class="mt-4 flex gap-2 border-t border-line pt-4">
                    @csrf
                    <input type="text" name="razao_social" placeholder="Razão social" required
                           class="h-8 flex-1 rounded-control text-[12.5px]">
                    <x-primary-button>Adicionar</x-primary-button>
                </form>
            </x-painel-card>
        </div>

        <x-painel-card titulo="Plano de contas">
            <p class="-mt-1 mb-4 text-[12.5px] text-mute">Categoria → subcategoria → conta. É o que classifica cada lançamento do financeiro.</p>

            <div class="space-y-3">
                @forelse ($categorias as $categoria)
                    <div class="rounded-control border border-line p-4">
                        <div class="flex items-center justify-between gap-3">
                            <span class="flex items-center gap-2 truncate text-[14px] font-medium text-ink">
                                {{ $categoria->nome }}
                                <x-status-pill :tom="$categoria->tipo === 'receita' ? 'good' : 'neutro'">
                                    {{ $categoria->tipo }}
                                </x-status-pill>
                            </span>
                            <form action="{{ route('categorias.destroy', $categoria) }}" method="POST" onsubmit="return confirm('Remover a categoria e tudo abaixo dela?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="shrink-0 text-[12px] text-dim transition-colors hover:text-bad">Remover</button>
                            </form>
                        </div>

                        <div class="mt-3 space-y-2.5 border-l border-line pl-4">
                            @foreach ($categoria->subcategorias as $subcategoria)
                                <div>
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="truncate text-[12.5px] text-dim">{{ $subcategoria->nome }}</span>
                                        <form action="{{ route('subcategorias.destroy', $subcategoria) }}" method="POST" onsubmit="return confirm('Remover a subcategoria e suas contas?');">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="shrink-0 text-[11px] text-mute transition-colors hover:text-bad">Remover</button>
                                        </form>
                                    </div>

                                    <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                        @foreach ($subcategoria->contas as $conta)
                                            <form action="{{ route('contas.destroy', $conta) }}" method="POST" onsubmit="return confirm('Remover esta conta?');"
                                                  class="inline-flex items-center gap-1.5 rounded-pill border border-line bg-raised px-2 py-0.5">
                                                @csrf @method('DELETE')
                                                <span class="text-[11.5px] text-dim">{{ $conta->nome }}</span>
                                                <button type="submit" class="text-mute transition-colors hover:text-bad" aria-label="Remover {{ $conta->nome }}">×</button>
                                            </form>
                                        @endforeach

                                        <form action="{{ route('contas.store') }}" method="POST" class="inline-flex items-center gap-1">
                                            @csrf
                                            <input type="hidden" name="subcategoria_id" value="{{ $subcategoria->id }}">
                                            <input type="text" name="nome" placeholder="nova conta"
                                                   class="h-7 w-32 rounded-control text-[11.5px]">
                                            <button type="submit" class="text-[11.5px] text-dim transition-colors hover:text-ink">Add</button>
                                        </form>
                                    </div>
                                </div>
                            @endforeach

                            <form action="{{ route('subcategorias.store') }}" method="POST" class="flex gap-2 pt-1">
                                @csrf
                                <input type="hidden" name="categoria_id" value="{{ $categoria->id }}">
                                <input type="text" name="nome" placeholder="nova subcategoria"
                                       class="h-7 flex-1 rounded-control text-[11.5px]">
                                <button type="submit" class="text-[11.5px] text-dim transition-colors hover:text-ink">Adicionar</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="py-[34px] text-center text-[14px] text-mute">Nenhuma categoria cadastrada.</p>
                @endforelse
            </div>

            <form action="{{ route('categorias.store') }}" method="POST" class="mt-4 flex flex-wrap gap-2 border-t border-line pt-4">
                @csrf
                <input type="text" name="nome" placeholder="Nova categoria" required
                       class="h-8 min-w-[200px] flex-1 rounded-control text-[12.5px]">
                <select name="tipo" class="h-8 rounded-control py-0 text-[12.5px]">
                    <option value="despesa">Despesa</option>
                    <option value="receita">Receita</option>
                </select>
                <x-primary-button>Adicionar</x-primary-button>
            </form>
        </x-painel-card>
    </div>
</x-app-layout>
