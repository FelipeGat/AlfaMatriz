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

    // O checklist da criação renasce do `old()` quando a validação devolve a
    // página inteira — sem isto, um título em falta apagaria a lista já
    // digitada. Filtrado porque o campo de novo item viaja no array mesmo
    // vazio, e um envio devolvido não pode transformá-lo num item em branco.
    $itensIniciais = $edicao ? [] : array_values(array_filter(
        array_map(fn ($item) => trim((string) $item), (array) old('itens', [])),
        fn ($item) => $item !== ''
    ));

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

    E DESTRANCA quando a viagem acaba, dê no que der. Enquanto o Salvar
    recarregava a página, destrancar era de graça — a página voltava nova. Com
    o envio parcial não é: nem o modal de edição nem o de nova tarefa são
    redesenhados pela resposta do Salvar, então o `true` ficava, e reabrir a
    tarefa mostrava "Salvando…" num botão morto. Quem avisa é o remetente
    parcial, no `index.blade.php`.
--}}
{{-- `data-parcial` também aqui: o Salvar era o último envio da tela a repintar
     a página inteira. O modal continua fechando ao salvar, mas por outro
     caminho — o bloco de modais volta redesenhado e cada um nasce fechado. Na
     criação, quem fecha é o `fecharModal`, porque o "nova tarefa" é único e
     vive fora daquele bloco. --}}
