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
@endphp

<section class="flex flex-col min-h-0 rounded-control bg-panel border border-line overflow-hidden transition-opacity"
         {{--
             A cor da etapa vive AQUI, na coluna, como no Funil de Vendas — e
             não na borda do card: essa continua sendo o canal do aviso de
             tarefa esquecida (AC-093, AC-127).

             `flex: 1 1 276px` no lugar de largura fixa: com cinco colunas numa
             tela larga sobrava uma faixa vazia à direita (AC-132). Assim elas
             dividem o espaço quando ele existe, e o `min-width` segura a
             largura de leitura — apertando a tela, o quadro volta a rolar na
             horizontal em vez de espremer o card.
         --}}
         {{-- A faixa de cor só onde o cabeçalho está: em raias ela se repetiria
              uma vez por faixa, competindo com o cabeçalho fixo do topo, que é
              quem nomeia a etapa. Cor repetida sem rótulo vira listra. --}}
         style="flex: 1 1 276px; min-width: 276px;
                border-top: {{ $comCabecalho ? '3px solid rgb(var(--'.$etapa['cor'].'))' : '1px solid var(--line)' }}"
         data-status="{{ $etapa['chave'] }}"
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
         :class="{
             'ring-1 ring-brand': sobre === '{{ $etapa['chave'] }}' || pendente?.destino === '{{ $etapa['chave'] }}',
             'opacity-25': ! aceita('{{ $etapa['chave'] }}'),
         }">

    {{-- Em raias o cabeçalho é um só, fixo no topo do quadro: repetido faixa a
         faixa, ele viraria a maior parte da tela. --}}
    @if ($comCabecalho)
        @include('tarefas._coluna-cabecalho', ['etapa' => $etapa])
    @endif

    <div data-cards="{{ $alvo }}"
         class="flex-1 min-h-0 overflow-y-auto overscroll-y-contain p-2 space-y-2">
        @forelse ($cards as $tarefa)
            @php
                /**
                 * Os destinos são do CARD, não do status: o fluxo depende do
                 * tipo da tarefa.
                 *
                 * E quem não pode mover ESTA tarefa não recebe destino nenhum —
                 * o card não arrasta e não mostra o chevron. Oferecer e recusar
                 * depois é o vício que o quadro perdeu nas regras de fluxo; não
                 * faria sentido reintroduzi-lo na autorização. O porquê fica no
                 * `title`, e a rota continua recusando com a frase.
                 */
                $impedimento = $tarefa->motivoParaNaoMover(auth()->user());
                $transicoes = $impedimento ? [] : \App\Services\FluxoTarefaService::transicoesDe($tarefa);
            @endphp

            <div x-data="{ menuAberto: false, destino: '{{ $transicoes[0] ?? '' }}' }"
                 draggable="{{ $impedimento ? 'false' : 'true' }}"
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
                      o quadro sabe quais colunas apagar durante o arrasto. --}}
                 @dragstart="pegar(
                     {{ $tarefa->id }},
                     {{ Illuminate\Support\Js::from($transicoes) }},
                     '{{ $tarefa->tipo }}',
                     {{ $tarefa->estaBloqueada() ? 'true' : 'false' }},
                     '{{ $tarefa->status }}'
                 )"
                 @dragend="largar()"
                 {{-- Soltar um card SOBRE outro da mesma coluna reordena; de
                      coluna diferente, o evento segue subindo e a coluna
                      resolve como movimento de etapa. --}}
                 @dragover="permitirSobreCard($event, '{{ $etapa['chave'] }}')"
                 @drop="soltarSobreCard($event, $el, '{{ $etapa['chave'] }}', '{{ $alvo }}')"
                 {{-- O card faz as duas coisas: abre no clique e arrasta. Sem o
                      limiar, um arrasto curto — o começo de qualquer arrasto, na
                      prática — terminava com o modal aberto por cima do gesto. --}}
                 @pointerdown="marcarInicioDoClique($event)"
                 @click="if (foiClique($event)) $dispatch('open-modal', 'editar-tarefa-{{ $tarefa->id }}')"
                 class="rounded-ctl {{ $impedimento ? 'cursor-pointer' : 'cursor-grab active:cursor-grabbing' }}"
                 :class="{
                     'opacity-50': arrastando === {{ $tarefa->id }},
                     'ring-1 ring-brand': selecionado === {{ $tarefa->id }},
                 }">
                @include('tarefas._card', ['tarefa' => $tarefa, 'transicoes' => $transicoes])
            </div>
        @empty
            <div class="rounded-ctl border border-dashed border-line px-2 text-center flex items-center justify-center"
                 style="height: 84px">
                <p class="text-[11.5px] text-ink-faint">Nenhuma tarefa aqui</p>
            </div>
        @endforelse
    </div>

    {{--
        Criação rápida, no pé da coluna.

        Abrir tarefa pelo botão do topo custa: modal, cinco campos e um Salvar.
        Metade do que se abre no dia a dia é uma frase — "conferir o boleto da
        Orbe" — e para essa frase o formulário completo é uma cerimônia que faz
        a pessoa deixar para depois, ou anotar em outro lugar.

        Só em Aberta: tarefa sem responsável nasce lá (`Tarefa::booted`), e a
        criação rápida não tem onde perguntar por um. No pé do Backlog, ela
        entregaria um card que aparece na coluna ao lado — um controle que
        promete um lugar e entrega outro é a definição de quadro que mente.

        E só na faixa sem raia: com o quadro agrupado, um campo por faixa
        prometeria criar a tarefa DENTRO daquela raia, o que ele não faz.
    --}}
    @if ($etapa['chave'] === 'aberta' && $faixa === 'todas')
        <form method="POST" action="{{ route('tarefas.store') }}"
              class="shrink-0 px-2 pb-2 pt-1 border-t border-rule">
            @csrf
            <input type="text" name="titulo" maxlength="255" required data-criacao-rapida
                   placeholder="+ nova tarefa · Enter para criar"
                   class="w-full min-h-[34px] px-2 py-1 text-[12.5px] text-ink placeholder-ink-faint
                          !bg-transparent !rounded-control !border !border-transparent
                          hover:!border-line focus:!border-brand transition">
        </form>
    @endif
</section>
