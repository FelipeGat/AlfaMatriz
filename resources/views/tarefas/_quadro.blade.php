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
     * Espera: $chips, $etapas, $raias, $filtros, $recortes.
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
    {{-- A identidade é o que CEDE o lugar na tela cheia: ali o quadro é a tela
         inteira, e dizer que ele é o quadro repete o que já se está vendo. Os
         ~190px que ela ocupa são o orçamento da barra que entra logo abaixo.
         Quem esconde é o CSS, antes da primeira pintura (ver `app.css`). --}}
    <div class="shrink-0" data-fora-da-tela-cheia>
        <h2 class="font-display text-[15px] font-semibold text-ink leading-tight whitespace-nowrap">Quadro de tarefas</h2>
        <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint whitespace-nowrap">
            {{ count($etapas) }} etapas · concluída = em produção
        </p>
    </div>

    {{-- A porta da legenda da esteira: só o ícone, por escolha do dono — a
         versão com rótulo ("como funciona") foi recusada. O tamanho é o dos
         ícones da sidebar (18px): além do precedente, 18/24 é escala exata
         de 0,75, e foi a interrogação desenhada em escala quebrada que
         chegou deformada na primeira versão. `mute` e não `faint`: ícone
         sozinho, sem borda, a cor é o único anúncio de que ele existe. --}}
    <button type="button" @click="fluxoAberto = true"
            title="Como a esteira funciona" aria-label="Como a esteira funciona"
            class="shrink-0 h-[26px] w-[26px] rounded-badge flex items-center justify-center
                   text-ink-mute hover:text-brand transition">
        <span class="h-[18px] w-[18px]"><x-nav-icon name="duvida" :peso="1.7" /></span>
    </button>

    {{--
        O que a tela cheia levava embaixo dela, de volta — e no lugar que a
        identidade do quadro desocupou.

        Expandido, o quadro cobre a topbar (onde mora o "+ Nova tarefa") e a
        barra de busca e filtros: não havia como criar nem procurar sem sair do
        modo, e sair do modo para criar uma tarefa é o gesto que faz ninguém
        usar o modo. A altura do cabeçalho não muda — 52px antes e depois; o
        que muda é quem ocupa os ~190px do título.

        Dois controles, e não a barra inteira: o botão TRAZ a barra de verdade
        para cima do quadro (ver `alternarFiltros` e a regra do `app.css`). Uma
        segunda cópia dos selects aqui seria a cópia que fica para trás no
        primeiro filtro novo — e ~4 KB de HTML em toda visita ao quadro.
    --}}
    <div data-so-na-tela-cheia class="shrink-0 items-center gap-2">
        <button type="button" @click.stop="alternarFiltros()"
                :aria-expanded="filtrosAbertos.toString()"
                title="Buscar, filtrar e agrupar em raias · /"
                class="shrink-0 h-[26px] px-2.5 rounded-badge border flex items-center gap-1.5
                       text-[12px] transition"
                :class="filtrosAbertos
                    ? 'border-brand text-brand-text'
                    : 'border-btn-line text-ink-dim hover:text-brand hover:border-brand'">
            <span class="h-3 w-3"><x-nav-icon name="funil" :peso="1.8" /></span>
            Buscar e filtrar
            {{-- Quantos recortes estão ligados. Sem o número, a barra fechada
                 esconde que o quadro está recortado — e quadro recortado é
                 indistinguível de quadro vazio. --}}
            @if ($recortes)
                <span class="font-sans tabular text-[10px] text-brand-text">{{ count($recortes) }}</span>
            @endif
        </button>

        {{-- A porta de criar, que era da topbar. Preenchida na cor da marca
             porque aqui ela é a única ação primária da tela. --}}
        <button type="button" @click="$dispatch('open-modal', 'nova-tarefa')"
                class="shrink-0 h-[26px] px-2.5 rounded-control bg-brand text-on-brand
                       font-semibold text-[12px] whitespace-nowrap hover:bg-brand-bright transition">
            + Nova tarefa
        </button>
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
    {{-- O `data-pedaco` NÃO é este contêiner: as ações parciais trocam o
         innerHTML do pedaço por SÓ os chips de contagem (`_chips`), e tudo o
         mais que morasse dentro dele — as pílulas, o "ver como quadro" —
         sumiria no primeiro clique num checklist. O pedaço é o embrulho
         estreito lá embaixo, com exatamente o que a partial devolve. --}}
    <div class="ml-auto min-w-0 flex items-center gap-2 overflow-x-auto">
        {{-- O recorte ativo, nomeado (AC-355): o select guarda o valor mas
             não o anuncia — com pessoa+sistema ligados a tela só contava
             "X de Y tarefas". Uma pílula por filtro, e cada ✕ tira só o seu:
             no quadro fixado da raia (AC-353), tirar a pílula da pessoa É a
             volta às faixas. `situacao` não vira pílula — os chips de
             contagem ao lado já acendem quando ela liga. --}}
        @if ($recortes)
            <span class="shrink-0 font-mono text-[10px] uppercase tracking-caps text-ink-faint">Recorte</span>
            @foreach ($recortes as $recorte)
                {{-- `valor` é o que SOBRA do campo depois do ✕ — a prioridade
                     aceita várias ao mesmo tempo, e a pílula de uma não pode
                     levar as outras junto. Nos campos de valor único ele não
                     vem, e o clique tira o parâmetro inteiro como sempre. --}}
                <a href="{{ request()->fullUrlWithQuery([$recorte['parametro'] => $recorte['valor'] ?? null]) }}"
                   data-tirar-filtro="{{ $recorte['parametro'] }}"
                   title="Tirar este filtro do recorte"
                   class="shrink-0 h-[26px] px-2.5 rounded-badge border border-line flex items-center gap-1.5
                          text-[12px] text-ink-dim hover:text-ink transition">
                    <span class="h-3 w-3"><x-nav-icon name="x-mark" :peso="1.8" /></span>
                    {{ $recorte['rotulo'] }}
                </a>
            @endforeach
        @endif

        {{-- A porta de volta para a grade, SEM tirar o filtro (AC-256): trocar o
             layout de alguém e não deixar como voltar é decidir por ela. Só
             aparece quando a troca aconteceu — link para o estado em que já se
             está é ruído. --}}
        @if ($comoTabela ?? false)
            <a href="{{ request()->fullUrlWithQuery(['vista' => 'quadro']) }}"
               data-voltar-ao-quadro
               title="Ver como quadro, mantendo o filtro"
               class="shrink-0 h-[26px] px-2.5 rounded-badge border border-line flex items-center gap-1.5
                      text-[12px] text-ink-dim hover:text-ink transition">
                <span class="h-3 w-3"><x-nav-icon name="view-grid" :peso="1.8" /></span>
                ver como quadro
            </a>
        @endif

        <div class="shrink-0 flex items-center gap-2" data-pedaco="chips-do-quadro">
            @include('tarefas._chips', ['chips' => $chips])
        </div>
    </div>

    {{--
        A porta da tela cheia.

        FORA do contêiner rolável dos chips, e não dentro: aquele bloco tem
        `overflow-x-auto`, e num recorte com três pílulas ligadas a saída da
        tela cheia rolaria para fora da vista — quem entrou no modo ficaria sem
        o caminho de volta à mão. Aqui ela é irmã dele e não cede largura.

        Fica no cabeçalho DO QUADRO porque é o único cabeçalho que sobrevive ao
        modo: no da página ela desapareceria junto com o que esconde.
    --}}
    <button type="button" @click="alternarTelaCheia()"
            :title="telaCheia ? 'Sair da tela cheia · Esc' : 'Expandir o quadro para a tela inteira'"
            :aria-label="telaCheia ? 'Sair da tela cheia' : 'Expandir o quadro para a tela inteira'"
            :aria-pressed="telaCheia.toString()"
            class="shrink-0 ml-2 h-[26px] w-[26px] rounded-badge border border-btn-line flex items-center justify-center
                   text-ink-mute transition hover:text-brand hover:border-brand">
        <span class="h-[13px] w-[13px]" x-show="! telaCheia"><x-nav-icon name="expandir" :peso="1.8" /></span>
        <span class="h-[13px] w-[13px]" x-show="telaCheia" x-cloak><x-nav-icon name="encolher" :peso="1.8" /></span>
    </button>
