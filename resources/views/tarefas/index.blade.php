<x-app-layout>
    <x-slot name="titulo">Tarefas</x-slot>
    {{-- Com filtro ligado o cabeçalho diz "X de Y": sem o denominador, um
         quadro recortado é indistinguível de um quadro vazio. --}}
    <x-slot name="contexto">
        {{ $tarefas->count() < $totalNoQuadro
            ? $tarefas->count().' de '.$totalNoQuadro.' tarefas no quadro'
            : $tarefas->count().' tarefas no quadro' }}
    </x-slot>
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
        @include('tarefas._abas', ['ativa' => 'quadro'])

        @include('tarefas._filtros', [
            'filtros' => $filtros,
            'sistemas' => $sistemas,
            'usuarios' => $usuarios,
        ])

        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif
        @if (session('erro'))
            <x-aviso tom="critico">{{ session('erro') }}</x-aviso>
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
                    arraste o card para mover · solte em concluir ✓ para encerrar
                </p>
            </div>

            <div x-ref="quadro" @scroll="medirBordas()" @resize.window="medirBordas()" x-init="medirBordas()"
                 class="relative flex-1 min-h-0 flex gap-3 items-stretch overflow-x-auto p-3.5">
                @foreach ($etapas as $etapa)
                    @php $cards = $colunas[$etapa['chave']]; @endphp

                    {{--
                        As etapas que pedem texto (bloqueio, ajustes,
                        cancelamento, conclusão com relatório) não aceitam o
                        solto direto — o menu "Mover ▾" do card é o único
                        caminho para elas (Q-013).
                    --}}
                    <section class="flex flex-col min-h-0 rounded-control bg-panel border border-line overflow-hidden transition-opacity"
                             {{--
                                 A cor da etapa vive AQUI, na coluna, como no
                                 Funil de Vendas — e não na borda do card: essa
                                 continua sendo o canal do aviso de tarefa
                                 esquecida (AC-093, AC-127).

                                 `flex: 1 1 276px` no lugar de largura fixa: com
                                 cinco colunas numa tela larga sobrava uma faixa
                                 vazia à direita (AC-132). Assim elas dividem o
                                 espaço quando ele existe, e o `min-width` segura
                                 a largura de leitura — apertando a tela, o
                                 quadro volta a rolar na horizontal em vez de
                                 espremer o card.
                             --}}
                             style="flex: 1 1 276px; min-width: 276px; border-top: 3px solid rgb(var(--{{ $etapa['cor'] }}))"
                             data-status="{{ $etapa['chave'] }}"
                             @dragover.prevent="permitir('{{ $etapa['chave'] }}')"
                             @dragleave="sobre = null"
                             @drop.prevent="soltar('{{ $etapa['chave'] }}', {{ in_array($etapa['chave'], ['bloqueada', 'ajustes_necessarios', 'cancelada', 'concluida'], true) ? 'true' : 'false' }})"
                             {{-- Enquanto o card está na mão, a coluna que não
                                  o aceita apaga. É o que faz a regra do fluxo
                                  virar coisa que se VÊ antes de soltar: o
                                  "transição inválida" deixa de ser a primeira
                                  notícia de que aquele caminho não existia. --}}
                             {{-- O realce SEGUE enquanto o painel de motivo está
                                  aberto: o card ainda não chegou, e a coluna
                                  apagando junto com o solto faria o painel
                                  parecer desligado do gesto que o abriu. --}}
                             :class="{
                                 'ring-1 ring-brand': sobre === '{{ $etapa['chave'] }}' || pendente?.destino === '{{ $etapa['chave'] }}',
                                 'opacity-25': ! aceita('{{ $etapa['chave'] }}'),
                             }">
                        <header class="shrink-0 px-3 py-2.5 border-b border-rule">
                            <div class="flex items-center gap-2">
                                <span class="h-[7px] w-[7px] shrink-0 rounded-full"
                                      style="background: rgb(var(--{{ $etapa['cor'] }}))"></span>
                                <h3 class="min-w-0 truncate font-display text-[14px] font-semibold text-ink">{{ $etapa['label'] }}</h3>

                                {{--
                                    O contador vira "4/3" onde há limite de WIP,
                                    e tinge de âmbar ao estourar. O numerador é
                                    o que ANDA: a tarefa travada não ocupa vaga,
                                    porque o limite existe para conter trabalho
                                    começado em paralelo, e tarefa parada não
                                    está sendo tocada por ninguém.
                                --}}
                                @php
                                    $corDoContador = $etapa['acimaDoLimite'] ? 'warn' : $etapa['cor'];
                                @endphp
                                <span class="ml-auto shrink-0 h-5 min-w-[20px] px-1.5 rounded-full font-mono text-[10px] font-semibold leading-5 text-center"
                                      style="background: rgb(var(--{{ $corDoContador }}) / var(--tint-alpha)); color: rgb(var(--{{ $corDoContador }}))"
                                      @if ($etapa['acimaDoLimite']) title="Acima do limite de {{ $etapa['limite'] }} tarefas em curso nesta etapa" @endif>
                                    @if ($etapa['limite'])
                                        {{ $etapa['andando'] }}/{{ $etapa['limite'] }}
                                    @else
                                        {{ $etapa['quantidade'] }}
                                    @endif
                                </span>
                            </div>

                            {{-- Duas notícias que só aparecem quando existem:
                                 nenhuma coluna ganha uma linha vazia para ler. --}}
                            @if ($etapa['acimaDoLimite'])
                                <p class="mt-1 font-mono text-[10px] uppercase tracking-caps" style="color: rgb(var(--warn))">
                                    acima do limite
                                </p>
                            @elseif ($etapa['aguardandoTriagem'] > 0)
                                <p class="mt-1 font-mono text-[10px] uppercase tracking-caps" style="color: rgb(var(--warn))">
                                    {{ $etapa['aguardandoTriagem'] }} aguardando triagem
                                </p>
                            @endif
                        </header>

                        <div class="flex-1 min-h-0 overflow-y-auto overscroll-y-contain p-2 space-y-2">
                            @forelse ($cards as $tarefa)
                                @php
                                    /**
                                     * Os destinos são do CARD, não do status: o
                                     * fluxo depende do tipo da tarefa.
                                     *
                                     * E quem não pode mover ESTA tarefa não
                                     * recebe destino nenhum — o card não
                                     * arrasta e não mostra o chevron. Oferecer
                                     * e recusar depois é o vício que o quadro
                                     * acabou de perder nas regras de fluxo; não
                                     * faria sentido reintroduzi-lo na
                                     * autorização. O porquê fica no `title`, e
                                     * a rota continua recusando com a frase.
                                     */
                                    $impedimento = $tarefa->motivoParaNaoMover(auth()->user());
                                    $transicoes = $impedimento
                                        ? []
                                        : \App\Services\FluxoTarefaService::transicoesDe($tarefa);
                                @endphp
                                <div x-data="{ menuAberto: false, destino: '{{ $transicoes[0] ?? '' }}' }"
                                     draggable="{{ $impedimento ? 'false' : 'true' }}"
                                     @if ($impedimento) title="{{ $impedimento }}" @endif
                                     data-tarefa="{{ $tarefa->id }}"
                                     {{-- O card entrega os próprios destinos ao
                                          pegar: é assim que o quadro sabe quais
                                          colunas apagar durante o arrasto. --}}
                                     @dragstart="pegar(
                                         {{ $tarefa->id }},
                                         {{ Illuminate\Support\Js::from($transicoes) }},
                                         '{{ $tarefa->tipo }}',
                                         {{ $tarefa->estaBloqueada() ? 'true' : 'false' }}
                                     )"
                                     @dragend="largar()"
                                     @click="$dispatch('open-modal', 'editar-tarefa-{{ $tarefa->id }}')"
                                     class="{{ $impedimento ? 'cursor-pointer' : 'cursor-grab active:cursor-grabbing' }}"
                                     :class="arrastando === {{ $tarefa->id }} && 'opacity-50'">
                                    @include('tarefas._card', ['tarefa' => $tarefa, 'transicoes' => $transicoes])
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

                {{--
                    O alvo de bloquear, irmão do de concluir.

                    Bloquear é o que sobrou da coluna Bloqueada: a tarefa não sai
                    da etapa, então não há para onde arrastá-la — mas o gesto
                    continua sendo o mesmo, e uma faixa dá destino a ele sem
                    devolver ao quadro uma coluna que mentia sobre onde o
                    trabalho está.
                --}}
                <section class="shrink-0 self-stretch flex flex-col items-center justify-center gap-2
                                rounded-control border border-dashed transition-opacity"
                         style="flex: 0 0 60px; border-color: rgb(var(--warn) / 0.4); background: rgb(var(--warn) / calc(var(--tint-alpha) / 2))"
                         title="Arraste um card até aqui para bloquear"
                         @dragover.prevent="permitir('bloqueio')"
                         @dragleave="sobre = null"
                         @drop.prevent="soltar('bloqueio', true)"
                         :class="{
                             'ring-1 ring-brand': sobre === 'bloqueio' || pendente?.destino === 'bloqueio',
                             'opacity-25': ! aceita('bloqueio'),
                         }">
                    <span class="text-[13px] leading-none" style="color: rgb(var(--warn))" aria-hidden="true">🔒</span>
                    <span class="font-mono text-[10.5px] uppercase tracking-caps"
                          style="writing-mode: vertical-rl; color: rgb(var(--warn))">
                        Bloquear
                    </span>
                    @if ($totalBloqueadas > 0)
                        <span class="font-mono text-[10px] font-semibold h-5 min-w-[20px] px-1.5 rounded-full leading-5 text-center"
                              style="background: rgb(var(--warn) / var(--tint-alpha)); color: rgb(var(--warn))">
                            {{ $totalBloqueadas }}
                        </span>
                    @endif
                </section>

                {{--
                    O alvo de concluir.

                    Concluída não tem coluna, e está certo: o quadro é o
                    trabalho em curso, e cards encerrados só ocupariam espaço
                    (AC-096). Mas o preço disso era a ação mais importante do
                    fluxo ter virado a mais escondida — terminar uma tarefa só
                    acontecia dentro do menu de um dropdown, enquanto todo o
                    resto do quadro se move arrastando.

                    Por isso uma FAIXA e não uma coluna: ela não guarda card
                    nenhum e não cresce, só recebe o solto. O quadro continua
                    sem etapa terminal, e terminar volta a ter gesto.

                    Ela sempre pede confirmação, mesmo na tarefa operacional,
                    que não tem relatório a preencher: encerrar tira o card da
                    vista, e um arrasto torto não deveria ser capaz disso
                    sozinho.
                --}}
                <section class="shrink-0 self-stretch flex flex-col items-center justify-center gap-2
                                rounded-control border border-dashed transition-opacity"
                         style="flex: 0 0 60px; border-color: rgb(var(--good) / 0.4); background: rgb(var(--good) / calc(var(--tint-alpha) / 2))"
                         title="Arraste um card até aqui para concluir"
                         @dragover.prevent="permitir('concluida')"
                         @dragleave="sobre = null"
                         @drop.prevent="soltar('concluida', true)"
                         :class="{
                             'ring-1 ring-brand': sobre === 'concluida' || pendente?.destino === 'concluida',
                             'opacity-25': ! aceita('concluida'),
                         }">
                    <span class="text-[15px] leading-none" style="color: rgb(var(--good))" aria-hidden="true">✓</span>
                    <span class="font-mono text-[10.5px] uppercase tracking-caps"
                          style="writing-mode: vertical-rl; color: rgb(var(--good))">
                        Concluir
                    </span>
                </section>
            </div>

            {{--
                O painel de motivo.

                Soltar numa etapa que pede texto NÃO pode parecer falha. Antes o
                gesto morria em silêncio; depois passou a abrir a caixinha do
                card, que resolvia o silêncio mas não a leitura — um textarea
                aparecendo dentro do card, longe da coluna onde a pessoa acabou
                de soltar, não se explica.

                O painel nomeia a ação no título, diz numa linha POR QUE o texto
                está sendo pedido, e o botão nomeia o resultado ("Bloquear
                tarefa") em vez de dizer "Confirmar" — que é o que se aperta sem
                ler. Enquanto vazio, o botão fica apagado e a exigência está
                dita em âmbar, não escondida no `required` do navegador.
            --}}
            <div x-show="pendente" x-cloak x-transition.opacity.duration.150ms
                 class="absolute inset-0 z-20 flex items-end justify-center pb-5 px-4 pointer-events-none">
                <form method="POST" :action="pendente?.acao" @submit="enviandoPendente = true"
                      class="pointer-events-auto w-[440px] max-w-full rounded-panel border bg-panel p-4 shadow-xl"
                      x-transition:enter="transition ease-out duration-200"
                      x-transition:enter-start="opacity-0 translate-y-2"
                      x-transition:enter-end="opacity-100 translate-y-0"
                      :style="`border-color: rgb(var(--${pendente?.cor}) / 0.45);
                               border-top: 2px solid rgb(var(--${pendente?.cor}));
                               background-image: linear-gradient(rgb(var(--${pendente?.cor}) / calc(var(--tint-alpha) / 2)), rgb(var(--${pendente?.cor}) / calc(var(--tint-alpha) / 2)))`">
                    @csrf
                    <input type="hidden" name="status" :value="pendente?.status">

                    <div class="flex items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <h3 class="font-display text-[14.5px] font-semibold text-ink" x-text="pendente?.titulo"></h3>
                            <p class="mt-0.5 text-[11.5px] leading-snug text-ink-mute" x-text="pendente?.porque"></p>
                        </div>
                        <button type="button" @click="fecharPendente()" aria-label="Desistir"
                                class="shrink-0 h-6 w-6 rounded-control text-ink-faint hover:text-ink transition">✕</button>
                    </div>

                    <template x-if="pendente?.campo">
                        <textarea x-ref="textoPendente" :name="pendente.campo" rows="3"
                                  :placeholder="pendente.placeholder"
                                  x-model="textoPendente"
                                  class="mt-3 w-full text-[12.5px] rounded-control bg-input border-line text-ink"></textarea>
                    </template>

                    <template x-if="pendente?.pedeAprovacao">
                        <label class="mt-2 flex items-center gap-2 text-[11.5px] text-ink-dim">
                            <input type="checkbox" name="relatorio_aprovado" value="1">
                            Relatório aprovado
                        </label>
                    </template>

                    <div class="mt-3 flex items-center gap-3">
                        <button type="submit"
                                :disabled="(pendente?.obrigatorio && ! textoPendente.trim()) || enviandoPendente"
                                class="h-8 px-3 rounded-control font-semibold text-[12.5px] transition disabled:cursor-not-allowed"
                                :style="(pendente?.obrigatorio && ! textoPendente.trim()) || enviandoPendente
                                    ? 'background: rgb(var(--line)); color: rgb(var(--ink-faint))'
                                    : `background: rgb(var(--${pendente?.cor})); color: rgb(var(--on-brand))`"
                                x-text="enviandoPendente ? 'Enviando…' : pendente?.botao"></button>

                        <span x-show="pendente?.obrigatorio && ! textoPendente.trim()"
                              class="font-mono text-[10.5px] uppercase tracking-caps"
                              style="color: rgb(var(--warn))">
                            obrigatório
                        </span>
                    </div>
                </form>
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
            /**
             * O checklist de uma tarefa: arrastar para reordenar.
             *
             * Mora aqui, e não num `x-data` no HTML, porque o atributo é
             * delimitado por aspas duplas — qualquer aspa dupla dentro dele,
             * até num comentário de código, o fecha no meio e entrega
             * JavaScript truncado ao Alpine. A lista continua aparecendo, e
             * nada nela responde.
             */
            Alpine.data('checklist', (tarefaId) => ({
                arrastando: null,

                soltarSobre(alvo) {
                    if (! this.arrastando || this.arrastando === alvo) {
                        return;
                    }

                    const lista = this.$refs.lista;
                    const linhas = [...lista.children];
                    const destino = linhas.indexOf(alvo);
                    const origem = linhas.indexOf(this.arrastando);

                    lista.insertBefore(this.arrastando, origem < destino ? alvo.nextSibling : alvo);
                    this.salvarOrdem();
                },

                /**
                 * A ordem vai INTEIRA, lida do DOM depois do arrasto.
                 *
                 * Mandar só "o item X foi para a posição N" obrigaria o
                 * servidor a recalcular o resto — e a divergir do que está na
                 * tela assim que dois arrastos chegassem fora de ordem.
                 */
                salvarOrdem() {
                    const envio = document.getElementById('ordenar-checklist-' + tarefaId);

                    envio.querySelectorAll('input[data-ordem]').forEach((campo) => campo.remove());

                    [...this.$refs.lista.children].forEach((linha) => {
                        const campo = document.createElement('input');
                        campo.type = 'hidden';
                        campo.name = 'ordem[]';
                        campo.dataset.ordem = '1';
                        campo.value = linha.dataset.item;
                        envio.appendChild(campo);
                    });

                    envio.requestSubmit();
                },
            }));

            Alpine.data('quadroTarefas', () => ({
                arrastando: null,
                sobre: null,

                // Para onde o card que está na mão pode ir. Sai do mesmo lugar
                // que alimenta o menu "Mover ▾" (`FluxoTarefaService`), e é o
                // que permite ao quadro MOSTRAR a regra em vez de aplicá-la por
                // cima de quem já soltou: sem isso, arrastar uma tarefa
                // operacional até Em testes era um caminho aberto que só
                // respondia "transição inválida" depois do fato.
                destinos: [],

                // Tipo do card na mão: é ele que decide se concluir pede
                // relatório de teste, e o painel é do quadro, não do card.
                tipoArrastado: null,

                // O que o painel de motivo está perguntando agora.
                pendente: null,
                textoPendente: '',
                enviandoPendente: false,

                // Modelo de rota com marcador: o id só é conhecido no solto.
                rotaMover: @json(route('tarefas.mover', ['tarefa' => '__ID__'])),
                rotaBloquear: @json(route('tarefas.bloquear', ['tarefa' => '__ID__'])),

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

                /** Esta etapa é destino possível para o card que está na mão? */
                aceita(status) {
                    return this.arrastando === null || this.destinos.includes(status);
                },

                pegar(tarefa, destinos, tipo, bloqueada) {
                    this.arrastando = tarefa;
                    // Bloquear é destino de quem ainda não está travado; para
                    // quem já está, a saída é o botão da própria tarja.
                    this.destinos = bloqueada ? destinos : [...destinos, 'bloqueio'];
                    this.tipoArrastado = tipo;
                },

                largar() {
                    this.arrastando = null;
                    this.destinos = [];
                    this.tipoArrastado = null;
                    this.sobre = null;
                },

                /**
                 * O que o painel pergunta, por destino.
                 *
                 * O texto do "porquê" não é enfeite: é ele que transforma uma
                 * exigência em pedido. Sem a linha, o painel só informa que
                 * falta preencher um campo — que é a mesma notícia que o
                 * `required` do navegador já dava, e a razão de o gesto parecer
                 * castigo em vez de conversa.
                 */
                receita(destino) {
                    const ehDev = this.tipoArrastado === 'desenvolvimento';

                    const receitas = {
                        bloqueio: {
                            titulo: 'Bloqueando a tarefa',
                            porque: 'A tarefa continua na etapa em que está — o motivo é o que permite outra pessoa destravá-la depois.',
                            placeholder: 'Esperando quem, e o quê…',
                            botao: 'Bloquear tarefa',
                            cor: 'warn', campo: 'motivo', obrigatorio: true, pedeAprovacao: false,
                        },
                        ajustes_necessarios: {
                            titulo: 'Devolvendo para ajustes',
                            porque: 'Quem for corrigir precisa saber o que falhou — sem isso, a devolução vira uma volta sem instrução.',
                            placeholder: 'O que precisa ser corrigido…',
                            botao: 'Devolver para ajustes',
                            cor: 'warn', campo: 'motivo', obrigatorio: true, pedeAprovacao: false,
                        },
                        cancelada: {
                            titulo: 'Cancelando a tarefa',
                            porque: 'Cancelar encerra a tarefa. O motivo fica no histórico, e é o que explica a decisão para quem a reabrir um dia.',
                            placeholder: 'Motivo do cancelamento…',
                            botao: 'Cancelar tarefa',
                            cor: 'crit', campo: 'motivo', obrigatorio: true, pedeAprovacao: false,
                        },
                        concluida: {
                            titulo: 'Concluindo a tarefa',
                            porque: ehDev
                                ? 'A conclusão só passa com um relatório de teste aprovado desta passagem por Em testes.'
                                : 'Tarefa operacional fecha sem relatório. A confirmação existe porque encerrar tira o card do quadro.',
                            placeholder: 'Notas do relatório de teste…',
                            botao: 'Concluir tarefa',
                            cor: 'good',
                            campo: ehDev ? 'relatorio_notas' : null,
                            obrigatorio: ehDev,
                            pedeAprovacao: ehDev,
                        },
                    };

                    return receitas[destino] ?? null;
                },

                abrirPendente(tarefa, destino) {
                    const receita = this.receita(destino);

                    if (! receita) {
                        return;
                    }

                    const ehBloqueio = destino === 'bloqueio';

                    this.textoPendente = '';
                    this.enviandoPendente = false;
                    this.pendente = {
                        ...receita,
                        destino,
                        // Bloquear tem rota própria: travar não é mover.
                        status: ehBloqueio ? null : destino,
                        acao: (ehBloqueio ? this.rotaBloquear : this.rotaMover).replace('__ID__', tarefa),
                    };

                    this.$nextTick(() => this.$refs.textoPendente?.focus());
                },

                fecharPendente() {
                    this.pendente = null;
                    this.textoPendente = '';
                },

                permitir(status) {
                    if (this.arrastando !== null && this.aceita(status)) {
                        this.sobre = status;
                    }
                },

                soltar(status, exigeTexto) {
                    const tarefa = this.arrastando;
                    const permitido = this.aceita(status);
                    const tipo = this.tipoArrastado;

                    this.largar();
                    this.tipoArrastado = tipo;

                    if (tarefa === null || ! permitido) {
                        this.tipoArrastado = null;

                        return;
                    }

                    // Bloqueio, ajustes, cancelamento e conclusão pedem um
                    // texto que o solto não tem como responder. Antes o arrasto
                    // simplesmente morria aqui, sem mover e sem dizer nada — o
                    // que se lê como sistema quebrado, não como regra. Agora
                    // ele abre o painel, que nomeia a ação e diz por que está
                    // pedindo: o gesto vira o começo da pergunta.
                    if (exigeTexto) {
                        this.abrirPendente(tarefa, status);

                        return;
                    }

                    this.tipoArrastado = null;
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

    {{-- Modal: editar tarefa — uma por card, como em Clientes. O `_form` já
         traz a conversa dentro dele, porque o comentário é publicado pelo
         mesmo Salvar; o que vem depois são só os formulários de apagar
         comentário, que não podem ficar aninhados nele. --}}
    @foreach ($tarefas as $tarefa)
        <x-modal name="editar-tarefa-{{ $tarefa->id }}" maxWidth="lg">
            @include('tarefas._form', ['tarefa' => $tarefa, 'sistemas' => $sistemas, 'usuarios' => $usuarios])
            @include('tarefas._checklist-envios', ['tarefa' => $tarefa])
            @include('tarefas._comentarios-envios', ['tarefa' => $tarefa])
        </x-modal>
    @endforeach

    {{--
        Comentar recarrega a página, e sem isto o modal da tarefa fecharia a
        cada frase escrita. O evento é o mesmo que o clique no card dispara, e
        só depois de o Alpine já ter percorrido a página (`alpine:initialized`)
        — em `alpine:init` o modal ainda não está escutando e o aviso cairia no
        vazio.
    --}}
    @if (session('tarefa-aberta') && $tarefas->contains('id', session('tarefa-aberta')))
        <script>
            document.addEventListener('alpine:initialized', () => {
                window.dispatchEvent(new CustomEvent('open-modal', {
                    detail: 'editar-tarefa-{{ session('tarefa-aberta') }}',
                }));
            });
        </script>
    @endif
</x-app-layout>
