@php
    /**
     * O modal da tarefa — criação e edição.
     *
     * Medidas do `design/AlfaMatriz Tarefas.dc.html` (clique num card lá):
     * cabeçalho 14px/16px com ponto de 7px na cor da etapa, corpo com gap de
     * 14px, rodapé 12px/16px sobre `head`. `$tarefa` nulo é criação; o `$sufixo`
     * evita ids duplicados, porque o modal de edição se repete uma vez por card.
     */
    $sufixo = $tarefa?->id ?? 'nova';
    $edicao = (bool) $tarefa;

    // Prioridade e responsável são decisões de triagem. Para quem não a tem,
    // eles não aparecem desabilitados — somem. Campo travado à vista é um
    // convite recusado toda vez que se olha para ele, e a tela passaria a
    // conversar sobre uma permissão em vez de sobre a tarefa.
    $podeTriar = auth()->user()?->podeTriarTarefas() ?? false;

    // O `optgroup` só entra quando há as duas famílias. Rótulo de grupo sobre
    // um grupo só é moldura, e a casa que ainda não cadastrou nenhum sistema
    // interno veria "Produto" acima da lista de sempre, sem nada a distinguir.
    $agruparSistemas = $sistemas->pluck('natureza')->unique()->count() > 1;

    if ($edicao) {
        // O subtítulo diz ONDE a tarefa está e HÁ QUANTO TEMPO — a mesma
        // pergunta que o chip do card responde, e a primeira que se faz ao
        // abrir uma tarefa que se perdeu de vista.
        $eventoAberto = $tarefa->eventos->firstWhere('saiu_em', null);
        $entrouEm = $eventoAberto?->entrou_em ?? $tarefa->created_at;
        $tempoNaEtapa = \App\Models\Tarefa::duracaoCurta((int) $entrouEm->diffInSeconds(now()));
        $corDaEtapa = \App\Models\Tarefa::corDaEtapa($tarefa->status);
    }
@endphp

