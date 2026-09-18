{{--
    O painel do sino.

    Ele é IRMÃO do <aside>, e não filho: a sidebar tem `overflow: hidden` (para
    a transição de largura do rail) e um `transform` (para a gaveta do celular).
    O primeiro corta qualquer filho que passe da borda; o segundo faz o aside
    virar bloco contido, o que impede até `position: fixed` de escapar. Um
    painel ancorado lá dentro simplesmente não apareceria.

    A outra armadilha, a que o handoff nomeia: o aside é `position: sticky`, e
    sticky cria contexto de empilhamento mesmo com `z-index: auto`. Ele já
    carrega `z-40`; o painel fica acima disso para não ser pintado atrás do
    <main>.

    Espera (do composer de `layouts.navigation`): $notificacoes, $naoLidas.
--}}

@php
    /**
     * O rodapé leva ao Centro de Controle — quando a conta o alcança.
     *
     * São DUAS condições, e não uma: a permissão e o escopo. Conta de revenda
     * pode ter `dashboard` no perfil e mesmo assim não ver a tela, porque o
     * menu barra tudo que é da matriz antes de olhar permissão. Checar só a
     * permissão deixaria o endereço vazar pelo painel — e o menu é montado a
     * partir do que a pessoa PODE ver justamente para não contar a ela sobre
     * telas que não existem do lado dela.
     */
    $usuarioDoSino = auth()->user();
    $veCentroDeControle = $usuarioDoSino
        && ! $usuarioDoSino->temEscopoDeRevenda()
        && $usuarioDoSino->canPermissao('dashboard', 'ler');
@endphp

{{-- Overlay: fecha ao clicar fora, e fica ABAIXO do painel. --}}
<div x-show="sinoAberto" x-cloak @click="sinoAberto = false"
     class="fixed inset-0 z-40" x-transition.opacity.duration.150ms></div>

{{--
    Ancorado ao rodapé da sidebar, e não ao sino: o rodapé está no mesmo lugar
    com o menu aberto e recolhido, então o painel acompanha o rail sem precisar
    recalcular posição. A largura é a do handoff (352px), com teto para não
    estourar a tela estreita.
--}}
<div x-show="sinoAberto" x-cloak
     @keydown.escape.window="sinoAberto = false"
     x-transition:enter="transition ease-out duration-150"
     x-transition:enter-start="opacity-0 translate-y-1"
     x-transition:leave="transition ease-in duration-100"
     x-transition:leave-end="opacity-0"
     class="fixed bottom-3 z-50 w-[352px] max-w-[calc(100vw-24px)]
            left-3 lg:left-[calc(theme(spacing.sidebar)+12px)] rail:lg:left-[calc(theme(spacing.rail)+12px)]
            rounded-panel border border-line bg-panel overflow-hidden flex flex-col">

    <header class="h-[38px] shrink-0 flex items-center gap-2 px-4 border-b border-line bg-head">
        <h2 class="font-display text-[13.5px] font-semibold text-ink">Notificações</h2>

        @if (($naoLidas ?? 0) > 0)
            <form method="POST" action="{{ route('notificacoes.lidas') }}" class="ml-auto">
                @csrf
                <button type="submit"
                        class="font-mono text-[10px] uppercase tracking-caps text-ink-faint hover:text-brand transition">
                    Marcar lidas
                </button>
            </form>
        @endif
    </header>

    {{--
        O id `sino-lista` é onde o poll injeta a lista fresca quando algo chega
        durante a sessão: sem isso, abrir o sino depois de um aviso novo
        mostraria a lista da carga da página — o contador subiria, mas o painel
        continuaria velho. A marcação da linha mora no partial, uma só, usada
        aqui e no endpoint que o poll busca.
    --}}
    <div id="sino-lista" class="min-h-0 flex-1 max-h-[min(420px,60vh)] overflow-y-auto">
        @include('layouts._notificacoes-lista', ['notificacoes' => $notificacoes ?? collect()])
    </div>

    {{--
        O sino conta o que MUDOU; a fila do Centro de Controle mostra o que
        EXIGE AÇÃO e vale enquanto durar. São perguntas diferentes — "3 receitas
        em atraso" não é um evento, é um estado —, e é por isso que o rodapé
        leva de uma à outra em vez de as duas listas tentarem ser a mesma.
    --}}
    @if ($veCentroDeControle)
        <footer class="h-[38px] shrink-0 flex items-center px-4 border-t border-line bg-head">
            <a href="{{ route('centro-controle') }}"
               class="font-mono text-[10px] uppercase tracking-caps text-ink-faint hover:text-brand transition">
                Ver tudo que exige ação
            </a>
        </footer>
    @endif
