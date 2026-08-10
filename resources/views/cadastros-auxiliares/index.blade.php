<x-app-layout>
    <x-slot name="titulo">Cadastros auxiliares</x-slot>
    <x-slot name="contexto">CENTROS DE CUSTO · FORNECEDORES · PLANO DE CONTAS</x-slot>

    @if (session('status'))
        <x-aviso class="mb-4">{{ session('status') }}</x-aviso>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-control border border-crit-tint bg-crit-tint px-4 py-3 text-sm text-crit">{{ $errors->first() }}</div>
    @endif

    <div class="flex flex-col gap-4">
        {{--
            Centros de custo e fornecedores: mesma gramática de lista, cada
            item mostra quantos lançamentos dependem dele ao lado do botão de
            remover (AC-058) — é o dado que decide se remover é seguro.
        --}}
        <div class="grid gap-4" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr))">
            <x-painel titulo="Centros de custo" :sub="$centrosCusto->count().' cadastrado(s)'" solto>
                <div class="max-h-[232px] overflow-y-auto">
                    @forelse ($centrosCusto as $centro)
                        <div class="flex items-center gap-3 px-4 h-[42px] border-b border-rule">
                            <span class="h-[26px] w-[26px] shrink-0 rounded-tile bg-chip text-ink-mute flex items-center justify-center">
                                <span class="h-[14px] w-[14px]"><x-nav-icon name="clipboard" /></span>
                            </span>
                            <span class="flex-1 min-w-0 text-[13.5px] text-ink truncate">{{ $centro->nome }}</span>
                            <span class="shrink-0 font-mono text-[11px] text-ink-mute whitespace-nowrap">{{ $centro->contas_pagar_count }} lançamento(s)</span>
                            <x-confirmar :action="route('centros-custo.destroy', $centro)" method="DELETE"
                                         icone="trash" destrutivo confirmar="Remover"
                                         titulo="Remover este centro de custo?"
                                         :mensagem="$centro->contas_pagar_count.' lançamento(s) usam este centro de custo. Removê-lo deixa esses lançamentos sem centro.'" />
                        </div>
                    @empty
                        <p class="px-4 py-6 text-sm text-ink-mute">Nenhum centro de custo.</p>
                    @endforelse
                </div>
                <div class="flex gap-2 px-4 py-3 bg-head border-t border-line">
                    <form action="{{ route('centros-custo.store') }}" method="POST" class="flex flex-1 gap-2">
                        @csrf
                        <input type="text" name="nome" placeholder="Nome do centro de custo"
                               class="flex-1 min-w-0 h-[34px] rounded-control border-line bg-input text-ink placeholder-ink-faint text-sm" required>
                        <button class="shrink-0 h-[34px] px-3.5 rounded-control bg-brand text-on-brand font-sans text-[12.5px] font-semibold hover:bg-brand-bright transition whitespace-nowrap">
                            Adicionar
                        </button>
                    </form>
                </div>
            </x-painel>

            <x-painel titulo="Fornecedores" :sub="$fornecedores->count().' cadastrado(s)'" solto>
                <div class="max-h-[232px] overflow-y-auto">
                    @forelse ($fornecedores as $fornecedor)
                        <div class="flex items-center gap-3 px-4 h-[42px] border-b border-rule">
                            <span class="h-[26px] w-[26px] shrink-0 rounded-tile bg-chip text-ink-mute flex items-center justify-center">
                                <span class="h-[14px] w-[14px]"><x-nav-icon name="building" /></span>
                            </span>
                            <span class="flex-1 min-w-0 text-[13.5px] text-ink truncate">{{ $fornecedor->razao_social }}</span>
                            <span class="shrink-0 font-mono text-[11px] text-ink-mute whitespace-nowrap">{{ $fornecedor->contas_pagar_count }} lançamento(s)</span>
                            <x-confirmar :action="route('fornecedores.destroy', $fornecedor)" method="DELETE"
                                         icone="trash" destrutivo confirmar="Remover"
                                         titulo="Remover este fornecedor?"
                                         :mensagem="$fornecedor->contas_pagar_count.' lançamento(s) usam este fornecedor. Removê-lo deixa esses lançamentos sem fornecedor.'" />
                        </div>
                    @empty
                        <p class="px-4 py-6 text-sm text-ink-mute">Nenhum fornecedor.</p>
                    @endforelse
                </div>
                <div class="flex gap-2 px-4 py-3 bg-head border-t border-line">
                    <form action="{{ route('fornecedores.store') }}" method="POST" class="flex flex-1 gap-2">
                        @csrf
                        <input type="text" name="razao_social" placeholder="Razão social"
                               class="flex-1 min-w-0 h-[34px] rounded-control border-line bg-input text-ink placeholder-ink-faint text-sm" required>
                        <button class="shrink-0 h-[34px] px-3.5 rounded-control bg-brand text-on-brand font-sans text-[12.5px] font-semibold hover:bg-brand-bright transition whitespace-nowrap">
                            Adicionar
                        </button>
                    </form>
                </div>
            </x-painel>
        </div>

        {{--
            Plano de contas na horizontal (AC-059): categoria é um bloco
            marcado pelo tipo, subcategoria é uma linha e as contas são chips
            ao lado — em vez de quatro níveis de indentação descendo a tela.
        --}}
        @php
            $totalSubcategorias = $categorias->sum(fn ($c) => $c->subcategorias->count());
            $totalContas = $categorias->sum(fn ($c) => $c->subcategorias->sum(fn ($s) => $s->contas->count()));
        @endphp
        <x-painel titulo="Plano de contas"
                  :sub="$categorias->count().' categoria(s) · '.$totalSubcategorias.' subcategoria(s) · '.$totalContas.' conta(s)'"
                  solto>
            <x-slot name="acoes">
                <span class="font-mono text-[10.5px] text-ink-mute whitespace-nowrap">categoria › subcategoria › conta</span>
            </x-slot>

            <div class="p-4 flex flex-col gap-3">
                @forelse ($categorias as $categoria)
                    @php
                        $receita = $categoria->tipo === 'receita';
                        $accent = $receita ? 'good' : 'crit';
                    @endphp
                    <div class="rounded-ctl border border-line overflow-hidden" style="border-left: 2px solid rgb(var(--{{ $accent }}))">
                        <div class="flex items-center gap-2.5 px-3.5 py-2.5 bg-head">
                            <span class="font-display text-[14px] font-semibold text-ink">{{ $categoria->nome }}</span>
                            <x-badge :tom="$receita ? 'bom' : 'critico'">{{ ucfirst($categoria->tipo) }}</x-badge>
                            <span class="ml-auto font-mono text-[10.5px] text-ink-mute whitespace-nowrap">
                                {{ $categoria->subcategorias->count() }} sub · {{ $categoria->subcategorias->sum(fn ($s) => $s->contas->count()) }} contas
                            </span>
                            <x-confirmar :action="route('categorias.destroy', $categoria)" method="DELETE"
                                         icone="trash" destrutivo confirmar="Remover"
                                         titulo="Remover este categoria?"
                                         :mensagem="'A categoria sai do plano de contas, junto com as subcategorias e contas dentro dela.'" />
                        </div>

                        @foreach ($categoria->subcategorias as $subcategoria)
                            <div class="flex gap-3 px-3.5 py-[11px] border-t border-rule">
                                <div class="flex-none w-[168px] min-w-0 flex items-center gap-2">
                                    <span class="font-mono text-[11px] text-ink-faint">↳</span>
                                    <span class="flex-1 min-w-0 text-[13px] font-medium text-ink truncate">{{ $subcategoria->nome }}</span>
                                    <x-confirmar :action="route('subcategorias.destroy', $subcategoria)" method="DELETE"
                                                 icone="x-mark" destrutivo confirmar="Remover"
                                                 titulo="Remover esta subcategoria?"
                                                 :mensagem="$subcategoria->nome.' sai do plano de contas, junto com as '.$subcategoria->contas->count().' conta(s) dentro dela.'" />
                                </div>
                                <div class="flex-1 min-w-0 flex flex-wrap gap-1.5 items-center">
                                    @foreach ($subcategoria->contas as $conta)
                                        <span class="inline-flex items-center gap-1.5 rounded-tile bg-chip pl-2 pr-1 py-[3px] text-[12px] text-ink-dim whitespace-nowrap"
                                              title="{{ $conta->contas_pagar_count }} lançamento(s)">
                                            {{ $conta->nome }}
                                            <x-confirmar :action="route('contas.destroy', $conta)" method="DELETE"
                                                         destrutivo confirmar="Remover"
                                                         :titulo="'Remover a conta '.$conta->nome.'?'"
                                                         :mensagem="$conta->contas_pagar_count.' lançamento(s) apontam para esta conta. Removê-la deixa esses lançamentos sem classificação.'"
                                                         class="h-[14px] w-[14px] items-center justify-center text-ink-mute hover:text-crit"
                                                         title="Remover conta">×</x-confirmar>
                                        </span>
                                    @endforeach
                                    <form action="{{ route('contas.store') }}" method="POST" class="inline-flex items-center">
                                        @csrf
                                        <input type="hidden" name="subcategoria_id" value="{{ $subcategoria->id }}">
                                        <input type="text" name="nome" placeholder="+ conta"
                                               class="h-[26px] w-28 rounded-tile border border-dashed border-btn-line bg-input text-ink text-[12px] px-2">
                                    </form>
                                </div>
                            </div>
                        @endforeach

                        <form action="{{ route('subcategorias.store') }}" method="POST" class="flex gap-2 px-3.5 py-2.5 border-t border-rule">
                            @csrf
                            <input type="hidden" name="categoria_id" value="{{ $categoria->id }}">
                            <input type="text" name="nome" placeholder="+ subcategoria em {{ $categoria->nome }}"
                                   class="flex-1 min-w-0 h-[30px] rounded-ctl border border-dashed border-btn-line bg-input text-ink text-[12.5px] px-2.5">
                            <button class="shrink-0 h-[30px] px-3 rounded-ctl border border-btn-line text-brand-text font-sans text-[12px] font-semibold whitespace-nowrap">
                                Adicionar
                            </button>
                        </form>
                    </div>
                @empty
                    <p class="text-sm text-ink-mute">Nenhuma categoria cadastrada.</p>
                @endforelse
            </div>

            <div class="flex gap-2 px-4 py-3 bg-head border-t border-line">
                <form action="{{ route('categorias.store') }}" method="POST" class="flex flex-1 gap-2">
                    @csrf
                    <input type="text" name="nome" placeholder="Nova categoria"
                           class="flex-1 min-w-0 h-[34px] rounded-control border-line bg-input text-ink placeholder-ink-faint text-sm" required>
                    <select name="tipo" class="shrink-0 h-[34px] py-0 rounded-control border-line bg-input text-ink-dim text-sm">
                        <option value="despesa">Despesa</option>
                        <option value="receita">Receita</option>
                    </select>
                    <button class="shrink-0 h-[34px] px-3.5 rounded-control bg-brand text-on-brand font-sans text-[12.5px] font-semibold hover:bg-brand-bright transition whitespace-nowrap">
                        Adicionar categoria
                    </button>
                </form>
            </div>
        </x-painel>
    </div>
</x-app-layout>