<form method="POST" data-parcial
      {{-- O Salvar PUBLICA o comentário escrito no campo. Sem esvaziá-lo, ele
           continuaria na tela depois de gravado, e o Salvar seguinte publicaria
           a mesma frase de novo — o mesmo motivo do Perguntar. --}}
      @if ($edicao) data-limpa="#comentario-{{ $tarefa->id }}" @endif
      {{-- Só a criação carrega arquivo, e por isso só ela é multipart: o
           `new FormData(form)` do envio parcial embala os arquivos sozinho,
           mas o envio comum do navegador — sem JavaScript — manda os NOMES e
           deixa o conteúdo para trás sem avisar. Na edição o anexo vai por
           `fetch` próprio, e o `enctype` aqui só engordaria todo Salvar. --}}
      @unless ($edicao) enctype="multipart/form-data" @endunless
      action="{{ $edicao ? route('tarefas.update', $tarefa) : route('tarefas.store') }}"
      class="flex flex-col"
      {{-- `bloqueada` é estado de tela, não do servidor, porque travar e
           destravar acontecem com este modal aberto e sem recarga: quem o
           atualiza depois da ação é o `trocarPedacos` do quadro. Nasce do PHP
           para a primeira pintura estar certa. --}}
      x-data="{
          enviando: false,
          confirmandoExclusao: false,
          travando: false,
          bloqueada: {{ $edicao && $tarefa->estaBloqueada() ? 'true' : 'false' }},
      }"
      @submit="enviando = true"
      @envio-terminou="enviando = false">
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
            Os três banners — pergunta, retorno, bloqueio —, que agora moram em
            `_avisos-da-tarefa`: eles mudam com o modal ABERTO (perguntar,
            responder, travar, destravar), e é este bloco que o servidor manda
            redesenhado para ser trocado no lugar sem recarregar a tela.

            `display: contents` não é enfeite. O pai é um flex com `gap-3.5`, e
            um invólucro comum contaria como filho mesmo vazio — a tarefa sem
            nenhum dos três avisos ganharia 14px de vão antes do título, um
            espaço que ninguém pediu e que muda conforme o dia.
        --}}
        @if ($edicao)
            <div class="contents" data-pedaco="avisos-{{ $tarefa->id }}">
                @include('tarefas._avisos-da-tarefa', ['tarefa' => $tarefa])
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
            {{-- A caixa acompanha o texto em vez de rolar por dentro. Com 500
                 caracteres cabendo no resumo, duas linhas fixas escondiam o
                 começo do que a pessoa acabou de escrever, e reler exigia
                 arrastar o canto — trabalho manual para ver o que já estava
                 lá. Vazia, ela continua com as duas linhas do desenho: quem
                 escreve uma frase não paga o espaço de quem escreve cinco.

                 O reajuste também escuta `open-modal` porque textarea
                 escondido mede `scrollHeight` zero — sem isso, a tarefa com
                 resumo longo abriria para edição com a caixa pequena de novo,
                 que é justamente o caso em que o espaço faz falta. --}}
            <textarea id="resumo-{{ $sufixo }}" name="resumo" rows="2" maxlength="500"
                      x-data="{
                          acompanharOTexto() {
                              if (! this.$el.offsetParent) return

                              this.$el.style.height = 'auto'

                              // `scrollHeight` não conta a borda, e o campo é
                              // `border-box`: sem somá-la de volta, a caixa
                              // fica 2px curta e a última linha continua
                              // rolando por dentro — o problema inteiro, só
                              // que pequeno demais para se ver e grande o
                              // bastante para incomodar.
                              const traco = getComputedStyle(this.$el)
                              const bordas = parseFloat(traco.borderTopWidth) + parseFloat(traco.borderBottomWidth)

                              this.$el.style.height = (this.$el.scrollHeight + bordas) + 'px'
                          }
                      }"
                      x-init="$nextTick(() => acompanharOTexto())"
                      x-on:input="acompanharOTexto()"
                      x-on:open-modal.window="$nextTick(() => acompanharOTexto())"
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
            Só a conversa fica para a edição: tarefa que ainda não existe não
            tem onde pendurar um comentário. O checklist não espera mais — os
            passos costumam estar na cabeça de quem abre a tarefa, e "salve
            primeiro, liste depois" é o mesmo segundo gesto que os anexos já
            recusaram (AC-234). Na criação ele é outro mecanismo de propósito:
            não há tarefa, logo não há rota de item nem pedaço a redesenhar —
            os itens são campos `itens[]` do próprio formulário, e viajam no
            POST que cria a tarefa.

            Os anexos têm o include fora do `@if` — quem abre uma tarefa quase
            sempre está olhando para o print que a motivou (AC-234), e a tarefa
            nasceria descrevendo por escrito o que já estava na tela.

            Eles ficam ENTRE o checklist e a conversa de propósito: os anexos
            são prova do que a tarefa é, e a conversa é o que se diz sobre
            isso. Depois da conversa, virariam rodapé de uma lista que já rola
            por dentro. Na criação a conversa não existe, e a ordem entre os
            três continua a mesma.
        --}}
        {{-- Checklist e conversa vêm embrulhados num `data-pedaco`: são as duas
             regiões que o servidor redesenha e troca no lugar depois de marcar
             um item, corrigir um comentário ou passar a vez. O invólucro é
             `display: contents` pelo mesmo motivo dos avisos — o pai é um flex
             com vão, e um filho a mais mudaria o espaçamento.

             Os anexos ficam de fora porque já se atualizam sozinhos: eles vivem
             em estado do Alpine e enviam por `fetch` desde a US-064. --}}
        @if ($edicao)
            <div class="contents" data-pedaco="checklist-{{ $tarefa->id }}">
                @include('tarefas._checklist', ['tarefa' => $tarefa])
            </div>
        @else
            {{-- O campo de novo item também é um `itens[]`: o texto digitado e
                 não confirmado com Enter entra no envio mesmo assim, em vez de
                 morrer na tela junto com o clique no Salvar. O Enter só o
                 promove a linha da lista — e com ele `.prevent` em todos os
                 campos daqui, porque Enter num input dispararia o formulário
                 inteiro. Sem JavaScript sobra um campo, um item: degrada, não
                 quebra. --}}
            {{-- `@reset.window` pelo mesmo motivo dos anexos: o quadro chama
                 `form.reset()` depois de a tarefa nascer, e o evento sobe do
                 `<form>`, que é o PAI desta caixa. O `reset` limpa os campos,
                 mas não a lista do Alpine que os reescreve — sem o ouvinte, a
                 PRÓXIMA tarefa nasceria com o checklist da anterior. --}}
            {{-- E limpa num `$nextTick`, não na hora: o `x-model` do Alpine
                 também ouve o `reset` e devolve o campo zerado ao modelo num
                 `nextTick` seu — que roda DEPOIS de um `itens = []` síncrono e
                 ressuscitava a lista como uma linha em branco. O ouvinte dele
                 fica no `<form>` e enfileira antes; este entra depois na mesma
                 fila, e a palavra final é o vazio. --}}
            <div class="pt-4 border-t border-rule"
                 x-data="{ itens: @js($itensIniciais) }"
                 @reset.window="if ($event.target.contains($el)) $nextTick(() => itens = [])">
                <h4 class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Checklist</h4>

                <ul class="mt-2 space-y-1">
                    <template x-for="(item, i) in itens" :key="i">
                        <li class="group flex items-center gap-2 rounded-control px-1 py-0.5 hover:bg-chip transition">
                            {{-- Os `!` pela mesma razão do checklist da edição:
                                 o estilo global de `input[type=text]` vence a
                                 classe utilitária por especificidade, e cada
                                 item viraria uma caixa de formulário. --}}
                            <input type="text" name="itens[]" x-model="itens[i]" maxlength="255"
                                   @keydown.enter.prevent
                                   class="flex-1 min-w-0 px-0 py-0.5 text-[12.5px] text-ink transition
                                          !bg-transparent !rounded-none !border-x-0 !border-t-0 !border-b !border-transparent
                                          focus:!border-brand">

                            <button type="button" @click="itens.splice(i, 1)"
                                    title="Remover item" aria-label="Remover item"
                                    class="shrink-0 h-5 w-5 rounded-control text-ink-faint opacity-0 group-hover:opacity-100
                                           focus:opacity-100 hover:text-crit transition">✕</button>
                        </li>
                    </template>
                </ul>

                <input type="text" name="itens[]" maxlength="255"
                       placeholder="+ item · Enter para adicionar"
                       @keydown.enter.prevent="if ($el.value.trim()) { itens.push($el.value.trim()); $el.value = '' }"
                       class="mt-2 block w-full h-8 px-2.5 text-[12.5px] text-ink placeholder-ink-faint transition
                              bg-input border border-dashed !border-btn-line !rounded-ctl
                              focus:!border-brand">

                <p class="mt-1.5 px-1 text-[11px] leading-[1.45] text-ink-faint">
                    Checklist não é subtarefa: não tem responsável nem etapa, e não entra na conta de trabalho em curso.
                    O que precisa de dono próprio vira tarefa.
                </p>
            </div>
        @endif

        @include('tarefas._anexos', ['tarefa' => $tarefa])

        @if ($edicao)
            <div class="contents" data-pedaco="conversa-{{ $tarefa->id }}">
                @include('tarefas._comentarios', ['tarefa' => $tarefa])
            </div>
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
        {{-- `! bloqueada` no Alpine, e não um `@if` do Blade: travar e destravar
             acontecem sem recarregar a página, e uma condição resolvida no
             servidor deixaria o rodapé oferecendo "Bloquear tarefa" numa tarefa
             que acabou de travar. O estado inicial continua vindo do PHP. --}}
        @if ($edicao)
            <div x-show="travando && ! bloqueada" x-cloak class="flex items-end gap-2">
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
                {{-- Os dois botões existem no HTML e o Alpine mostra um; o que
                     começa escondido leva o `display:none` no MESMO `style` das
                     cores — atributo repetido perde a segunda cópia, e o botão
                     sairia sem moldura nem tom de aviso.

                     Assim o rodapé acompanha um travamento que aconteceu sem
                     recarga, e ainda assim nasce certo na primeira pintura:
                     `x-cloak` nos dois esconderia ambos enquanto o Alpine não
                     subisse, e rodapé sem botão nenhum é pior que um botão que
                     chega meio quadro depois. --}}
                <button type="submit" form="bloquear-tarefa-{{ $tarefa->id }}" x-show="bloqueada"
                        class="h-[34px] px-3 rounded-control border text-[12.5px] font-semibold whitespace-nowrap transition hover:bg-chip"
                        style="border-color: var(--warn-line); color: rgb(var(--warn));
                               @if (! $tarefa->estaBloqueada()) display: none @endif">
                    Destravar tarefa
                </button>

                <button type="button" @click="travando = ! travando" x-show="! bloqueada"
                        class="h-[34px] px-3 rounded-control border text-[12.5px] font-semibold whitespace-nowrap transition hover:bg-chip"
                        style="border-color: var(--warn-line); color: rgb(var(--warn));
                               @if ($tarefa->estaBloqueada()) display: none @endif">
                    Marcar como bloqueada
                </button>

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
