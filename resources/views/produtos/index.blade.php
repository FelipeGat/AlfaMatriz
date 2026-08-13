<x-app-layout>
    <x-slot name="titulo">Produtos</x-slot>

    {{-- O contexto acompanha a aba: na de internos, as contagens do catálogo
         comercial falariam de uma lista que não está na tela. --}}
    <x-slot name="contexto">
        @if ($aba === 'internos')
            {{ $contagens['internos'] }} sistemas internos
        @else
            {{ $contagens['sistemas'] }} sistemas · {{ $contagens['ativos'] }} ativos
        @endif
    </x-slot>

    {{-- A ação segue a aba, como em Revendas: quem está na lista de internos
         quer cadastrar um interno, e um botão fixo em "Novo produto" o levaria
         a um formulário com os campos errados já escolhidos. --}}
    @if (auth()->user()->canPermissao('sistemas', 'incluir'))
        <x-slot name="acoes">
            <a href="{{ route('sistemas.create', $aba === 'internos' ? ['natureza' => 'interno'] : []) }}"
               class="h-[34px] px-3 inline-flex items-center rounded-control bg-brand text-on-brand font-semibold text-[12.5px] hover:bg-brand-bright transition whitespace-nowrap">
                {{ $aba === 'internos' ? '+ Novo sistema interno' : '+ Novo produto' }}
            </a>
        </x-slot>
    @endif

    {{--
        Sete cartões numa grade de três deixavam um buraco na última fila, e
        cada cartão repetia a mesma grade de métricas — muita moldura e nenhuma
        comparação possível. A lista ordenada por receita responde de cara qual
        produto sustenta a casa. Cartões continuam disponíveis, mas a lista é o
        padrão.
    --}}
    <div x-data="modoProdutos" class="space-y-4">
        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif

        {{--
            Duas populações, uma tela.

            O que a Alfa VENDE e o que a Alfa USA moram na mesma tabela e são
            perguntas diferentes: o produto responde por receita, o interno só
            existe para a tarefa ter onde apontar. Misturá-los numa lista só
            poria linhas de MRR zero no meio da comparação que a lista existe
            para permitir — e o zero de quem nunca foi vendido se leria como o
            zero de quem parou de vender.
        --}}
        <x-abas>
            <x-abas.item href="{{ route('produtos.index', array_merge(request()->query(), ['aba' => 'produtos'])) }}"
                         :ativo="$aba === 'produtos'" icone="cube-outline">
                Produtos · {{ $contagens['sistemas'] }}
            </x-abas.item>
            <x-abas.item href="{{ route('produtos.index', array_merge(request()->query(), ['aba' => 'internos'])) }}"
                         :ativo="$aba === 'internos'" icone="settings">
                {{-- "Internos" sozinho é adjetivo sem substantivo: internos o
                     quê? Ao lado de "Produtos", que é um substantivo inteiro, a
                     aba pedia que o leitor completasse a frase. --}}
                Sistemas internos · {{ $contagens['internos'] }}
            </x-abas.item>
        </x-abas>

        @if ($aba === 'internos')
            @include('produtos._internos', ['internos' => $internos])
        @else
        {{-- A lista de produtos segue na indentação de antes, de propósito:
             recuá-la em um nível trocaria um diff de duas linhas por um de
             duzentas, todas de espaço em branco. --}}
        <div class="flex flex-wrap items-center gap-3">
            <div class="flex-1 min-w-[240px] rounded-panel border border-line bg-card-grad px-4 py-3">
                <p class="text-[11px] uppercase tracking-[0.10em] text-ink-mute">MRR total · todos os produtos</p>
                <p class="mt-1 font-display text-[24px] font-semibold leading-none text-ink tabular whitespace-nowrap">
                    R$ {{ number_format($mrrTotal, 2, ',', '.') }}
                </p>
            </div>

            {{-- Os dois vieram da tela `sistemas.index`, removida por não ter
                 link em lugar nenhum. Ficam ao lado do MRR de propósito: os
                 três são a mesma conta lida de ângulos diferentes — o total, o
                 que o divide e o resultado. Separados, o leitor teria de
                 confiar que o preço médio se refere àquele MRR. --}}
            <div class="flex-1 min-w-[200px] rounded-panel border border-line bg-card-grad px-4 py-3">
                <p class="text-[11px] uppercase tracking-[0.10em] text-ink-mute">Licenças ativas</p>
                <p class="mt-1 font-display text-[24px] font-semibold leading-none text-ink tabular whitespace-nowrap">
                    {{ number_format($vinculosAtivos, 0, ',', '.') }}
                </p>
            </div>

            <div class="flex-1 min-w-[200px] rounded-panel border border-line bg-card-grad px-4 py-3">
                <p class="text-[11px] uppercase tracking-[0.10em] text-ink-mute">Preço médio por licença</p>
                <p class="mt-1 font-display text-[24px] font-semibold leading-none text-ink tabular whitespace-nowrap">
                    R$ {{ number_format($precoMedio, 2, ',', '.') }}
                </p>
            </div>

            <div class="shrink-0 flex items-center gap-0.5 rounded-control border border-line bg-input" style="padding: 3px">
                <button type="button" @click="definir('lista')"
                        class="h-7 px-2.5 rounded-tile inline-flex items-center gap-1.5 font-mono text-[10.5px] uppercase tracking-caps transition"
                        :class="modo === 'lista' ? 'text-brand-text' : 'text-ink-mute hover:text-ink'"
                        :style="modo === 'lista' ? 'background: rgb(var(--brand) / 0.14)' : ''">
                    <span class="h-3.5 w-3.5"><x-nav-icon name="view-list" /></span> Lista
                </button>
                <button type="button" @click="definir('cartoes')"
                        class="h-7 px-2.5 rounded-tile inline-flex items-center gap-1.5 font-mono text-[10.5px] uppercase tracking-caps transition"
                        :class="modo === 'cartoes' ? 'text-brand-text' : 'text-ink-mute hover:text-ink'"
                        :style="modo === 'cartoes' ? 'background: rgb(var(--brand) / 0.14)' : ''">
                    <span class="h-3.5 w-3.5"><x-nav-icon name="view-grid" /></span> Cartões
                </button>
            </div>
        </div>

        {{-- Lista ---------------------------------------------------------- --}}
        <div x-show="modo === 'lista'">
            <x-tabela min="1080px">
                <thead>
                    <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                        <th class="px-4 py-2.5 font-semibold">Sistema</th>
                        <th class="px-4 py-2.5 font-semibold">MRR · share</th>
                        <th class="px-4 py-2.5 font-semibold">ARR</th>
                        <th class="px-4 py-2.5 font-semibold">Base ativa</th>
                        <th class="px-4 py-2.5 font-semibold">Ticket médio</th>
                        <th class="px-4 py-2.5 font-semibold">Churn</th>
                        <th class="px-4 py-2.5 font-semibold">Status</th>
                        <th class="px-4 py-2.5 font-semibold text-right">Ações</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($produtos as $produto)
                        @php
                            $sistema = $produto['sistema'];
                            $churnAlto = $produto['taxa_cancelamento'] > 10;
                            $marcado = $produto['sem_tier'] || $churnAlto;
                        @endphp

                        <tr class="border-b border-rule hover:bg-chip transition {{ $sistema->ativo ? '' : 'opacity-[0.72]' }}"
                            @if ($marcado)
                                style="border-left: 2px solid rgb(var(--{{ $churnAlto ? 'crit' : 'warn' }})); background: rgb(var(--{{ $churnAlto ? 'crit' : 'warn' }}) / 0.05)"
                            @endif>
                            {{-- Sem ícone aqui, e não por falta dele.
                                 Em 34px o desenho de cada marca vira um borrão e não
                                 identifica nada — quem identifica é o NOME, ao lado, em
                                 tipografia de destaque. E o tile ficava sendo o elemento
                                 de maior contraste da linha justamente na coluna menos
                                 informativa, disputando o olho com os números.
                                 Pior: a cor da marca briga com a cor que aqui SIGNIFICA
                                 algo (verde = ativo, vermelho = churn alto). O wordmark
                                 vive nos cartões, onde o produto é o assunto e tem
                                 espaço para ser lido. --}}
                            <td class="px-4 py-3">
                                <div class="min-w-0">
                                    <span class="flex items-center gap-1.5 min-w-0">
                                        <span class="font-display text-[14.5px] font-semibold text-ink truncate">{{ $sistema->nome }}</span>
                                        @if ($sistema->categoria)
                                            <x-badge>{{ $sistema->categoria }}</x-badge>
                                        @endif
                                    </span>
                                    <span class="block font-mono text-[11px] text-ink-faint truncate">
                                        {{ $sistema->versao ?: 'sem versão' }}@if ($sistema->responsavel) · {{ $sistema->responsavel }}@endif
                                    </span>
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                <div class="flex items-baseline gap-2">
                                    <span class="font-mono text-[13.5px] text-ink whitespace-nowrap">R$ {{ number_format($produto['mrr'], 2, ',', '.') }}</span>
                                    <span class="font-mono text-[11px] text-ink-faint">{{ number_format($produto['share'] * 100, 0) }}%</span>
                                </div>
                                <span class="mt-1.5 block h-1.5 w-full max-w-[140px] rounded-badge bg-bar-track overflow-hidden">
                                    <span class="block h-full rounded-badge" data-barra="{{ $sistema->nome }}"
                                          style="width: {{ round($produto['largura'] * 100, 2) }}%; background: rgb(var(--accent))"></span>
                                </span>
                            </td>

                            <td class="px-4 py-3 font-mono text-[13px] text-ink-dim whitespace-nowrap">
                                R$ {{ number_format($produto['arr'], 2, ',', '.') }}
                            </td>

                            <td class="px-4 py-3">
                                <span class="block font-mono text-[13.5px] text-ink tabular">{{ number_format($produto['clientes_ativos'], 0, ',', '.') }}</span>
                                {{-- A unidade real de cobrança: academias, condomínios,
                                     vidas agregadas. "Clientes" esconde o que se cobra. --}}
                                <span class="block font-mono text-[11px] uppercase tracking-caps text-ink-faint truncate">
                                    {{ $sistema->unidade_cobranca }}
                                </span>
                            </td>

                            <td class="px-4 py-3 font-mono text-[13px] text-ink-dim whitespace-nowrap">
                                R$ {{ number_format($produto['ticket_medio'], 2, ',', '.') }}
                            </td>

                            <td class="px-4 py-3">
                                <span class="block font-mono text-[13px] whitespace-nowrap {{ $churnAlto ? 'text-crit' : 'text-ink-dim' }}">
                                    {{ number_format($produto['taxa_cancelamento'], 1, ',', '.') }}%
                                </span>
                                <span class="block font-mono text-[11px] text-ink-faint whitespace-nowrap">
                                    {{ $produto['clientes_cancelados'] }} cancel.
                                </span>
                            </td>

                            <td class="px-4 py-3">
                                <x-badge :tom="$sistema->ativo ? 'bom' : 'neutro'" ponto>
                                    {{ $sistema->ativo ? 'Ativo' : 'Inativo' }}
                                </x-badge>
                                @if ($produto['sem_tier'])
                                    <span class="mt-1 block font-mono text-[10px] uppercase tracking-caps whitespace-nowrap"
                                          style="color: rgb(var(--warn))">sem tier de atacado</span>
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <x-acao-tabela icone="tag" titulo="Configurar tiers de atacado"
                                                   :href="route('sistemas.edit', $sistema)" />
                                    <x-acao-tabela icone="users" titulo="Ver clientes do sistema"
                                                   :href="route('clientes.index', ['sistema' => $sistema->id])" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-[13px] text-ink-mute">Nenhum sistema cadastrado.</td>
                        </tr>
                    @endforelse
                </tbody>

                @if ($produtos->isNotEmpty())
                    <tfoot>
                        <x-linha-total>
                            <td>Total</td>
                            <td>R$ {{ number_format($totais['mrr'], 2, ',', '.') }}</td>
                            <td>R$ {{ number_format($totais['arr'], 2, ',', '.') }}</td>
                            <td>{{ number_format($totais['ativos'], 0, ',', '.') }}</td>
                            <td></td>
                            <td>{{ $totais['cancelados'] }} cancel.</td>
                            <td></td>
                            <td></td>
                        </x-linha-total>
                    </tfoot>
                @endif

                {{-- As contagens seguem sendo do catálogo inteiro, não da
                     página: "3 sem tier de atacado" é pendência da casa. --}}
                <x-slot name="rodape">
                    <span>{{ $produtos->count() }} de {{ $contagens['sistemas'] }} sistemas · {{ $contagens['ativos'] }} ativos</span>
                    @if ($produtos->hasPages())
                        <span>· página {{ $produtos->currentPage() }} de {{ $produtos->lastPage() }}</span>
                    @endif
                    @if ($contagens['sem_tier'] > 0)
                        <span style="color: rgb(var(--warn))">· {{ $contagens['sem_tier'] }} sem tier de atacado</span>
                    @endif
                </x-slot>
            </x-tabela>
        </div>

        {{-- Cartões -------------------------------------------------------- --}}
        <div x-show="modo === 'cartoes'" x-cloak
             class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr))">
            @foreach ($produtos as $produto)
                @php $sistema = $produto['sistema']; @endphp
                <article class="rounded-panel border border-line bg-card-grad p-4 {{ $sistema->ativo ? '' : 'opacity-[0.72]' }}">
                    {{-- No cartão o produto é o assunto e tem espaço próprio:
                         aqui o wordmark cabe e vale a pena. --}}
                    <div class="min-w-0">
                        {{-- 26px de caixa dá ~19px de letra em todas as marcas: os quadros
                             foram normalizados para a mesma proporção letra/quadro,
                             então a altura da caixa vale igual para todas. --}}
                        <x-marca-sistema :sistema="$sistema" formato="wordmark" tamanho="h-[26px] max-w-full" />
                        <span class="mt-2 block font-mono text-[11px] uppercase tracking-caps text-ink-faint truncate">
                            {{ $sistema->unidade_cobranca }}
                        </span>
                    </div>

                    <p class="mt-3 font-display text-[22px] font-semibold leading-none text-ink tabular whitespace-nowrap">
                        R$ {{ number_format($produto['mrr'], 2, ',', '.') }}
                    </p>
                    <p class="mt-1 font-mono text-[11px] text-ink-faint">{{ number_format($produto['share'] * 100, 0) }}% do MRR total</p>

                    <dl class="mt-3 pt-3 border-t border-rule space-y-1.5">
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-[12px] text-ink-mute">Base ativa</dt>
                            <dd class="font-mono text-[12.5px] text-ink-dim">{{ number_format($produto['clientes_ativos'], 0, ',', '.') }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-[12px] text-ink-mute">Ticket médio</dt>
                            <dd class="font-mono text-[12.5px] text-ink-dim">R$ {{ number_format($produto['ticket_medio'], 2, ',', '.') }}</dd>
                        </div>
                        @if ($produto['modulos_ativos'] > 0)
                            {{-- Módulos são cobrados à parte da licença: sem
                                 esta linha o MRR do produto parece menor do que é. --}}
                            <div class="flex items-center justify-between gap-2">
                                <dt class="text-[12px] text-ink-mute">
                                    Módulos <span class="text-ink-faint">({{ $produto['modulos_ativos'] }})</span>
                                </dt>
                                <dd class="font-mono text-[12.5px] text-ink-dim">
                                    R$ {{ number_format($produto['mrr_modulos'], 2, ',', '.') }}
                                </dd>
                            </div>
                        @endif
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-[12px] text-ink-mute">Churn</dt>
                            <dd class="font-mono text-[12.5px] {{ $produto['taxa_cancelamento'] > 10 ? 'text-crit' : 'text-ink-dim' }}">
                                {{ number_format($produto['taxa_cancelamento'], 1, ',', '.') }}%
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-3 pt-3 border-t border-rule flex items-center gap-2">
                        <x-badge :tom="$sistema->ativo ? 'bom' : 'neutro'" ponto>{{ $sistema->ativo ? 'Ativo' : 'Inativo' }}</x-badge>
                        @if ($produto['sem_tier'])
                            <x-badge tom="atencao">sem tier</x-badge>
                        @endif
                        <a href="{{ route('sistemas.edit', $sistema) }}"
                           class="ml-auto font-mono text-[10.5px] uppercase tracking-caps text-brand-text hover:underline">Configurar</a>
                    </div>
                </article>
            @endforeach
        </div>

        {{-- Um paginador só, FORA dos dois blocos: lista e cartões mostram a
             mesma página da mesma lista, e duplicar o controle dentro de cada
             modo daria dois lugares para o mesmo estado sair de sincronia. --}}
        @if ($produtos->hasPages())
            <div>{{ $produtos->links() }}</div>
        @endif
        @endif
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('modoProdutos', () => ({
                // Lista é o padrão: é ela que deixa comparar. Quem prefere
                // cartões escolhe uma vez e a escolha fica.
                modo: (() => {
                    try {
                        return localStorage.getItem('alfamatriz:produtos-modo') === 'cartoes' ? 'cartoes' : 'lista';
                    } catch (erro) {
                        return 'lista';
                    }
                })(),

                definir(modo) {
                    this.modo = modo;
                    try {
                        localStorage.setItem('alfamatriz:produtos-modo', modo);
                    } catch (erro) {
                        // preferência não persistida; a sessão atual continua valendo
                    }
                },
            }));
        });
    </script>
</x-app-layout>