</div>

@php
    // Sem raias o quadro é uma faixa só, que ocupa a altura toda.
    // Com raias ele empilha, e a rolagem passa a ser vertical.
    // `agrupado`, e não o modo: com o filtro fixando a dimensão da própria
    // raia (AC-353) o modo continua escolhido, mas a faixa é uma só — e o
    // que se desenha é o quadro plano.
    $comRaias = $raias['agrupado'];
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
            <span class="ml-1 font-sans tabular text-[11px]"
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
{{--
    Raia COM filtro não é grade — é tabela agrupada (US-070).

    A grade continua sendo a resposta certa para a raia SEM filtro: ali a segunda
    dimensão se paga, porque é o retrato do time inteiro. Com filtro ela cobra o
    layout cheio para mostrar células vazias, e é isso que a tabela desfaz.

    A troca é aqui, e não dentro do contêiner de rolagem, porque a tabela tem a
    própria rolagem e os próprios alvos: herdar o `data-quadro` faria o arrasto
    horizontal e o `medirBordas()` medirem uma tela que não existe mais.
--}}
@if ($comRaias && ($comoTabela ?? false))
    @include('tarefas._tabela-raias', ['raias' => $raias, 'filtros' => $filtros])
@else

<div x-ref="quadro" data-quadro data-rolagem="quadro"
     @scroll="medirBordas()" @resize.window="medirBordas()" x-init="medirBordas()"
     :class="'etapa-' + etapaMobile"
     {{--
         O respiro DE CIMA sai daqui quando há raias, e vai para dentro da barra
         fixa. Não é preciosismo: é o que decide se sobra uma fresta.

         `position: sticky` não sobe além do CONTENT BOX do pai, e o content box
         começa depois do `padding-top`. Com `p-3.5` no contêiner, o cabeçalho
         grudava a 14px do topo — e naqueles 14px o conteúdo continuava passando
         à vista: o card sumia por baixo da barra e REAPARECIA acima dela, na
         fresta. Fundo opaco não resolve, porque a fresta está FORA da barra.

         Sem `padding-top`, o content box começa na borda, a barra gruda no topo
         de verdade, e o respiro volta como `pt-3.5` DENTRO dela — pintado com o
         fundo dela, portanto tapando o que passa. Em repouso a tela fica
         idêntica; o que muda é que a fresta deixa de existir.

         Sem raias não há barra fixa para carregar o respiro, então ele fica aqui
         mesmo.
     --}}
     class="relative flex-1 min-h-0 flex flex-col gap-3 overflow-auto px-3.5 pb-3.5 {{ $comRaias ? '' : 'pt-3.5' }}">

    {{--
        Em raias, o cabeçalho das etapas fica FIXO no topo: as faixas
        empilham e a rolagem vira vertical, então sem isto a pessoa
        perde de vista em que coluna está olhando na terceira raia.
        O espaçador da direita mantém o alinhamento com as duas
        faixas de solto, que existem em cada linha de raia.
    --}}
    @if ($comRaias)
        {{--
            O fundo é o VÉU SOBRE A BASE, e não `bg-board` sozinho.

            `--board` é um véu translúcido no tema escuro (`rgba(0,0,0,0.28)`) e
            uma cor sólida no claro. Como fundo do quadro isso é certo — ele
            recua sobre o `canvas` da página. Como fundo de uma barra FIXA, não:
            no tema escuro os cards continuavam visíveis atravessando o
            cabeçalho enquanto se rolava, porque 72% dele é buraco. No claro o
            defeito não aparecia, e foi assim que ele passou.

            As duas camadas reproduzem exatamente o que já está atrás da barra —
            o véu do quadro sobre o canvas do `<body>` —, então a cor não muda em
            tema nenhum; o que muda é que agora ela é opaca. Nenhum valor novo:
            são os mesmos dois tokens, empilhados na ordem em que já estavam.
        --}}
        {{--
            A borda inferior é o que faz a barra ser LIDA como camada.

            Opaca ela já esconde o que passa por baixo — mas esconder sem marcar
            onde some faz o card parecer cortado no meio por nada, e não deslizar
            para trás de alguma coisa. É a diferença entre uma guilhotina e um
            plano acima do outro.

            Regra do próprio sistema, não invenção: toda barra fixa leva borda
            inferior de 1px — o Topbar (design/README §Topbar) e o cabeçalho
            deste mesmo quadro, logo acima, já fazem assim. Esta era a única
            fixa sem ela.
        --}}
        {{-- O VÉU VAI NUM GRADIENTE, e não solto — sem isso o CSS é inválido.

             No atalho `background`, só a ÚLTIMA camada pode ser uma cor; as
             demais têm de ser imagem. `background: var(--board), rgb(...)`
             resolve para duas cores, o navegador descarta a declaração INTEIRA
             e a barra fica sem fundo nenhum — transparente de verdade, pior que
             o véu de 28% que havia antes. `linear-gradient` de uma cor para ela
             mesma é uma imagem, e pinta exatamente o mesmo véu.

             Isso passou por um teste verde: ele conferia se a string estava no
             HTML, e ela estava. Marcação não prova CSS válido. --}}
        <div class="sticky top-0 z-10 shrink-0 flex gap-[10px] pt-3.5 pb-1 border-b border-line"
             style="background: linear-gradient(var(--board), var(--board)), rgb(var(--canvas))">
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
            {{-- `items-baseline`, não `items-center`: o link e o selo são
                 texto MENOR ao lado do nome, e centrar caixas de alturas
                 diferentes deixa o texto pequeno flutuando acima da linha do
                 nome — lia como sobrescrito. Texto ao lado de texto alinha
                 pela linha de base. --}}
            <header class="shrink-0 flex items-baseline gap-2 pt-1">
                <h3 class="font-display text-[13.5px] font-semibold text-ink">{{ $faixa['titulo'] }}</h3>

                {{-- A faixa é uma PORTA, não só um título (AC-354): quem
                     agrupou por pessoa quer, no passo seguinte, olhar UMA
                     pessoa — e sem este link o caminho era descobrir que o
                     select de filtro faz isso. Link, e não botão, pelo mesmo
                     motivo do controle de raias: cada recorte é um endereço.
                     O estilo é o do "Limpar recorte", que já é o par dele. --}}
                <a href="{{ request()->fullUrlWithQuery([$raias['modo'] => $faixa['filtro']]) }}"
                   data-ver-so-a-faixa
                   title="Aplicar o filtro e ver o quadro só com estas tarefas"
                   class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint hover:text-brand transition">
                    ver só estas
                </a>

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