{{--
    O botão se tranca no primeiro envio: dois cliques rápidos mandavam dois
    envios, e o segundo publicava o comentário de novo. O `submit` já disparou
    quando o `disabled` entra, então o envio em curso não é cancelado — o que
    morre é o SEGUNDO clique.
--}}
<form method="POST"
      action="{{ $edicao ? route('tarefas.update', $tarefa) : route('tarefas.store') }}"
      class="flex flex-col"
      x-data="{ enviando: false, confirmandoExclusao: false, travando: false }"
      @submit="enviando = true">
    @csrf
    @if ($edicao)
        @method('PUT')
    @endif

    {{--
        Cabeçalho fixo. `sticky` e não `fixed` porque quem rola é o modal
        inteiro (o `x-modal` é um container com rolagem própria): grudado no
        topo dele, o cabeçalho acompanha sem sair do lugar, e a tarefa nunca
        fica anônima no meio da conversa.
    --}}
    <header class="sticky top-0 z-10 shrink-0 flex items-center gap-2.5 px-4 py-3.5 border-b border-line bg-panel">
        @if ($edicao)
            <span class="h-[7px] w-[7px] shrink-0 rounded-full"
                  style="background: rgb(var(--{{ $corDaEtapa }}))"></span>
        @endif

        <div class="min-w-0 flex-1">
            <h3 class="font-display text-[15px] font-semibold text-ink truncate">
                {{ $edicao ? 'Editar tarefa' : 'Nova tarefa' }}
            </h3>

            @if ($edicao)
                {{-- O número abre a linha porque é o que se copia daqui para
                     pedir a tarefa a alguém — e `truncate` no lugar do
                     `whitespace-nowrap`: com um item a mais, a linha que não
                     coubesse passaria a pintar por cima do botão de fechar em
                     vez de cortar. --}}
                <p class="mt-px font-mono text-[10px] uppercase tracking-[0.12em] text-ink-faint truncate">
                    {{ $tarefa->codigo() }} · {{ \App\Models\Tarefa::rotuloDaEtapa($tarefa->status) }} · há {{ $tempoNaEtapa }}
                </p>
            @endif
        </div>

        <button type="button" @click="$dispatch('close')" aria-label="Fechar"
                class="shrink-0 h-[26px] w-[26px] rounded-tile flex items-center justify-center
                       text-ink-mute transition hover:text-ink">
            <span class="h-[13px] w-[13px]"><x-nav-icon name="x-mark" :peso="2" /></span>
        </button>
    </header>

    <div class="px-4 py-4 flex flex-col gap-3.5">
        {{--
            Os três banners, logo abaixo do cabeçalho, na ordem do card
            (pergunta, retorno, bloqueio).

            Eles respondem "por que esta tarefa está parada" antes de qualquer
            campo. Enterrados no meio do formulário, seriam lidos depois de a
            pessoa já ter decidido o que veio fazer.
        --}}
        @if ($edicao && $tarefa->temPergunta())
            <div class="px-[11px] py-[9px] rounded-[5px] border border-l-2"
                 style="background: rgb(var(--brand) / 0.085); border-color: rgb(var(--brand) / 0.4);
                        border-left-color: rgb(var(--brand))">
                <div class="flex items-center gap-2.5">
                    <span class="h-3.5 w-3.5 shrink-0 text-brand-text"><x-nav-icon name="duvida" :peso="1.8" /></span>
                    <span class="flex-1 min-w-0 text-[12.5px] font-medium text-ink">
                        Aguardando resposta de {{ $tarefa->perguntaPara?->name ?? 'alguém' }}
                    </span>
                    <span class="shrink-0 font-mono text-[10.5px] whitespace-nowrap text-brand-text">
                        {{ max(1, $tarefa->rodadas) }}ª rodada
                    </span>
                </div>

                @if ($tarefa->conversaEmpacada())
                    <p class="mt-1.5 text-[11.5px] leading-[1.45] text-ink-dim">
                        Três idas e voltas sem resolver costuma querer dizer que o PR está grande demais ou que
                        a tarefa foi mal especificada — considere devolver para correção.
                    </p>
                @endif
            </div>
        @endif

        {{--
            O retorno faltava aqui, e era o único dos três que só existia no
            card. Lá o motivo é clamp de duas linhas — quem abria a tarefa para
            LER o que reprovou encontrava o formulário sem nenhuma menção à
            devolução, e a única cópia inteira do texto estava no `title` da
            tarja. Por isso este não tem clamp: é este o lugar onde o motivo
            aparece por extenso, com as quebras de linha que quem escreveu deu.
        --}}
        @if ($edicao && $tarefa->temRetorno())
            <div class="px-[11px] py-[9px] rounded-[5px] border border-l-2"
                 style="background: var(--warn-tint); border-color: var(--warn-line);
                        border-left-color: rgb(var(--warn))">
                <div class="flex items-center gap-2.5">
                    <span class="h-3.5 w-3.5 shrink-0" style="color: rgb(var(--warn))">
                        <x-nav-icon name="arrow-uturn-left" :peso="1.8" />
                    </span>
                    <span class="flex-1 min-w-0 font-mono text-[10.5px] font-semibold uppercase tracking-[0.08em]"
                          style="color: rgb(var(--warn))">{{ $tarefa->rotuloDoRetorno() }}</span>
                </div>

                @if (filled($tarefa->retorno_motivo))
                    <p class="mt-1.5 text-[12.5px] leading-[1.45] text-ink whitespace-pre-wrap">{{ $tarefa->retorno_motivo }}</p>
                @endif
            </div>
        @endif

        @if ($edicao && $tarefa->estaBloqueada())
            <div class="flex items-center gap-2.5 px-[11px] py-[9px] rounded-[5px] border border-l-2"
                 style="background: var(--warn-tint); border-color: var(--warn-line);
                        border-left-color: rgb(var(--warn))">
                <span class="h-3.5 w-3.5 shrink-0" style="color: rgb(var(--warn))">
                    <x-nav-icon name="cadeado-fechado" :peso="1.8" />
                </span>
                <span class="flex-1 min-w-0 text-[12.5px] text-ink">{{ $tarefa->bloqueio_motivo }}</span>
                <span class="shrink-0 font-mono text-[10.5px] font-semibold whitespace-nowrap"
                      style="color: rgb(var(--warn))">{{ $tarefa->bloqueadaHa() }}</span>

                {{-- Destravar é envio próprio, e o formulário da tarefa não pode
                     aninhar outro: o `form` aponta para fora, como o corrigir e
                     o apagar da conversa. --}}
                <button type="submit" form="bloquear-tarefa-{{ $tarefa->id }}"
                        class="shrink-0 h-6 px-2.5 rounded-tile border text-[11.5px] font-semibold transition hover:bg-chip"
                        style="border-color: var(--warn-line); color: rgb(var(--warn))">
                    Destravar
                </button>
            </div>
        @endif

        <div>
            <label for="titulo-{{ $sufixo }}" class="block mb-[5px] text-[12px] font-medium text-ink-dim">Título</label>
            <input id="titulo-{{ $sufixo }}" name="titulo" type="text" required maxlength="255"
                   value="{{ old('titulo', $tarefa->titulo ?? '') }}"
                   class="block w-full h-9 px-2.5 py-0 rounded-control bg-input border-line text-ink text-[13.5px]">
        </div>

        {{-- O resumo faltava, e é ele que o card mostra embaixo do título: sem
             o campo, a única forma de preenchê-lo era pelo banco. --}}
        <div>
            <label for="resumo-{{ $sufixo }}" class="block mb-[5px] text-[12px] font-medium text-ink-dim">Resumo</label>
            <textarea id="resumo-{{ $sufixo }}" name="resumo" rows="2" maxlength="255"
                      placeholder="Uma linha do que precisa acontecer…"
                      class="block w-full px-2.5 py-2 rounded-control bg-input border-line text-ink
                             text-[13px] leading-[1.45] resize-y">{{ old('resumo', $tarefa->resumo ?? '') }}</textarea>
        </div>

        {{-- Grade 2×2. O tipo vem primeiro porque é ele que decide o resto: a
             tarefa de desenvolvimento passa pelos portões e só fecha com o
             staging validado; a operacional fecha direto de Em andamento. --}}
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label for="tipo-{{ $sufixo }}" class="block mb-[5px] text-[12px] font-medium text-ink-dim">Tipo</label>
                <select id="tipo-{{ $sufixo }}" name="tipo"
                        class="block w-full h-9 py-0 rounded-control bg-input border-line text-ink text-[13px]">
                    @foreach (\App\Models\Tarefa::TIPOS as $chave => $label)
                        <option value="{{ $chave }}" @selected(old('tipo', $tarefa->tipo ?? 'desenvolvimento') === $chave)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                {{-- Uma linha, não um parágrafo: a diferença cabe numa frase, e
                     três linhas de ajuda embaixo de um select empurram a grade
                     inteira para baixo. --}}
                <p class="mt-1 text-[11px] leading-[1.4] text-ink-faint">
                    Operacional fecha direto, sem passar pelos portões.
                </p>
            </div>

            @if ($podeTriar)
                <div>
                    <label for="prioridade-{{ $sufixo }}" class="block mb-[5px] text-[12px] font-medium text-ink-dim">Prioridade</label>
                    <select id="prioridade-{{ $sufixo }}" name="prioridade"
                            class="block w-full h-9 py-0 rounded-control bg-input border-line text-ink text-[13px]">
                        @foreach (\App\Models\Tarefa::PRIORIDADES as $chave => $label)
                            <option value="{{ $chave }}" @selected(old('prioridade', $tarefa->prioridade ?? 'media') === $chave)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div>
                <label for="sistema_id-{{ $sufixo }}" class="block mb-[5px] text-[12px] font-medium text-ink-dim">Sistema</label>
                {{-- Agrupado por natureza: produto e sistema interno são duas
                     famílias, e numa lista corrida "AlfaMatriz" apareceria
                     entre dois produtos como se também fosse vendido. --}}
                <select id="sistema_id-{{ $sufixo }}" name="sistema_id"
                        class="block w-full h-9 py-0 rounded-control bg-input border-line text-ink text-[13px]">
                    <option value="">—</option>
                    @foreach ($sistemas->groupBy('natureza') as $natureza => $doGrupo)
                        @if ($agruparSistemas) <optgroup label="{{ \App\Models\Sistema::NATUREZAS[$natureza] ?? $natureza }}"> @endif
                        @foreach ($doGrupo as $sistema)
                            <option value="{{ $sistema->id }}" @selected(old('sistema_id', $tarefa->sistema_id ?? '') == $sistema->id)>
                                {{ $sistema->nome }}
                            </option>
                        @endforeach
                        @if ($agruparSistemas) </optgroup> @endif
                    @endforeach
                </select>
            </div>

            @if ($podeTriar)
                <div>
                    <label for="responsavel_id-{{ $sufixo }}" class="block mb-[5px] text-[12px] font-medium text-ink-dim">Responsável</label>
                    <select id="responsavel_id-{{ $sufixo }}" name="responsavel_id"
                            class="block w-full h-9 py-0 rounded-control bg-input border-line text-ink text-[13px]">
                        <option value="">—</option>
                        @foreach ($usuarios as $usuario)
                            <option value="{{ $usuario->id }}" @selected(old('responsavel_id', $tarefa->responsavel_id ?? '') == $usuario->id)>
                                {{ $usuario->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>

        {{--
            A ausência dita UMA vez, e no lugar onde os campos estariam.

            Sem esta linha, o formulário curto se lê como versão incompleta da
            tela — e a pessoa procura o campo que "sumiu", ou pior, acha que a
            tarefa dela nasce sem prioridade porque o sistema esqueceu. Dizer
            quem decide também responde a quem pedir.
        --}}
        @unless ($podeTriar)
            <p class="px-[11px] py-[9px] rounded-[5px] border text-[11.5px] leading-[1.5] text-ink-dim"
               style="background: var(--warn-tint); border-color: var(--warn-line)">
                @if ($edicao)
                    A prioridade e o responsável desta tarefa são definidos na triagem — por isso não aparecem
                    aqui. O resto do formulário é seu.
                @else
                    A tarefa entra como <strong class="text-ink">A definir</strong> e sem responsável:
                    priorizar e direcionar são decisões da triagem.
                @endif
            </p>
        @endunless

        {{--
            Checklist e conversa só na edição: tarefa que ainda não existe não
            tem nem uma nem outra, e o modal de criação não teria onde pendurar
            o comentário.
        --}}
        @if ($edicao)
            @include('tarefas._checklist', ['tarefa' => $tarefa])
            @include('tarefas._comentarios', ['tarefa' => $tarefa])
        @endif
    </div>

    {{--
        Rodapé fixo, sobre `head` para se separar do corpo sem precisar de
        sombra. As ações destrutivas ficam à ESQUERDA e as de saída à direita:
        a largura inteira do modal separa o excluir do salvar, que é o que
        impede o clique errado por proximidade.
    --}}
    <footer class="sticky bottom-0 shrink-0 flex flex-col gap-2.5 px-4 py-3 border-t border-line bg-head">
        {{-- Travar exige o motivo, então o botão do rodapé revela o campo em vez
             de enviar: um POST sem texto seria recusado pelo motor do fluxo com
             uma frase que a pessoa não pediu. --}}
        @if ($edicao && ! $tarefa->estaBloqueada())
            <div x-show="travando" x-cloak class="flex items-end gap-2">
                <div class="flex-1 min-w-0">
                    <label for="bloqueio-{{ $sufixo }}" class="block mb-[5px] text-[12px] font-medium text-ink-dim">
                        O que está travando?
                    </label>
                    <textarea id="bloqueio-{{ $sufixo }}" name="motivo" form="bloquear-tarefa-{{ $tarefa->id }}"
                              rows="2" required placeholder="Esperando quem, o quê…"
                              class="block w-full px-2.5 py-2 rounded-control bg-input border-line text-ink
                                     text-[12.5px] leading-[1.45] resize-y"></textarea>
                </div>
                <button type="submit" form="bloquear-tarefa-{{ $tarefa->id }}"
                        class="shrink-0 h-[34px] px-3 rounded-control border text-[12.5px] font-semibold transition hover:bg-chip"
                        style="border-color: var(--warn-line); color: rgb(var(--warn))">
                    Bloquear tarefa
                </button>
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-2.5">
            @if ($edicao)
                @if ($tarefa->estaBloqueada())
                    <button type="submit" form="bloquear-tarefa-{{ $tarefa->id }}"
                            class="h-[34px] px-3 rounded-control border text-[12.5px] font-semibold whitespace-nowrap transition hover:bg-chip"
                            style="border-color: var(--warn-line); color: rgb(var(--warn))">
                        Destravar tarefa
                    </button>
                @else
                    <button type="button" @click="travando = ! travando"
                            class="h-[34px] px-3 rounded-control border text-[12.5px] font-semibold whitespace-nowrap transition hover:bg-chip"
                            style="border-color: var(--warn-line); color: rgb(var(--warn))">
                        Marcar como bloqueada
                    </button>
                @endif

                {{--
                    Excluir em DOIS passos, e o segundo se anuncia no próprio
                    botão. É a única ação do quadro sem desfazer, e a nota diz a
                    diferença para cancelar — sem ela as duas palavras são
                    sinônimas na cabeça de quem lê, e uma apaga o histórico que
                    a outra existe para guardar.
                --}}
                @if ($podeTriar)
                    <button type="button"
                            @click="confirmandoExclusao ? $refs.excluir.click() : confirmandoExclusao = true"
                            title="Excluir apaga o registro. Para encerrar com rastro, use Cancelar no menu Mover."
                            class="h-[34px] px-3 rounded-control border text-[12.5px] font-semibold whitespace-nowrap transition"
                            :style="confirmandoExclusao
                                ? 'border-color: rgb(var(--crit)); background: rgb(var(--crit)); color: rgb(var(--on-brand))'
                                : 'border-color: rgb(var(--crit) / 0.4); background: transparent; color: rgb(var(--crit))'"
                            x-text="confirmandoExclusao ? 'Confirmar exclusão' : 'Excluir'"></button>

                    {{-- O envio de verdade mora fora do formulário da tarefa, e
                         o botão acima o aciona: aninhar formulário é HTML
                         inválido e o navegador descarta o de dentro. --}}
                    <button type="submit" form="excluir-tarefa-{{ $tarefa->id }}" x-ref="excluir" class="hidden"></button>

                    <span x-show="confirmandoExclusao" x-cloak
                          class="text-[11.5px] leading-[1.35] max-w-[210px]"
                          style="color: rgb(var(--crit))">
                        Apaga o registro. Para encerrar com rastro, cancele pelo menu Mover.
                    </span>
                @endif
            @endif

            <span class="ml-auto"></span>

            <button type="button" @click="$dispatch('close')"
                    class="h-[34px] px-3.5 rounded-control border border-btn-line text-ink-dim
                           text-[12.5px] font-semibold transition hover:text-ink">
                Cancelar
            </button>

            {{-- O rótulo literal é o que vale sem JS; com Alpine no ar, ele vira
                 o aviso de que o envio está em curso. --}}
            <button type="submit" :disabled="enviando"
                    class="h-[34px] px-4 rounded-control bg-brand text-on-brand text-[12.5px] font-semibold
                           transition hover:bg-brand-bright disabled:cursor-not-allowed"
                    x-text="enviando ? 'Salvando…' : 'Salvar'">Salvar</button>
        </div>
    </footer>
</form>
