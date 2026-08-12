@php
    /**
     * Os destinos cuja chegada exige um texto — o que abre o painel de motivo.
     *
     * Em andamento está na lista, mas com ressalva: só pede texto quando a
     * tarefa vem de um portão (ver `pedeTexto`). Vindo do Backlog é só começar
     * a trabalhar, e um painel ali pediria justificativa para pegar a própria
     * tarefa.
     */
    $etapasComTexto = ['em_desenvolvimento', 'cancelada', 'concluida', 'pronta_producao'];
@endphp

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
        {{-- O chip "N p/ você" NÃO vive aqui: ele é o primeiro dos chips do
             cabeçalho do quadro, junto das outras duas contagens. Solto acima
             das abas, ele seria a única contagem fora do quadro que conta. --}}
        @include('tarefas._abas', ['ativa' => 'quadro'])

        {{--
            NÃO há faixa de KPI aqui, e isso é decisão, não esquecimento.

            O `AlfaMatriz Sistema.dc.html` mostra quatro números no topo desta
            tela, mas ele é a VERSÃO RESUMIDA — o próprio README diz isso. A
            referência do quadro é o `AlfaMatriz Tarefas.dc.html`, e lá o quadro
            começa logo abaixo dos filtros e ocupa a altura inteira. Uma faixa
            de cards aqui rouba ~100px de uma tela cuja única queixa era caber
            pouco card por coluna.
        --}}
        @include('tarefas._filtros', [
            'filtros' => $filtros,
            'sistemas' => $sistemas,
            'usuarios' => $usuarios,
            'raias' => $raias,
        ])

        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif
        @if (session('erro'))
            <x-aviso tom="critico">{{ session('erro') }}</x-aviso>
        @endif

        {{-- O teclado escuta na JANELA: o quadro não recebe foco, e exigir que
             ele recebesse obrigaria a clicar no fundo antes de a primeira seta
             funcionar. Quem filtra o que não deve disparar é o próprio
             `aoTeclar` (campo em foco, tecla com modificador). --}}
        <div x-data="quadroTarefas" @keydown.window="aoTeclar($event)"
             class="relative flex-1 min-h-0 flex flex-col rounded-panel border border-line bg-board overflow-hidden">
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
                {{-- `flex:0 0 auto` no título e `flex:0 1 auto` nos chips: quem
                     cede espaço primeiro são os chips, não o nome do quadro. É
                     a mesma prioridade de encolhimento da topbar. --}}
                <div class="shrink-0">
                    <h2 class="font-display text-[15px] font-semibold text-ink leading-tight whitespace-nowrap">Quadro de tarefas</h2>
                    <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint whitespace-nowrap">
                        {{ count($etapas) }} etapas · concluída = em produção
                    </p>
                </div>

                {{--
                    Os chips do quadro.

                    Eles são o que sobrou das faixas verticais de solto: aquelas
                    faziam duas coisas, receber o gesto e mostrar as contagens.
                    O gesto foi para os botões do card; a contagem é trabalho de
                    cabeçalho, e aqui não custa 132px de largura.

                    Cada um é também um filtro. Chip zerado não aparece: um "0
                    travadas" permanente ensina a não ler a fila.
                --}}
                <div class="ml-auto min-w-0 flex items-center gap-2 overflow-x-auto">
                    @foreach ($chips as $chip)
                        <a href="{{ $chip['href'] }}" title="{{ $chip['title'] }}"
                           class="shrink-0 h-[26px] px-[9px] inline-flex items-center gap-1.5 rounded-tile border
                                  font-mono text-[10.5px] font-semibold uppercase tracking-[0.06em] whitespace-nowrap transition"
                           style="border-color: {{ $chip['borda'] }}; background: {{ $chip['fundo'] }}; color: rgb(var(--{{ $chip['cor'] }}))">
                            <x-nav-icon :name="$chip['icone']" :peso="1.9" class="h-3 w-3 shrink-0" />
                            {{ $chip['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>

            @php
                // Sem raias o quadro é uma faixa só, que ocupa a altura toda.
                // Com raias ele empilha, e a rolagem passa a ser vertical.
                $comRaias = $raias['modo'] !== 'nenhuma';
            @endphp

            {{--
                A tira de etapas do celular.

                Ela substitui as colunas que não cabem, e por isso carrega o que
                o cabeçalho da coluna carregaria: o nome, a contagem e o aviso
                de limite estourado. Sem a contagem, trocar de etapa viraria
                tentativa e erro — a pessoa tocaria em cada chip para descobrir
                onde está o trabalho.

                Alvos de 44px porque é um controle de dedo, e 44px é o mínimo
                que não erra o vizinho.
            --}}
            <div class="lg:hidden shrink-0 -mx-1 flex gap-1.5 overflow-x-auto pb-1">
                @foreach ($etapas as $etapa)
                    <button type="button" @click="etapaMobile = '{{ $etapa['chave'] }}'"
                            class="shrink-0 h-11 px-3 rounded-control border text-[12.5px] whitespace-nowrap transition"
                            :class="etapaMobile === '{{ $etapa['chave'] }}'
                                ? 'border-brand text-ink font-semibold bg-chip'
                                : 'border-line text-ink-mute'">
                        {{ $etapa['label'] }}
                        <span class="ml-1 font-mono text-[11px]"
                              style="color: rgb(var(--{{ $etapa['acimaDoLimite'] ? 'warn' : $etapa['cor'] }}))">
                            @if ($etapa['limite'])
                                {{ $etapa['andando'] }}/{{ $etapa['limite'] }}
                            @else
                                {{ $etapa['quantidade'] }}
                            @endif
                        </span>
                    </button>
                @endforeach
            </div>

            <div x-ref="quadro" data-quadro @scroll="medirBordas()" @resize.window="medirBordas()" x-init="medirBordas()"
                 :class="'etapa-' + etapaMobile"
                 class="relative flex-1 min-h-0 flex flex-col gap-3 overflow-auto p-3.5">

                {{--
                    Em raias, o cabeçalho das etapas fica FIXO no topo: as faixas
                    empilham e a rolagem vira vertical, então sem isto a pessoa
                    perde de vista em que coluna está olhando na terceira raia.
                    O espaçador da direita mantém o alinhamento com as duas
                    faixas de solto, que existem em cada linha de raia.
                --}}
                @if ($comRaias)
                    <div class="sticky top-0 z-10 shrink-0 flex gap-3 bg-board pb-1">
                        @foreach ($etapas as $etapa)
                            <div class="rounded-control bg-panel border border-line overflow-hidden"
                                 style="flex: 1 1 276px; min-width: 276px; border-top: 3px solid rgb(var(--{{ $etapa['cor'] }}))">
                                @include('tarefas._coluna-cabecalho', ['etapa' => $etapa])
                            </div>
                        @endforeach
                        {{-- Sem espaçador: ele existia para alinhar com as duas
                             faixas de solto, que saíram do quadro. --}}
                    </div>
                @endif

                @foreach ($raias['faixas'] as $faixa)
                    @if ($faixa['titulo'])
                        <header class="shrink-0 flex items-center gap-2 pt-1">
                            <h3 class="font-display text-[13.5px] font-semibold text-ink">{{ $faixa['titulo'] }}</h3>

                            {{--
                                Mais de duas em andamento: o selo não é elogio
                                nem bronca, é a conta que a raia por pessoa
                                existe para fazer. Quem está com quatro coisas
                                ao mesmo tempo não está tocando quatro — está
                                revezando entre elas.
                            --}}
                            @if ($faixa['sobrecarga'])
                                <x-badge tom="atencao" title="Mais de duas tarefas em andamento ao mesmo tempo">
                                    trabalho em paralelo
                                </x-badge>
                            @endif
                        </header>
                    @endif

                    <div class="flex gap-3 items-stretch {{ $comRaias ? 'shrink-0' : 'flex-1 min-h-0' }}"
                         @if ($comRaias) style="min-height: 180px" @endif>
                        @foreach ($etapas as $etapa)
                            @include('tarefas._coluna', [
                                'etapa' => $etapa,
                                'cards' => $faixa['colunas'][$etapa['chave']],
                                'faixa' => $faixa['chave'],
                                'comCabecalho' => ! $comRaias,
                            ])
                        @endforeach

                {{--
                    As duas faixas verticais de solto (Bloquear e Concluir)
                    SAÍRAM daqui, e não é esquecimento.

                    Elas gastavam 132px de largura do quadro para dar destino a
                    um gesto — e largura é justamente o que falta numa tela de
                    seis colunas. O que faziam bem, mostrar as duas contagens e
                    servir de porta para travadas e histórico, virou trabalho do
                    cabeçalho: são os chips lá em cima. A ação em si foi para o
                    card, que é de quem ela fala.
                --}}
                    </div>
                @endforeach
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
                    <input type="hidden" name="de_status" :value="pendente?.de">

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

            {{--
                Os atalhos, atrás do "?".

                Atalho que ninguém descobre é atalho que não existe — e a
                alternativa, uma legenda fixa no rodapé, cobraria espaço de todo
                mundo o tempo todo para servir a quem já decorou.
            --}}
            <div x-show="atalhosAbertos" x-cloak x-transition.opacity.duration.150ms
                 @click="atalhosAbertos = false"
                 class="absolute inset-0 z-30 flex items-center justify-center p-4"
                 style="background: rgb(var(--canvas) / 0.75)">
                <div @click.stop class="w-[420px] max-w-full rounded-panel border border-line bg-panel p-4 shadow-xl">
                    <div class="flex items-center gap-2">
                        <h3 class="font-display text-[14.5px] font-semibold text-ink">Atalhos do quadro</h3>
                        <button type="button" @click="atalhosAbertos = false" aria-label="Fechar"
                                class="ml-auto h-6 w-6 rounded-control text-ink-faint hover:text-ink transition">✕</button>
                    </div>

                    <dl class="mt-3 space-y-1.5">
                        @foreach ([
                            '↑ ↓' => 'Anda pelos cards da coluna',
                            '← →' => 'Troca de coluna',
                            '⇧ ← →' => 'Move a tarefa de etapa',
                            'B' => 'Bloqueia ou destrava',
                            'M' => 'Abre o menu de mover',
                            'Enter' => 'Abre a tarefa',
                            'C' => 'Criação rápida',
                            'N' => 'Nova tarefa (formulário)',
                            '/' => 'Busca',
                            'Esc' => 'Fecha o que estiver aberto',
                            '?' => 'Mostra esta lista',
                        ] as $tecla => $oQueFaz)
                            <div class="flex items-center gap-3">
                                <dt class="shrink-0 w-[72px] font-mono text-[11px] text-ink-dim">{{ $tecla }}</dt>
                                <dd class="text-[12.5px] text-ink-mute">{{ $oQueFaz }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </div>

            {{-- Um formulário só, apontado para a tarefa que foi solta. --}}
            <form x-ref="formMover" method="POST" action="" class="hidden">
                @csrf
                <input type="hidden" name="status" x-ref="statusMover">
                <input type="hidden" name="de_status" x-ref="deStatusMover">
            </form>

            {{-- A ordem da coluna, montada do DOM no solto sobre outro card. --}}
            <form x-ref="formPosicionar" method="POST" action="{{ route('tarefas.posicionar') }}" class="hidden">
                @csrf
                <input type="hidden" name="status" x-ref="statusPosicionar">
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

                // A etapa em que o card estava quando foi pego: viaja no envio
                // para o servidor recusar movimento sobre movimento alheio.
                statusArrastado: null,

                // Posicionar card na coluna é organizar trabalho alheio, e
                // segue a mesma capacidade de priorizar e direcionar.
                podeTriar: {{ auth()->user()?->podeTriarTarefas() ? 'true' : 'false' }},

                // A etapa que o celular está mostrando. No quadro largo ela não
                // faz nada: quem esconde as outras é o CSS, por media query.
                etapaMobile: @json($etapas[0]['chave'] ?? 'aberta'),

                // A ordem das etapas, para as setas saberem o que é "a próxima".
                etapasEmOrdem: @json(array_column($etapas, 'chave')),

                // Etapas que só se alcança com texto: o teclado abre o painel
                // nelas, como o arraste faz.
                {{-- Por variável, e não literal: o `@json` do Blade separa os
                     argumentos com `explode(',')`, então um array escrito à mão
                     com quatro elementos perde o último e sai com o colchete
                     aberto — erro de sintaxe na view compilada, não no Blade. --}}
                etapasComTexto: @json($etapasComTexto),

                // Os portões do ciclo: é de onde a tarefa VEM que decide se
                // voltar para a bancada é reprovação ou só começar a trabalhar.
                portoes: @json(\App\Models\Tarefa::PORTOES),

                selecionado: null,
                atalhosAbertos: false,

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

                /**
                 * Reordenar dentro da coluna, quando o card é solto sobre outro
                 * card da MESMA etapa.
                 *
                 * A ordem automática responde "o que é mais grave", que não é a
                 * mesma pergunta que "qual eu pego primeiro" — entre duas altas,
                 * quem conhece o trabalho sabe que uma destrava a outra.
                 *
                 * Posicionar é organizar trabalho alheio, então segue a mesma
                 * capacidade de priorizar: sem ela, o solto sobre card não faz
                 * nada e o evento sobe para a coluna, que decide se é movimento
                 * de etapa.
                 */
                ehReordenacao(status) {
                    return this.podeTriar
                        && this.arrastando !== null
                        && this.statusArrastado === status;
                },

                permitirSobreCard(evento, status) {
                    if (this.ehReordenacao(status)) {
                        evento.preventDefault();
                        evento.stopPropagation();
                    }
                },

                soltarSobreCard(evento, alvo, status, chaveDaLista) {
                    if (! this.ehReordenacao(status)) {
                        return;
                    }

                    evento.preventDefault();
                    evento.stopPropagation();

                    // A lista é a da FAIXA, não a do status: com raias ligadas,
                    // a mesma etapa aparece uma vez por raia, e reordenar
                    // pegaria a primeira delas em vez daquela onde se soltou.
                    const lista = document.querySelector(`[data-cards="${chaveDaLista}"]`);
                    const arrastado = lista.querySelector(`[data-tarefa="${this.arrastando}"]`);

                    this.largar();

                    if (! arrastado || arrastado === alvo) {
                        return;
                    }

                    const linhas = [...lista.children];
                    const destino = linhas.indexOf(alvo);
                    const origem = linhas.indexOf(arrastado);

                    lista.insertBefore(arrastado, origem < destino ? alvo.nextSibling : alvo);

                    // A coluna inteira vai no envio, lida do DOM: mandar só "X
                    // foi para N" faria o servidor recalcular o que o navegador
                    // já sabe, e divergir no segundo arrasto.
                    const envio = this.$refs.formPosicionar;
                    envio.querySelectorAll('input[data-ordem]').forEach((campo) => campo.remove());
                    this.$refs.statusPosicionar.value = status;

                    [...lista.children].forEach((linha) => {
                        const campo = document.createElement('input');
                        campo.type = 'hidden';
                        campo.name = 'ordem[]';
                        campo.dataset.ordem = '1';
                        campo.value = linha.dataset.tarefa;
                        envio.appendChild(campo);
                    });

                    envio.submit();
                },

                /**
                 * O teclado do quadro.
                 *
                 * Quem passa o dia aqui move dezenas de cards, e cada um custa
                 * pegar o mouse, mirar e arrastar. As setas fazem o mesmo
                 * percurso sem tirar a mão de onde ela já está.
                 *
                 * Nada dispara enquanto se digita: sem esta guarda, escrever
                 * "backlog" na busca moveria cards pelo caminho — o `b` bloqueia
                 * e o `c` abre criação rápida. É a primeira coisa a conferir,
                 * porque o estrago é silencioso.
                 */
                aoTeclar(evento) {
                    const digitando = evento.target.closest('input, textarea, select, [contenteditable]');
                    const tecla = evento.key;

                    if (tecla === 'Escape') {
                        this.fecharPendente();
                        this.atalhosAbertos = false;

                        return;
                    }

                    if (digitando || evento.metaKey || evento.ctrlKey || evento.altKey) {
                        return;
                    }

                    const acoes = {
                        // `document` e não `$refs`: a busca vive na barra de
                        // filtros, que é irmã do quadro e não filha dele — e a
                        // criação rápida se repete por coluna, então um `ref`
                        // ali seria um nome disputado por vários elementos.
                        '/': () => document.querySelector('input[name="busca"]')?.focus(),
                        '?': () => (this.atalhosAbertos = ! this.atalhosAbertos),
                        n: () => this.$dispatch('open-modal', 'nova-tarefa'),
                        c: () => document.querySelector('[data-criacao-rapida]')?.focus(),
                        ArrowUp: () => this.andarNaColuna(-1),
                        ArrowDown: () => this.andarNaColuna(1),
                        ArrowLeft: () => (evento.shiftKey ? this.moverUmaEtapa(-1) : this.andarEntreColunas(-1)),
                        ArrowRight: () => (evento.shiftKey ? this.moverUmaEtapa(1) : this.andarEntreColunas(1)),
                        Enter: () => this.abrirSelecionado(),
                        m: () => this.abrirMenuDoSelecionado(),
                        b: () => this.travarSelecionado(),
                    };

                    const acao = acoes[tecla] ?? acoes[tecla.toLowerCase?.()];

                    if (acao) {
                        evento.preventDefault();
                        acao();
                    }
                },

                /** Os cards visíveis de uma etapa, na ordem em que estão na tela. */
                cardsDaEtapa(status) {
                    return [...document.querySelectorAll(`section[data-status="${status}"] [data-tarefa]`)];
                },

                elementoSelecionado() {
                    return this.selecionado === null
                        ? null
                        : document.querySelector(`[data-tarefa="${this.selecionado}"]`);
                },

                etapaDoSelecionado() {
                    return this.elementoSelecionado()?.closest('section[data-status]')?.dataset.status ?? null;
                },

                selecionar(elemento) {
                    if (! elemento) {
                        return;
                    }

                    this.selecionado = Number(elemento.dataset.tarefa);
                    elemento.scrollIntoView({ block: 'nearest', inline: 'nearest' });
                },

                andarNaColuna(passo) {
                    const status = this.etapaDoSelecionado() ?? this.etapasEmOrdem[0];
                    const cards = this.cardsDaEtapa(status);
                    const atual = cards.findIndex((c) => Number(c.dataset.tarefa) === this.selecionado);

                    this.selecionar(cards[Math.max(0, Math.min(cards.length - 1, atual + passo))] ?? cards[0]);
                },

                andarEntreColunas(passo) {
                    const status = this.etapaDoSelecionado() ?? this.etapasEmOrdem[0];
                    let indice = this.etapasEmOrdem.indexOf(status);

                    // Pula as etapas vazias: parar numa coluna sem card seria
                    // perder a seleção e obrigar a voltar.
                    for (let i = 0; i < this.etapasEmOrdem.length; i++) {
                        indice += passo;

                        if (indice < 0 || indice >= this.etapasEmOrdem.length) {
                            return;
                        }

                        const cards = this.cardsDaEtapa(this.etapasEmOrdem[indice]);

                        if (cards.length) {
                            this.etapaMobile = this.etapasEmOrdem[indice];
                            this.selecionar(cards[0]);

                            return;
                        }
                    }
                },

                abrirSelecionado() {
                    if (this.selecionado !== null) {
                        this.$dispatch('open-modal', 'editar-tarefa-' + this.selecionado);
                    }
                },

                abrirMenuDoSelecionado() {
                    const alvo = this.elementoSelecionado();

                    if (alvo) {
                        Alpine.$data(alvo).menuAberto = ! Alpine.$data(alvo).menuAberto;
                    }
                },

                travarSelecionado() {
                    const alvo = this.elementoSelecionado();

                    if (! alvo) {
                        return;
                    }

                    // Destravar é imediato — não há o que perguntar. Travar
                    // passa pelo painel, porque o motivo é obrigatório.
                    if (alvo.dataset.bloqueada) {
                        alvo.querySelector('form[action*="bloquear"]')?.requestSubmit();

                        return;
                    }

                    this.prepararTeclado(alvo);
                    this.abrirPendente(this.selecionado, 'bloqueio');
                },

                /** Põe o card selecionado no mesmo estado que um `dragstart` põe. */
                prepararTeclado(alvo) {
                    this.pegar(
                        Number(alvo.dataset.tarefa),
                        JSON.parse(alvo.dataset.destinos || '[]'),
                        alvo.dataset.tipo,
                        !! alvo.dataset.bloqueada,
                        alvo.closest('section[data-status]')?.dataset.status,
                    );
                },

                moverUmaEtapa(passo) {
                    const alvo = this.elementoSelecionado();

                    if (! alvo) {
                        return;
                    }

                    const status = this.etapaDoSelecionado();
                    const destino = this.etapasEmOrdem[this.etapasEmOrdem.indexOf(status) + passo];

                    this.prepararTeclado(alvo);

                    // Fora do fluxo, nada acontece — e não é silêncio: a coluna
                    // vizinha já está apagada na tela desde que a seleção
                    // aconteceu, então a recusa foi anunciada antes do gesto.
                    if (! destino || ! this.aceita(destino)) {
                        this.largar();

                        return;
                    }

                    this.soltar(destino, this.pedeTexto(destino));
                },

                /**
                 * Este destino abre o painel de motivo?
                 *
                 * Em andamento é o caso que não se resolve só pelo destino:
                 * vindo de um portão ele é reprovação e cobra o texto; vindo do
                 * Backlog é só começar a trabalhar, e não há motivo a dar. Um
                 * painel ali pediria justificativa para pegar a própria tarefa.
                 */
                pedeTexto(destino) {
                    if (destino === 'em_desenvolvimento') {
                        return this.portoes.includes(this.statusArrastado);
                    }

                    return this.etapasComTexto.includes(destino);
                },

                // Onde o ponteiro desceu, e quando o último arrasto terminou.
                inicioDoClique: null,
                fimDoArrasto: 0,

                marcarInicioDoClique(evento) {
                    this.inicioDoClique = { x: evento.clientX, y: evento.clientY };
                },

                /**
                 * Foi clique, ou foi o fim de um arrasto?
                 *
                 * O card abre o detalhe no clique E arrasta, e sem separar os
                 * dois o gesto de arrastar terminava com o modal aberto por
                 * cima — inclusive quando o arrasto foi recusado, porque aí o
                 * `click` chega igual.
                 *
                 * Duas peneiras, porque uma só não pega os dois casos: 4px de
                 * folga para a mão que treme sobre um clique legítimo, e 300ms
                 * de carência depois de um arrasto, para o `click` que o
                 * navegador dispara ao soltar não passar por clique.
                 */
                foiClique(evento) {
                    if (Date.now() - this.fimDoArrasto < 300) {
                        return false;
                    }

                    if (! this.inicioDoClique) {
                        return true;
                    }

                    const andou = Math.hypot(
                        evento.clientX - this.inicioDoClique.x,
                        evento.clientY - this.inicioDoClique.y,
                    );

                    return andou < 4;
                },

                /** Esta etapa é destino possível para o card que está na mão? */
                aceita(status) {
                    return this.arrastando === null || this.destinos.includes(status);
                },

                pegar(tarefa, destinos, tipo, bloqueada, status) {
                    this.arrastando = tarefa;
                    // Bloquear é destino de quem ainda não está travado; para
                    // quem já está, a saída é o botão da própria tarja.
                    this.destinos = bloqueada ? destinos : [...destinos, 'bloqueio'];
                    this.tipoArrastado = tipo;
                    this.statusArrastado = status;
                },

                largar() {
                    if (this.arrastando !== null) {
                        this.fimDoArrasto = Date.now();
                    }

                    this.arrastando = null;
                    this.destinos = [];
                    this.tipoArrastado = null;
                    this.statusArrastado = null;
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
                        // A devolução muda de sentido conforme o portão que
                        // reprovou — é isso que a coluna única de Ajustes
                        // achatava. Vindo do staging o código JÁ está na main,
                        // e a recuperação é materialmente outra.
                        em_desenvolvimento: {
                            titulo: 'Devolvendo para Em andamento',
                            porque: {
                                em_revisao: 'Diga o que precisa ser corrigido no PR. Sem isso, quem recebe abre o card sem saber o que reprovou.',
                                em_staging: 'Falhou em staging — o código JÁ está na main. Diga o que quebrou e se precisa voltar a versão (deploy/voltar.sh) ou dá para corrigir seguindo em frente.',
                                pronta_producao: 'Reprovada antes de a tag subir. Diga o que apareceu.',
                            }[this.statusArrastado] ?? 'Diga o que precisa ser corrigido.',
                            placeholder: {
                                em_revisao: 'O que precisa ser corrigido no PR…',
                                em_staging: 'O que quebrou no staging · voltar ou corrigir em frente…',
                                pronta_producao: 'O que apareceu antes de subir…',
                            }[this.statusArrastado] ?? 'O que precisa ser corrigido…',
                            botao: 'Devolver para correção',
                            cor: 'warn', campo: 'motivo', obrigatorio: true, pedeAprovacao: false,
                        },
                        // O carimbo do staging: é aqui que o dev afirma ter
                        // validado, e é essa nota que o admin lê antes de
                        // taggear. Texto opcional; o que importa é o carimbo.
                        pronta_producao: {
                            titulo: 'Liberando para Pronta p/ produção',
                            porque: 'Vai para a fila do admin, que sobe a tag. Diga o que você conferiu no staging — é o que ele lê antes de subir.',
                            placeholder: 'O que foi conferido no staging…',
                            botao: 'Liberar para o admin subir',
                            cor: 'good',
                            campo: ehDev ? 'relatorio_notas' : null,
                            obrigatorio: false,
                            pedeAprovacao: ehDev,
                        },
                        cancelada: {
                            titulo: 'Cancelando a tarefa',
                            porque: 'Cancelar encerra a tarefa. O motivo fica no histórico, e é o que explica a decisão para quem a reabrir um dia.',
                            placeholder: 'Motivo do cancelamento…',
                            botao: 'Cancelar tarefa',
                            cor: 'crit', campo: 'motivo', obrigatorio: true, pedeAprovacao: false,
                        },
                        concluida: {
                            titulo: 'Encerrando como Concluída',
                            porque: ehDev
                                ? 'Concluída significa EM PRODUÇÃO: a tag subiu e o vigia aplicou. Registre a versão — é ela que responde "desde quando o cliente tem isso".'
                                : 'Tarefa operacional não passa por PR nem por tag. Registre o que foi feito — é o que sobra como prova depois.',
                            placeholder: ehDev ? 'v1.4.2' : 'O que foi feito…',
                            botao: ehDev ? 'Subiu para produção' : 'Encerrar tarefa',
                            cor: 'good',
                            campo: ehDev ? 'versao_producao' : 'relatorio_notas',
                            obrigatorio: ehDev,
                            pedeAprovacao: false,
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
                        de: this.statusArrastado,
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
                    const de = this.statusArrastado;

                    this.largar();
                    this.tipoArrastado = tipo;
                    this.statusArrastado = de;

                    if (tarefa === null || ! permitido) {
                        this.tipoArrastado = null;
                        this.statusArrastado = null;

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

                    this.$refs.formMover.action = this.rotaMover.replace('__ID__', tarefa);
                    this.$refs.statusMover.value = status;
                    this.$refs.deStatusMover.value = de ?? '';
                    this.tipoArrastado = null;
                    this.statusArrastado = null;
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
