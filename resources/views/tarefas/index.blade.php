<x-app-layout>
    <x-slot name="titulo">Tarefas</x-slot>
    <x-slot name="contexto">{{ $tarefas->count() }} tarefas no quadro</x-slot>

    {{--
        Mesmo truque do Funil de Vendas: a tela ocupa a altura da janela e o
        quadro cresce dentro dela, senão a coluna mais cheia estica a página
        inteira e as outras ficam desalinhadas.
    --}}
    <div class="flex flex-col gap-4" style="height: calc(100vh - 120px); min-height: 520px">
        @if (session('status'))
            <x-aviso class="shrink-0">{{ session('status') }}</x-aviso>
        @endif

        <div x-data="quadroTarefas" class="relative flex-1 min-h-0 flex flex-col rounded-panel border border-line bg-board overflow-hidden">
            <div x-show="temMaisEsquerda" x-cloak aria-hidden="true"
                 class="pointer-events-none absolute left-0 top-0 bottom-0 w-10 z-10"
                 style="background: linear-gradient(90deg, rgb(var(--canvas) / 0.55), transparent)"></div>
            <div x-show="temMaisDireita" x-cloak aria-hidden="true"
                 class="pointer-events-none absolute right-0 top-0 bottom-0 w-10 z-10"
                 style="background: linear-gradient(270deg, rgb(var(--canvas) / 0.55), transparent)"></div>

            <div class="shrink-0 flex items-center gap-3 px-4 h-[52px] border-b border-line">
                <span class="h-7 w-7 shrink-0 rounded-tile flex items-center justify-center bg-brand/15 text-brand-text">
                    <span class="h-[15px] w-[15px]"><x-nav-icon name="view-grid" /></span>
                </span>
                <div class="min-w-0">
                    <h2 class="font-display text-[15.5px] font-semibold text-ink leading-tight">Quadro de tarefas</h2>
                    <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint truncate">
                        {{ count($etapas) }} etapas · {{ $tarefas->count() }} tarefas
                    </p>
                </div>
            </div>

            <div x-ref="quadro" @scroll="medirBordas()" @resize.window="medirBordas()" x-init="medirBordas()"
                 class="relative flex-1 min-h-0 flex gap-3 items-stretch overflow-x-auto p-3.5">
                @foreach ($etapas as $etapa)
                    @php $cards = $colunas[$etapa['chave']]; @endphp

                    <section class="shrink-0 flex flex-col min-h-0 rounded-control bg-panel border border-line overflow-hidden"
                             style="width: 276px" data-status="{{ $etapa['chave'] }}">
                        <header class="shrink-0 px-3 py-2.5 border-b border-rule">
                            <div class="flex items-center gap-2">
                                <h3 class="min-w-0 truncate font-display text-[14px] font-semibold text-ink">{{ $etapa['label'] }}</h3>
                                <span class="ml-auto shrink-0 h-5 min-w-[20px] px-1.5 rounded-full font-mono text-[10px] font-semibold leading-5 text-center bg-chip text-ink-dim">
                                    {{ $etapa['quantidade'] }}
                                </span>
                            </div>
                        </header>

                        <div class="flex-1 min-h-0 overflow-y-auto overscroll-y-contain p-2 space-y-2">
                            @forelse ($cards as $tarefa)
                                @include('tarefas._card', ['tarefa' => $tarefa])
                            @empty
                                <div class="rounded-ctl border border-dashed border-line px-2 text-center flex items-center justify-center"
                                     style="height: 84px">
                                    <p class="text-[11.5px] text-ink-faint">Nenhuma tarefa aqui</p>
                                </div>
                            @endforelse
                        </div>
                    </section>
                @endforeach
            </div>
        </div>
    </div>

    {{--
        Script clássico e inline de propósito, como no `funil`: precisa
        registrar o componente antes de o Alpine iniciar, o que o bundle do
        Vite (módulo, portanto adiado) não garante.
    --}}
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('quadroTarefas', () => ({
                temMaisEsquerda: false,
                temMaisDireita: false,

                medirBordas() {
                    const q = this.$refs.quadro;
                    if (! q) return;
                    this.temMaisEsquerda = q.scrollLeft > 1;
                    this.temMaisDireita = q.scrollLeft + q.clientWidth < q.scrollWidth - 1;
                },
            }));
        });
    </script>
</x-app-layout>
