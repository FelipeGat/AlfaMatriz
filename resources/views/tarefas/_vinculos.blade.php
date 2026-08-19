@php
    /**
     * As tarefas vinculadas a esta, dentro do modal de edição.
     *
     * Nenhum `<form>` aqui, pelo mesmo motivo do checklist: este bloco vive
     * DENTRO do formulário da tarefa, e formulário aninhado é HTML inválido —
     * o navegador descarta o interno e a falha é silenciosa. Os envios ficam em
     * `_vinculos-envios`, e os campos daqui os alcançam pelo atributo `form`.
     *
     * O vínculo é simétrico: o que se lê aqui é o mesmo que a OUTRA tarefa lê
     * sobre esta. Não há "pai" nem "principal" para destacar.
     */
    $sugestoes = \App\Models\Tarefa::sugestoesDeVinculo($tarefa);
@endphp

<div class="pt-4 border-t border-rule">
    <div class="flex items-center gap-2">
        <h4 class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Tarefas vinculadas</h4>

        {{-- A contagem só aparece quando há o que contar, como o "3/5" do
             checklist: "0" anunciaria como ausência algo que é só o normal. --}}
        @if ($tarefa->vinculadas->isNotEmpty())
            <span class="font-sans tabular text-[10.5px] text-ink-mute">{{ $tarefa->vinculadas->count() }}</span>
        @endif
    </div>

    <ul class="mt-2 space-y-1">
        @foreach ($tarefa->vinculadas as $vinculada)
            @php
                $corDaVinculada = \App\Models\Tarefa::corDaEtapa($vinculada->status);
            @endphp

            <li class="group flex items-center gap-2 rounded-control px-1 py-1 hover:bg-chip transition">
                {{-- O ponto na cor da etapa é o mesmo do cabeçalho do modal: em
                     que pé está a tarefa irmã é metade do que se quer saber ao
                     olhar para a lista, e obrigar a abri-la para descobrir
                     faria o vínculo custar uma viagem por linha. --}}
                <span class="h-[7px] w-[7px] shrink-0 rounded-full"
                      style="background: rgb(var(--{{ $corDaVinculada }}))"
                      title="{{ \App\Models\Tarefa::STATUS[$vinculada->status] ?? $vinculada->status }}"></span>

                {{--
                    A linha inteira abre a tarefa vinculada, e não só o número:
                    o alvo de 12px de largura é o que faz um atalho ser clicado
                    errado. `abrir-tarefa` é o mesmo evento do clique no card e
                    do Enter do teclado — ele troca o bloco de modais inteiro,
                    então a tarefa irmã ABRE NO LUGAR desta, que é o que
                    "navegar até ela" significa. Voltar é o vínculo de lá, que
                    aponta de volta para cá porque o par existe nos dois
                    sentidos.
                --}}
                <button type="button" x-data
                        @click="$dispatch('abrir-tarefa', {{ $vinculada->id }})"
                        title="Abrir a tarefa {{ $vinculada->codigo() }}"
                        class="min-w-0 flex-1 flex items-center gap-2 text-left">
                    <span class="shrink-0 font-mono text-[11px] text-ink-mute group-hover:text-brand transition">
                        {{ $vinculada->codigo() }}
                    </span>
                    {{-- `truncate` e não `nowrap` solto: sem o corte, o título
                         longo pinta por cima do botão de desvincular em vez de
                         parar antes dele (armadilha 1). --}}
                    <span class="min-w-0 flex-1 truncate text-[12.5px] text-ink">{{ $vinculada->titulo }}</span>
                </button>

                {{-- Só no hover da linha, como o remover do checklist: uma
                     coluna de ✕ acesos disputa a leitura com a lista que se
                     veio ler. --}}
                <button type="submit" form="desvincular-{{ $tarefa->id }}-{{ $vinculada->id }}"
                        title="Desvincular a tarefa {{ $vinculada->codigo() }}"
                        aria-label="Desvincular a tarefa {{ $vinculada->codigo() }}"
                        class="shrink-0 h-5 w-5 rounded-control text-ink-faint opacity-0 group-hover:opacity-100
                               focus:opacity-100 hover:text-crit transition">✕</button>
            </li>
        @endforeach
    </ul>

    {{--
        Um campo de número, e não um `<select>` de tarefas.

        O `<select>` teria de decidir quais tarefas caber nele, e todo recorte
        possível — só as do quadro, só as dos últimos 30 dias — deixa sem
        caminho quem quer apontar para a tarefa antiga que EXPLICA esta, que é
        metade dos vínculos que se fazem. O número é justamente o que o modal
        manda copiar ("o número abre a linha porque é o que se copia daqui").

        A `<list>` é sugestão em cima disso, e é HTML nativo: sem JavaScript ela
        continua filtrando enquanto se digita, e o campo continua aceitando
        qualquer número que ela não ofereça.
    --}}
    {{-- O `id` devolve o cursor a este campo depois do envio, como o do novo
         item do checklist: vincula-se em série ("esta, aquela e mais aquela"),
         e a lista é redesenhada pelo servidor a cada uma (ver `guardarFoco`, no
         `index`). --}}
    <input type="text" id="nova-vinculada-campo-{{ $tarefa->id }}"
           name="tarefa" form="vincular-{{ $tarefa->id }}" maxlength="255"
           list="sugestoes-de-vinculo-{{ $tarefa->id }}" autocomplete="off"
           placeholder="+ número da tarefa · Enter para vincular"
           class="mt-2 block w-full h-8 px-2.5 text-[12.5px] text-ink placeholder-ink-faint transition
                  bg-input border border-dashed !border-btn-line !rounded-ctl
                  focus:!border-brand">

    <datalist id="sugestoes-de-vinculo-{{ $tarefa->id }}">
        {{-- O rótulo inteiro vai no `value` porque é ele que o navegador
             escreve no campo ao escolher, e é por ele que ele filtra enquanto
             se digita — com o título fora dali, procurar "importador" não
             acharia nada. Quem lê o número de volta é `idDaTarefaDigitada`, no
             controle, que pega o primeiro número da frase. --}}
        @foreach ($sugestoes as $sugestao)
            <option value="{{ $sugestao->codigo() }} — {{ $sugestao->titulo }}"></option>
        @endforeach
    </datalist>

    {{-- A nota existe para não precisarem perguntar, como a do checklist. Sem
         ela, a primeira dúvida de quem usa é se vincular prende as duas — se
         concluir uma cobra a outra, se a irmã anda junto no quadro. --}}
    <p class="mt-1.5 px-1 text-[11px] leading-[1.45] text-ink-faint">
        Vincular não prende: as duas tarefas continuam com etapa, responsável e prazo próprios.
        O vínculo vale nos dois sentidos — esta tarefa também aparece na lista da outra.
    </p>
</div>
