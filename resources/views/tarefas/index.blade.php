<x-app-layout>
    <x-slot name="titulo">Tarefas</x-slot>
    <x-slot name="contexto">{{ $tarefas->count() }} tarefas no quadro</x-slot>
    <x-slot name="acoes">
        <button type="button" x-data @click="$dispatch('open-modal', 'nova-tarefa')"
                class="h-[34px] px-3 rounded-control bg-brand text-on-brand font-semibold text-[12.5px]
                       hover:bg-brand-bright transition whitespace-nowrap">
            + Nova tarefa
        </button>
    </x-slot>

    {{--
        Mesmo truque do Funil de Vendas: a tela ocupa a altura da janela e o
        quadro cresce dentro dela, senão a coluna mais cheia estica a página
        inteira e as outras ficam desalinhadas.
    --}}
    <div class="flex flex-col gap-4" style="height: calc(100vh - 120px); min-height: 520px">
        @if (session('status'))
            <x-aviso class="shrink-0">{{ session('status') }}</x-aviso>
        @endif
        @if (session('erro'))
            <x-aviso class="shrink-0" tom="critico">{{ session('erro') }}</x-aviso>
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
                <p class="ml-auto shrink-0 hidden sm:block font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                    arraste o card para mover de etapa
                </p>
            </div>

            <div x-ref="quadro" @scroll="medirBordas()" @resize.window="medirBordas()" x-init="medirBordas()"
                 class="relative flex-1 min-h-0 flex gap-3 items-stretch overflow-x-auto p-3.5">
                @foreach ($etapas as $etapa)
                    @php $cards = $colunas[$etapa['chave']]; @endphp

                    {{--
                        As etapas que pedem texto (ajustes, cancelamento,
                        conclusão com relatório) não aceitam o solto direto —
                        o menu "Mover ▾" do card é o único caminho para elas
                        (Q-013).
                    --}}
                    <section class="shrink-0 flex flex-col min-h-0 rounded-control bg-panel border border-line overflow-hidden"
                             style="width: 276px" data-status="{{ $etapa['chave'] }}"
                             @dragover.prevent="permitir('{{ $etapa['chave'] }}')"
                             @dragleave="sobre = null"
                             @drop.prevent="soltar('{{ $etapa['chave'] }}', {{ in_array($etapa['chave'], ['ajustes_necessarios', 'cancelada', 'concluida'], true) ? 'true' : 'false' }})"
                             :class="sobre === '{{ $etapa['chave'] }}' && 'ring-1 ring-brand'">
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
                                @php $transicoes = \App\Services\FluxoTarefaService::TRANSICOES_PERMITIDAS[$tarefa->status] ?? []; @endphp
                                <div x-data="{ menuAberto: false, destino: '{{ $transicoes[0] ?? '' }}' }"
                                     draggable="true"
                                     data-tarefa="{{ $tarefa->id }}"
                                     @dragstart="arrastando = {{ $tarefa->id }}"
                                     @dragend="arrastando = null; sobre = null"
                                     @click="$dispatch('open-modal', 'editar-tarefa-{{ $tarefa->id }}')"
                                     class="cursor-grab active:cursor-grabbing"
                                     :class="arrastando === {{ $tarefa->id }} && 'opacity-50'">
                                    @include('tarefas._card', ['tarefa' => $tarefa])
                                    @include('tarefas._mover', ['tarefa' => $tarefa, 'transicoes' => $transicoes])
                                </div>
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

            {{-- Um formulário só, apontado para a tarefa que foi solta. --}}
            <form x-ref="formMover" method="POST" action="" class="hidden">
                @csrf
                <input type="hidden" name="status" x-ref="statusMover">
            </form>
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
                arrastando: null,
                sobre: null,

                // Modelo de rota com marcador: o id só é conhecido no solto.
                rotaMover: @json(route('tarefas.mover', ['tarefa' => '__ID__'])),

                // Rótulos das etapas para o select do menu "Mover ▾": montados
                // no cliente (x-text), não em Blade — se saíssem como
                // <option> literal, o rótulo de uma etapa mais à frente no
                // quadro (ex.: "Cancelada") apareceria no HTML antes do
                // cabeçalho da própria coluna, quebrando a ordem das colunas.
                rotulosStatus: @json(\App\Models\Tarefa::STATUS),

                temMaisEsquerda: false,
                temMaisDireita: false,

                medirBordas() {
                    const q = this.$refs.quadro;
                    if (! q) return;
                    this.temMaisEsquerda = q.scrollLeft > 1;
                    this.temMaisDireita = q.scrollLeft + q.clientWidth < q.scrollWidth - 1;
                },

                permitir(status) {
                    if (this.arrastando !== null) {
                        this.sobre = status;
                    }
                },

                soltar(status, exigeTexto) {
                    const tarefa = this.arrastando;
                    this.sobre = null;
                    this.arrastando = null;

                    if (tarefa === null) {
                        return;
                    }

                    // Ajustes, cancelamento e conclusão pedem texto que o
                    // solto não tem como responder — quem quer mover para lá
                    // usa o menu "Mover ▾" do card, onde a pergunta cabe.
                    if (exigeTexto) {
                        return;
                    }

                    this.$refs.formMover.action = this.rotaMover.replace('__ID__', tarefa);
                    this.$refs.statusMover.value = status;
                    this.$refs.formMover.submit();
                },
            }));
        });
    </script>

    {{-- Modal: nova tarefa --}}
    <x-modal name="nova-tarefa" maxWidth="lg">
        @include('tarefas._form', ['tarefa' => null, 'sistemas' => $sistemas, 'usuarios' => $usuarios])
    </x-modal>

    {{-- Modal: editar tarefa — uma por card, como em Clientes --}}
    @foreach ($tarefas as $tarefa)
        <x-modal name="editar-tarefa-{{ $tarefa->id }}" maxWidth="lg">
            @include('tarefas._form', ['tarefa' => $tarefa, 'sistemas' => $sistemas, 'usuarios' => $usuarios])
        </x-modal>
    @endforeach
</x-app-layout>
