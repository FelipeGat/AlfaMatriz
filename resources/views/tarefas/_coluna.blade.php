@php
    /**
     * Uma coluna do quadro: cabeçalho, cards e a criação rápida.
     *
     * Virou partial quando as raias chegaram — o mesmo desenho de coluna passou
     * a se repetir uma vez por faixa, e uma cópia por raia seria a garantia de
     * que uma delas ficaria para trás na primeira mudança.
     *
     * Espera: $etapa, $cards, $faixa (a chave da raia, para os alvos de solto
     * não se confundirem entre faixas) e $comCabecalho.
     */
    $alvo = $faixa.'::'.$etapa['chave'];

    // Um conjunto só define "há recorte", usado pelo texto de coluna vazia e
    // pelo botão Limpar. Separados, os dois se contradiziam. Prioridade é
    // lista, e lista vazia é o mesmo "nada pedido" do texto vazio.
    $temRecorte = collect($filtros ?? [])->contains(fn ($valor) => $valor !== '' && $valor !== []);
@endphp

<section class="flex flex-col min-h-0 rounded-control bg-panel border border-line overflow-hidden transition-opacity"
         {{--
             A cor da etapa vive AQUI, na coluna, como no Funil de Vendas — e
             não na borda do card: essa continua sendo o canal do aviso de
             tarefa esquecida (AC-093, AC-127).

             `flex: 1 1 272px` no lugar de largura fixa: com seis colunas numa
             tela larga sobrava uma faixa vazia à direita (AC-132). Assim elas
             dividem o espaço quando ele existe, e o `min-width` segura a
             largura de leitura — apertando a tela, o quadro volta a rolar na
             horizontal em vez de espremer o card. Os 272px são do protótipo, e
             o `min-width` junto é obrigatório: sem ele o flex encolhe a coluna
             abaixo da largura em que o card ainda se lê.
         --}}
         {{-- A faixa de cor só onde o cabeçalho está: em raias ela se repetiria
              uma vez por faixa, competindo com o cabeçalho fixo do topo, que é
              quem nomeia a etapa. Cor repetida sem rótulo vira listra. --}}
         :style="recolhidas.includes('{{ $etapa['chave'] }}')
             ? 'flex: 0 0 42px; min-width: 42px; border-top: {{ $comCabecalho ? '3px solid rgb(var(--'.$etapa['cor'].'))' : '1px solid var(--line)' }}'
             : 'flex: 1 1 272px; min-width: 272px; border-top: {{ $comCabecalho ? '3px solid rgb(var(--'.$etapa['cor'].'))' : '1px solid var(--line)' }}'"
         data-status="{{ $etapa['chave'] }}"
         {{-- A coluna anuncia a PRÓPRIA faixa quando o card entra nela: com
              raias ligadas a mesma etapa aparece uma vez por raia, e sem este
              aviso o quadro não tinha como saber que o solto estava
              acontecendo na faixa de outra pessoa. Vai pelo `dragenter`, e não
              por um argumento do `soltar`, porque o nome da faixa é nome de
              gente — não tem por que atravessar o Blade dentro de uma string
              de JavaScript. --}}
         @dragenter="faixaSobOPonteiro = @js($faixa)"
         @dragover.prevent="permitir('{{ $etapa['chave'] }}')"
         @dragleave="sobre = null"
         {{-- Quem decide se abre painel é o `pedeTexto`, e não uma lista fixa
              aqui: Em andamento pede texto vindo de um portão e não pede vindo
              do Backlog, e a coluna renderizada no servidor não tem como saber
              de onde o card que ainda vai ser arrastado veio. --}}
         @drop.prevent="soltar('{{ $etapa['chave'] }}', pedeTexto('{{ $etapa['chave'] }}'))"
         {{-- Enquanto o card está na mão, a coluna que não o aceita apaga. É o
              que faz a regra do fluxo virar coisa que se VÊ antes de soltar: o
              "transição inválida" deixa de ser a primeira notícia de que aquele
              caminho não existia. O realce SEGUE enquanto o painel de motivo
              está aberto — o card ainda não chegou, e a coluna apagando junto
              com o solto faria o painel parecer desligado do gesto. --}}
         {{-- A célula de OUTRA faixa apaga junto com as colunas fora do fluxo,
              e pelo mesmo motivo: soltar ali não move nada — a raia é o
              responsável (ou o sistema) da tarefa, e trocá-lo é campo do
              formulário, não gesto de arrastar. Antes ela acendia, aceitava o
              solto e não acontecia nada. --}}
         :class="{
             'ring-1 ring-brand': sobre === '{{ $etapa['chave'] }}' || pendente?.destino === '{{ $etapa['chave'] }}',
             'opacity-25': ! aceita('{{ $etapa['chave'] }}') || ! naFaixaDoArrasto(@js($faixa)),
         }">

    {{-- Em raias o cabeçalho é um só, fixo no topo do quadro: repetido faixa a
         faixa, ele viraria a maior parte da tela. --}}
    {{-- O alvo nomeado é o que deixa o contador da coluna voltar sozinho,
         sem o quadro inteiro atrás dele. `shrink-0` no invólucro porque a
         seção é um flex vertical e o cabeçalho não pode encolher. --}}
    @if ($comCabecalho)
        <div class="shrink-0" data-pedaco="etapa-{{ $etapa['chave'] }}">
            @include('tarefas._coluna-cabecalho', ['etapa' => $etapa])
        </div>
    @endif

    {{-- A coluna rola por dentro, e o quadro é redesenhado inteiro a cada ação
         parcial: sem a chave de rolagem, a lista voltaria ao topo e o card em
         que se estava mexendo sairia da vista. --}}
    {{--
         `overscroll-y-contain` SÓ onde a coluna é quem rola na vertical.

         Sem raias ele é certo: cada coluna rola por dentro, o quadro não rola
         na vertical, e conter evita que a PÁGINA role junto quando a lista da
         coluna acaba.

         Em raias é o oposto. Quem rola é o quadro inteiro, e cada coluna vira
         um contêiner de rolagem com a corrente cortada: com o cursor sobre um
         card — ou seja, na maior parte da tela — a roda do mouse morria ali e o
         quadro não andava. Rolar virava caçar um vão entre os cards.

         `overflow-y-auto` fica nos dois casos: sem ele a célula com muitos
         cards estouraria a altura da faixa em vez de rolar por dentro.
    --}}
    <div data-cards="{{ $alvo }}" data-rolagem="cards:{{ $alvo }}"
         x-show="! recolhidas.includes('{{ $etapa['chave'] }}')"
         class="flex-1 min-h-0 overflow-y-auto p-[10px] space-y-[10px] {{ $comCabecalho ? 'overscroll-y-contain' : '' }}">
        @forelse ($cards as $tarefa)
            @php
                /**
                 * Os destinos são do CARD e de QUEM OLHA: o fluxo depende do
                 * tipo da tarefa, e quem faz triagem recebe o quadro inteiro
                 * (US-079).
                 *
                 * E quem não pode mover ESTA tarefa não recebe destino nenhum —
                 * o card não arrasta e não mostra o chevron. Oferecer e recusar
                 * depois é o vício que o quadro perdeu nas regras de fluxo; não
                 * faria sentido reintroduzi-lo na autorização. O porquê fica no
                 * `title`, e a rota continua recusando com a frase.
                 */
                $impedimento = $tarefa->motivoParaNaoMover(auth()->user());
                $transicoes = $tarefa->destinosPara(auth()->user());
            @endphp

            <div x-data="{ menuAberto: false, destino: '{{ $transicoes[0] ?? '' }}' }"
                 {{-- O painel de parede olha e não arrasta. --}}
                 draggable="{{ $impedimento || ! auth()->user()?->podeMexerNoQuadro() ? 'false' : 'true' }}"
                 @if ($impedimento) title="{{ $impedimento }}" @endif
                 data-tarefa="{{ $tarefa->id }}"
                 {{-- Os mesmos dados do arraste, legíveis pelo teclado: quem
                      navega com as setas precisa saber para onde este card pode
                      ir sem ter passado por um `dragstart`. Atributo entre
                      aspas SIMPLES porque o JSON usa as duplas. --}}
                 data-destinos='@json($transicoes)'
                 data-tipo="{{ $tarefa->tipo }}"
                 data-bloqueada="{{ $tarefa->estaBloqueada() ? '1' : '' }}"
                 {{-- O card entrega os próprios destinos ao pegar: é assim que
                      o quadro sabe quais colunas apagar durante o arrasto.

                      NUMA LINHA SÓ, e isso não é descuido de formatação: o
                      atributo se repete uma vez por card, e quebrado em seis
                      linhas de argumento ele mandava ~130 bytes de indentação
                      por card — 15 KB num quadro de 120, contra o teto de peso
                      do AC-240 (`QuadroLeveTest`). Reindentar aqui é gastar
                      esse orçamento sem trocar nada por ele. --}}
                 @dragstart="pegar( $el, {{ $tarefa->id }}, {{ Illuminate\Support\Js::from($transicoes) }}, '{{ $tarefa->tipo }}', {{ $tarefa->estaBloqueada() ? 'true' : 'false' }}, '{{ $tarefa->status }}' )"
                 @dragend="largar()"
                 {{-- Arrastar SOBRE um vizinho abre o vão ao vivo — o card na
                      mão muda de lugar na lista, com os outros deslizando. Vale
                      entre COLUNAS também: quem escolhe o lugar na fila de
                      destino não deveria precisar mover duas vezes.

                      A etapa e o endereço da lista NÃO viajam aqui: os dois
                      saem do `data-cards` da lista sob o ponteiro, e repetidos
                      em cada card eles custavam ~10 KB num quadro de 120 —
                      o mesmo fato escrito três vezes por card. --}}
                 @dragover="permitirSobreCard($event, $el, {{ $tarefa->id }})"
                 @drop="soltarSobreCard($event, $el)"
                 {{-- O card faz as duas coisas: abre no clique e arrasta. Sem o
                      limiar, um arrasto curto — o começo de qualquer arrasto, na
                      prática — terminava com o modal aberto por cima do gesto. --}}
                 @pointerdown="marcarInicioDoClique($event)"
                 {{-- `abrir-tarefa` e não `open-modal`: o modal desta tarefa
                      não está na página — ele é buscado no clique. Ver
                      `abrirTarefa` no script do quadro. --}}
                 @click="if (foiClique($event)) $dispatch('abrir-tarefa', {{ $tarefa->id }})"
                 class="rounded-ctl {{ $impedimento ? 'cursor-pointer' : 'cursor-grab active:cursor-grabbing' }}"
                 {{-- Na mão o card SOME da lista (classe `invisible`, que o
                      `pegar` aplica um quadro depois do dragstart, para o
                      navegador fotografar o fantasma antes): o vão do pouso é
                      um espaço vazio de verdade. A cópia meio apagada que
                      ficava aqui lia como um segundo card — "como se não
                      estivesse movendo para um espaço vazio" (dono,
                      16/08/2026). --}}
                 :class="{
                     'ring-1 ring-brand': selecionado === {{ $tarefa->id }},
                 }">
                {{-- Alvo nomeado: marcar um item do checklist muda o "3/5" DESTE
                     card e mais nada. Redesenhar o quadro para isso mandava
                     906 KB; o card sozinho tem ~15 KB. --}}
                <div class="contents" data-pedaco="card-{{ $tarefa->id }}">
                    @include('tarefas._card', ['tarefa' => $tarefa, 'transicoes' => $transicoes])
                </div>
            </div>
        @empty
            <div class="rounded-ctl border border-dashed border-line px-2 text-center flex items-center justify-center"
                 style="height: 84px">
                {{-- Uma frase por etapa: coluna vazia é informação, e o que ela
                     informa muda conforme a etapa. "Nenhuma tarefa aqui" seis
                     vezes desperdiça as seis notícias. --}}
                {{-- Sob recorte a frase muda: "Nada priorizado" com um filtro
                     ligado seria mentira — pode haver muito priorizado, só não
                     no que se pediu para ver. --}}
                <p class="text-[11.5px] text-ink-faint text-center px-2">
                    {{ $temRecorte
                        ? 'Nada no recorte'
                        : (\App\Models\Tarefa::VAZIO_DA_ETAPA[$etapa['chave']] ?? 'Nenhuma tarefa aqui') }}
                </p>
            </div>
        @endforelse
    </div>

    {{--
        Criação rápida, no pé da coluna.

        Abrir tarefa pelo botão do topo custa: modal, cinco campos e um Salvar.
        Metade do que se abre no dia a dia é uma frase — "conferir o boleto da
        Orbe" — e para essa frase o formulário completo é uma cerimônia que faz
        a pessoa deixar para depois, ou anotar em outro lugar.

        Em Aberta e no Backlog. O Backlog só entrou depois de o formulário
        passar a DECLARAR a coluna de destino: `Tarefa::booted` decide a etapa
        pelo responsável, e sem o campo o card criado no pé do Backlog nascia em
        Aberta — um controle que promete um lugar e entrega outro.

        E só na faixa sem raia: com o quadro agrupado, um campo por faixa
        prometeria criar a tarefa DENTRO daquela raia, o que ele não faz.
    --}}
    @if (in_array($etapa['chave'], ['aberta', 'backlog'], true) && $faixa === 'todas'
        && auth()->user()?->canPermissao('tarefas', 'incluir'))
        <form method="POST" action="{{ route('tarefas.store') }}" data-parcial
              x-show="! recolhidas.includes('{{ $etapa['chave'] }}')"
              class="shrink-0 px-[10px] py-2 border-t border-rule">
            @csrf
            <input type="hidden" name="status" value="{{ $etapa['chave'] }}">
            <input type="text" name="titulo" maxlength="255" required data-criacao-rapida
                   placeholder="+ nova tarefa · Enter para criar"
                   class="w-full min-h-[34px] px-2 py-1 text-[12.5px] text-ink placeholder-ink-faint
                          !bg-transparent !rounded-control !border !border-transparent
                          hover:!border-line focus:!border-brand transition">
        </form>
    @endif
</section>