</div>

{{--
    O aviso flutuante e a configuração do poll vivem aqui, fora do painel do
    sino mas dentro do `x-data="shell"` da moldura — o card lê `sinoAviso` e
    `sinoNovas`, que o poll do shell move.

    `window.__sino` é injetado por um <script> CLÁSSICO no corpo, e não por um
    módulo: o script clássico roda durante a análise do HTML, ANTES do módulo do
    Vite (que é adiado), então o baseline já está em pé quando o Alpine lê. É
    nulo para a conta de exibição — o monitor da parede não recebe aviso e não
    deve gastar poll — e some junto com a sessão.
--}}
@auth
    @unless (auth()->user()->ehContaDeExibicao())
        <script>
            window.__sino = {
                url: @json(route('notificacoes.resumo')),
                urlLista: @json(route('notificacoes.lista')),
                naoLidas: {{ (int) ($naoLidas ?? 0) }},
                ultimoId: {{ (int) (($notificacoes ?? collect())->max('id') ?? 0) }},
            };
        </script>

        {{--
            O card fica até a pessoa fechar — nada de relógio. Quem levantou da
            mesa e voltou dez minutos depois ainda o encontra ali; um toast que
            some sozinho recriaria a discrição que o sino já tinha. Clicar no
            corpo abre o sino (e reconhece o aviso); o × dispensa sem abrir.

            Canto inferior direito, longe da pilha de \"salvo\" (topo, centro) e
            do painel do sino (inferior esquerdo): três coisas flutuantes, três
            cantos, nenhuma por cima da outra.
        --}}
        <div x-show="sinoAviso" x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-2"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-end="opacity-0"
             class="fixed bottom-4 right-4 z-[65] w-[300px] max-w-[calc(100vw-32px)]
                    rounded-panel border border-line bg-panel overflow-hidden
                    shadow-[0_12px_32px_rgb(0_0_0_/_0.32)]">
            {{--
                O card mostra a notificação MAIS RECENTE — título e prévia —, e
                não só "N novas": um contador diz que há algo, o conteúdo diz o
                QUE é, e às vezes já resolve sem abrir o painel. O ícone é
                tingido pelo tom do aviso (o mesmo mapa do painel), para a
                gravidade se ler de relance.
            --}}
            <div role="button" tabindex="0"
                 @click="abrirSino()" @keydown.enter="abrirSino()"
                 class="flex items-start gap-3 p-3 text-left cursor-pointer transition hover:bg-chip">
                <span class="shrink-0 mt-px h-8 w-8 rounded-tile flex items-center justify-center"
                      :style="sinoUltima
                          ? `background: rgb(var(--${sinoUltima.tom}) / var(--tint-alpha)); color: rgb(var(--${sinoUltima.tom}))`
                          : 'background: rgb(var(--brand) / var(--tint-alpha)); color: rgb(var(--brand))'">
                    <span class="h-[16px] w-[16px]"><x-nav-icon name="bell" :peso="1.8" /></span>
                </span>

                <span class="min-w-0 flex-1">
                    {{-- Título numa linha, truncado; a prévia (o meta do aviso)
                         embaixo, também truncada — o card é relance, o resto
                         está no painel a um clique. --}}
                    <span class="block truncate text-[13px] font-semibold text-ink"
                          x-text="sinoUltima ? sinoUltima.titulo : 'Nova notificação'"></span>
                    <span class="block truncate text-[11.5px] text-ink-mute"
                          x-text="sinoUltima && sinoUltima.meta ? sinoUltima.meta : 'Clique para ver'"></span>
                </span>

                <button type="button" @click.stop="dispensarAvisoSino()"
                        class="shrink-0 h-6 w-6 rounded-badge text-ink-faint hover:text-ink transition flex items-center justify-center"
                        aria-label="Dispensar">
                    <span class="h-3.5 w-3.5"><x-nav-icon name="x-mark" :peso="1.7" /></span>
                </button>
            </div>

            {{-- "Ver todas" só quando há mais de três: com uma ou duas, o card
                 mais o número no sino já bastam, e a linha extra seria ruído. --}}
            <template x-if="naoLidas > 3">
                <button type="button" @click="abrirSino()"
                        class="w-full border-t border-rule px-3 py-2 text-left
                               font-mono text-[10px] uppercase tracking-caps text-brand hover:text-brand-bright transition">
                    Ver todas · <span x-text="naoLidas"></span> não lidas
                </button>
            </template>
        </div>
    @endunless
@endauth