@endif

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

{{--
    A legenda da esteira, atrás do ícone de dúvida do cabeçalho.

    Mesma decisão dos atalhos: orientação fixa cobraria espaço de todo mundo o
    tempo todo para servir a quem chegou ontem. Sob demanda, cabe dizer o que o
    quadro só mostra uma regra por vez: a ordem das paradas, a volta dos
    portões, o bloqueio que não é coluna e o desvio da operacional.
--}}
@php
    /**
     * Rótulo, cor e portão saem das MESMAS constantes que pintam as colunas
     * (`rotuloDaEtapa`, `corDaEtapa`, `PORTAO_DA_ETAPA`): legenda copiada é
     * legenda que diverge do quadro que ela explica. Só a nota das etapas sem
     * portão nasce aqui, com o texto do handoff (README §16.1).
     */
    $esteira = [
        ['chave' => 'aberta', 'nota' => 'fila de triagem'],
        ['chave' => 'backlog', 'nota' => 'priorizado, sem ninguém tocando'],
        ['chave' => 'em_desenvolvimento', 'nota' => 'a bancada de quem executa'],
        ['chave' => 'em_revisao', 'nota' => \App\Models\Tarefa::PORTAO_DA_ETAPA['em_revisao']],
        ['chave' => 'em_staging', 'nota' => \App\Models\Tarefa::PORTAO_DA_ETAPA['em_staging']],
        ['chave' => 'em_producao', 'nota' => \App\Models\Tarefa::PORTAO_DA_ETAPA['em_producao']],
        ['chave' => 'concluida', 'nota' => 'no ar e conferido · a versão diz desde quando'],
    ];
