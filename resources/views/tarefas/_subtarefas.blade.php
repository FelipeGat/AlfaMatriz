@php
    /**
     * As subtarefas desta tarefa, dentro do modal de edição.
     *
     * Nenhum `<form>` nem campo aqui, ao contrário do checklist e dos vínculos:
     * criar subtarefa abre OUTRO formulário, buscado do servidor com a mãe
     * amarrada. O botão só dispara o evento — e por isso esta seção escapa do
     * formulário aninhado, que é HTML inválido e falha em silêncio.
     *
     * A diferença para os vínculos é o que a nota do rodapé diz: vínculo não
     * prende, subtarefa prende. É a mesma lista de tarefas irmãs na forma, e o
     * oposto no efeito — e por isso as duas seções não viraram uma.
     */
    $progresso = $tarefa->progressoDasSubtarefas();
    $bloqueada = $tarefa->motivoParaNaoEncerrar();
@endphp

<div class="pt-4 border-t border-rule-strong">
    <div class="flex items-center gap-2">
        <h4 class="font-mono text-[11.5px] font-semibold uppercase tracking-caps-wide text-ink">Subtarefas</h4>

        {{-- O placar só aparece quando há o que contar, como o "3/5" do
             checklist: "0/0" anunciaria como ausência algo que é só o normal.
             Verde quando fecha, porque aí ele deixou de ser cobrança. --}}
        @if ($progresso)
            <span class="font-sans tabular text-[10.5px]"
                  style="color: {{ $progresso['feitas'] === $progresso['total']
                      ? 'rgb(var(--good))' : 'rgb(var(--ink-mute))' }}">
                {{ $progresso['feitas'] }}/{{ $progresso['total'] }}
            </span>
        @endif
    </div>

    <ul class="mt-2 space-y-1">
        @foreach ($tarefa->subtarefas as $filha)
            @php
                $encerrada = in_array($filha->status, \App\Models\Tarefa::STATUS_TERMINAIS, true);
            @endphp

            <li class="group flex items-center gap-2 rounded-control px-1 py-1 hover:bg-chip transition">
                {{-- O ponto na cor da etapa, como na lista de vínculos: em que
                     pé está cada filha é metade do que se quer saber ao olhar
                     para a lista — e é a outra metade que decide se a mãe
                     encerra hoje. --}}
                <span class="h-[7px] w-[7px] shrink-0 rounded-full"
                      style="background: rgb(var(--{{ \App\Models\Tarefa::corDaEtapa($filha->status) }}))"
                      title="{{ \App\Models\Tarefa::rotuloDaEtapa($filha->status) }}"></span>

                {{-- A linha inteira abre a filha, como na lista de vínculos: o
                     alvo de 12px do número é o que faz um atalho ser clicado
                     errado. `abrir-tarefa` troca o bloco de modais, então a
                     filha abre NO LUGAR da mãe — e o "de #120" no cabeçalho
                     dela é o caminho de volta. --}}
                <button type="button" x-data
                        @click="$dispatch('abrir-tarefa', {{ $filha->id }})"
                        title="Abrir a subtarefa {{ $filha->codigo() }}"
                        class="min-w-0 flex-1 flex items-center gap-2 text-left">
                    <span class="shrink-0 font-mono text-[11px] text-ink-mute group-hover:text-brand transition">
                        {{ $filha->codigo() }}
                    </span>

                    {{-- `truncate` e não `nowrap` solto: sem o corte, o título
                         longo pinta por cima da etapa em vez de parar antes
                         dela (armadilha 1). --}}
                    <span class="min-w-0 flex-1 truncate text-[12.5px] {{ $encerrada ? 'text-ink-mute line-through' : 'text-ink' }}">
                        {{ $filha->titulo }}
                    </span>

                    {{-- A etapa por extenso, e não só o ponto: é ela que diz
                         quanto falta, e passar o mouse em doze pontinhos para
                         descobrir isso é a viagem que a lista existe para
                         poupar. Encerrada fica apagada — ela já não é o que
                         prende a mãe. --}}
                    <span class="shrink-0 font-mono text-[9.5px] uppercase tracking-[0.06em] {{ $encerrada ? 'text-ink-faint' : 'text-ink-mute' }}">
                        {{ \App\Models\Tarefa::rotuloDaEtapa($filha->status) }}
                    </span>
                </button>
            </li>
        @endforeach
    </ul>

    @if ($tarefa->podeReceberSubtarefa())
        {{--
            UMA porta, e é o formulário inteiro.

            Houve um campo de uma linha aqui, para despejar títulos em série. Ele
            saiu no primeiro dia de uso, por duas razões que se somam: criar só o
            título e ter de reabrir para escrever e colar o print é o trabalho em
            dois tempos que esta seção existe para evitar — e um campo ao lado de
            um botão que fazem a MESMA coisa custa uma decisão a cada uso, num
            modal que já carrega checklist, vínculos, anexos e conversa.

            O botão em largura inteira, e não encolhido num canto: é a única
            ação da seção, e é ela que a lista acima pede depois de ser lida.
        --}}
        <button type="button" x-data
                @click="$dispatch('nova-subtarefa', {{ $tarefa->id }})"
                title="Abrir o formulário: título, resumo, prioridade, responsável, checklist e anexos"
                class="mt-2 w-full h-8 rounded-ctl flex items-center justify-center gap-1.5
                       text-[12.5px] font-semibold text-on-brand bg-brand transition hover:opacity-90">
            <span class="h-3 w-3"><x-nav-icon name="plus" :peso="2" /></span>
            Nova subtarefa
        </button>
    @endif

    {{-- A nota existe para não precisarem perguntar, como a do checklist e a
         dos vínculos — e aqui ela é a mais necessária das três, porque diz
         exatamente o contrário da de cima: vínculo não prende, subtarefa
         prende. Quem acabou de criar oito filhas precisa saber, ANTES de
         tentar, que a mãe não fecha enquanto elas estiverem abertas. --}}
    <p class="mt-1.5 px-1 text-[11px] leading-[1.45] text-ink-faint">
        A subtarefa é tarefa inteira: vai para a fila de triagem com etapa, responsável e anexos próprios.
        Esta aqui só encerra depois que todas forem concluídas ou canceladas.
    </p>

    @if ($bloqueada)
        <p class="mt-1.5 px-1 text-[11px] leading-[1.45]" style="color: rgb(var(--warn))">{{ $bloqueada }}</p>
    @endif
</div>
