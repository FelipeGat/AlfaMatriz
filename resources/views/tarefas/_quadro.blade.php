@php
    /**
     * O corpo do quadro: cabeçalho com os chips, a tira de etapas do celular,
     * as colunas e os envios escondidos de mover e posicionar.
     *
     * Partial própria porque ela é desenhada em DOIS momentos. Ao abrir a tela,
     * de dentro do `index`; e a cada ação feita no modal da tarefa, quando
     * volta sozinha no JSON e é trocada no lugar sem recarregar a página (ver
     * `TarefaController::respostaParcial` e `trocarPedacos`, no `index`).
     *
     * O que fica de FORA dela é tão deliberado quanto o que está dentro: a
     * `<div x-data="quadroTarefas">` que a embrulha mora no `index`, e é por
     * isso que recolher colunas, a etapa escolhida no celular e a seleção do
     * teclado sobrevivem à troca — o componente Alpine não é recriado, só o
     * conteúdo dele.
     *
     * Espera: $chips, $etapas, $raias, $filtros.
     */
@endphp

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

        Cada um é também um filtro, e os quatro aparecem sempre —
        inclusive zerados. Zerado ele fica APAGADO em vez de sumir:
        o cabeçalho não muda de forma conforme o dia, e "0 travadas"
        também é notícia. Ver `chipsDoQuadro` para o porquê da
        divergência com o protótipo.
    --}}
    <div class="ml-auto min-w-0 flex items-center gap-2 overflow-x-auto" data-pedaco="chips-do-quadro">
        @include('tarefas._chips', ['chips' => $chips])
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

{{-- `data-rolagem` é o que faz a faixa voltar para onde estava depois de o
     quadro ser redesenhado por uma ação parcial. Sem ele, marcar um item numa
     tarefa da quinta coluna jogava a rolagem de volta à primeira. --}}
<div x-ref="quadro" data-quadro data-rolagem="quadro"
     @scroll="medirBordas()" @resize.window="medirBordas()" x-init="medirBordas()"
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
        <div class="sticky top-0 z-10 shrink-0 flex gap-[10px] bg-board pb-1">
            @foreach ($etapas as $etapa)
                <div class="rounded-control bg-panel border border-line overflow-hidden"
                     style="flex: 1 1 272px; min-width: 272px; border-top: 3px solid rgb(var(--{{ $etapa['cor'] }}))"
                     data-pedaco="etapa-{{ $etapa['chave'] }}">
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

        <div class="flex gap-[10px] items-stretch {{ $comRaias ? 'shrink-0' : 'flex-1 min-h-0' }}"
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
{{--
    O painel de motivo NÃO mora aqui.

    Ele era um bloco flutuante ancorado ao rodapé do quadro, e isso
    custava a ligação com o gesto: a pessoa soltava um card na
    terceira coluna e o pedido de texto aparecia lá embaixo, longe
    do card de que falava. No protótipo ele nasce DENTRO do card
    (ver `_card.blade.php`), logo abaixo do rodapé — o texto é sobre
    aquela tarefa e aparece nela.
--}}

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
<form x-ref="formMover" method="POST" action="" class="hidden" data-parcial>
    @csrf
    <input type="hidden" name="status" x-ref="statusMover">
    <input type="hidden" name="de_status" x-ref="deStatusMover">
</form>

{{-- A ordem da coluna, montada do DOM no solto sobre outro card. --}}
<form x-ref="formPosicionar" method="POST" action="{{ route('tarefas.posicionar') }}" class="hidden" data-parcial>
    @csrf
    <input type="hidden" name="status" x-ref="statusPosicionar">
</form>