@endphp
<div x-show="fluxoAberto" x-cloak x-transition.opacity.duration.150ms
     @click="fluxoAberto = false"
     class="absolute inset-0 z-30 flex items-center justify-center p-4"
     style="background: rgb(var(--canvas) / 0.75)">
    <div @click.stop class="w-[420px] max-w-full max-h-full overflow-y-auto rounded-panel border border-line bg-panel p-4 shadow-xl">
        <div class="flex items-center gap-2">
            <h3 class="font-display text-[14.5px] font-semibold text-ink">Como a esteira funciona</h3>
            <button type="button" @click="fluxoAberto = false" aria-label="Fechar"
                    class="ml-auto h-6 w-6 rounded-control text-ink-faint hover:text-ink transition">✕</button>
        </div>

        <div class="mt-3">
            @foreach ($esteira as $parada)
                <div class="flex items-center gap-2">
                    <span class="h-[7px] w-[7px] shrink-0 rounded-full"
                          style="background: rgb(var(--{{ \App\Models\Tarefa::corDaEtapa($parada['chave']) }}))"></span>
                    <span class="shrink-0 text-[12.5px] font-semibold text-ink">
                        {{ \App\Models\Tarefa::rotuloDaEtapa($parada['chave']) }}
                    </span>
                    <span class="min-w-0 truncate font-mono text-[9.5px] uppercase tracking-[0.1em] text-ink-faint"
                          title="{{ $parada['nota'] }}">{{ $parada['nota'] }}</span>
                </div>
                {{-- O fio entre as paradas: 3px de recuo centralizam a linha de
                     1px sob o ponto de 7px da parada de cima. --}}
                @unless ($loop->last)
                    <div class="ml-[3px] h-2.5 border-l border-line" aria-hidden="true"></div>
                @endunless
            @endforeach
        </div>

        {{-- O que anda por fora da linha. Cada ícone e cor é o que o card já
             usa para a mesma notícia — a legenda ensina o símbolo, não cria um. --}}
        <div class="mt-3 pt-3 border-t border-line space-y-1.5">
            @foreach ([
                ['icone' => 'arrow-uturn-left', 'cor' => 'warn',
                 'frase' => 'Reprovar num portão devolve a tarefa para Em andamento, com o motivo — a tarja no card diz de onde ela voltou.'],
                ['icone' => 'cadeado-fechado', 'cor' => 'warn',
                 'frase' => 'Bloqueada é marca, não coluna: a tarefa fica na etapa em que está e sai da conta de WIP.'],
                ['icone' => 'duvida', 'cor' => 'brand-text',
                 'frase' => 'Perguntar passa a bola sem mover o card — na 3ª rodada o quadro acende o alerta de conversa empacada.'],
            ] as $marca)
                <div class="flex items-start gap-2.5">
                    <span class="mt-px h-3.5 w-3.5 shrink-0" style="color: rgb(var(--{{ $marca['cor'] }}))">
                        <x-nav-icon :name="$marca['icone']" :peso="1.8" />
                    </span>
                    <p class="text-[12px] leading-[1.4] text-ink-mute">{{ $marca['frase'] }}</p>
                </div>
            @endforeach

            <div class="flex items-start gap-2.5">
                <span class="shrink-0 px-1.5 py-0.5 rounded-badge bg-chip text-ink-mute
                             font-mono text-[9.5px] font-semibold uppercase tracking-[0.08em]">Oper.</span>
                <p class="text-[12px] leading-[1.4] text-ink-mute">
                    A tarefa operacional não tem PR nem staging: de Em andamento ela vai direto para Concluída.
                </p>
            </div>

            <div class="flex items-start gap-2.5">
                <span class="mt-px h-3.5 w-3.5 shrink-0 text-ink-faint"><x-nav-icon name="x-mark" :peso="1.8" /></span>
                <p class="text-[12px] leading-[1.4] text-ink-mute">
                    Cancelar encerra de qualquer etapa, sempre com o motivo. As encerradas vivem na aba Histórico.
                </p>
            </div>
        </div>
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

{{-- O molde do pedido de motivo: um para o quadro inteiro, clonado para dentro
     do card que o pediu. Aqui, e não no `index`, porque é este bloco que volta
     inteiro quando o quadro é redesenhado — no `index` o molde sobreviveria à
     troca e o clone dentro do card não, e as duas metades se separariam. --}}
@include('tarefas._painel-motivo')
