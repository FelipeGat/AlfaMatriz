@php
    /**
     * Conversa da tarefa: o que o título e o resumo não cabem (US-049).
     *
     * O card é relance e o formulário é cadastro — nenhum dos dois é lugar
     * para "o cliente ligou de novo, agora o erro é outro". Isso vive aqui,
     * datado e assinado, dentro do mesmo modal em que a tarefa se edita.
     *
     * Esta partial NÃO abre formulário nenhum: no modo de escrita ela vive
     * DENTRO do formulário da tarefa, e o campo de comentário é mais um campo
     * dele — quem salva a tarefa publica o comentário junto. Formulário
     * aninhado é HTML inválido, e é por isso que os botões de corrigir e
     * apagar apontam, pelo atributo `form`, para os formulários que
     * `_comentarios-envios` monta depois do fechamento deste.
     *
     * `$somenteLeitura` é o histórico: tarefa encerrada se lê, não se comenta
     * — quem quer voltar a escrever reabre a tarefa (AC-131), e aí ela está
     * no quadro de novo.
     */
    $somenteLeitura ??= false;
    $comentarios = $tarefa->comentarios;
@endphp

<div @class(['border-t border-rule pt-4' => ! $somenteLeitura])>
    {{--
        O banner de pergunta em aberto.

        O card já traz a tarja, mas quem abre o detalhe para responder chega
        aqui — e sem o banner, a conversa aberta seria mais um comentário no
        meio da lista, indistinguível dos outros. Ele diz de quem é a vez e em
        que rodada a conversa está.
    --}}
    @if ($tarefa->temPergunta())
        @php $empacada = $tarefa->conversaEmpacada(); @endphp

        <div class="mb-4 px-[9px] py-[7px] rounded-tile border-l-2"
             style="background: rgb(var(--brand) / 0.085); border-color: rgb(var(--brand))">
            <div class="flex items-center gap-1.5">
                <span class="h-3 w-3 shrink-0 text-brand-text"><x-nav-icon name="duvida" :peso="1.9" /></span>
                <span class="flex-1 min-w-0 font-mono text-[9.5px] font-semibold uppercase tracking-[0.08em] text-brand-text">
                    Aguardando resposta de {{ $tarefa->perguntaPara?->name ?? 'alguém' }}
                </span>
                <span class="shrink-0 px-[5px] py-px rounded-badge font-mono text-[9px] font-semibold"
                      style="{{ $empacada
                          ? 'background: rgb(var(--crit) / var(--tint-alpha)); color: rgb(var(--crit))'
                          : 'background: var(--chip); color: rgb(var(--ink-mute))' }}">
                    {{ max(1, $tarefa->rodadas) }}ª rodada
                </span>
            </div>

            @if ($empacada)
                <p class="mt-[5px] font-mono text-[9px] uppercase tracking-[0.06em]" style="color: rgb(var(--crit))">
                    considere devolver para correção
                </p>
            @endif
        </div>
    @endif

    {{-- "Conversa", e não "Comentários": o que acontece aqui é uma troca com
         vez definida — perguntar passa a bola, responder devolve. "Comentários"
         descreveria um mural, onde ninguém deve nada a ninguém. --}}
    <div class="flex items-center gap-2 mb-2.5">
        <p class="flex-1 font-mono text-[10.5px] uppercase tracking-caps text-ink-mute">Conversa</p>
        <span class="font-mono text-[10.5px] text-ink-faint">{{ $comentarios->count() }}</span>
    </div>

    {{-- Altura limitada com rolagem própria: uma tarefa velha pode ter trinta
         comentários, e o campo de escrever não pode ir parar fora da tela por
         causa disso. --}}
    <div class="mt-3 space-y-2 max-h-[240px] overflow-y-auto overscroll-y-contain">
        @forelse ($comentarios as $comentario)
            @php $doAutor = ! $somenteLeitura && $comentario->autor_id === auth()->id(); @endphp

            @php
                // A cor do avatar sai do NOME, como no card: o mesmo rosto
                // guarda o mesmo tom entre as duas telas.
                $autor = $comentario->autor;
                $iniciaisAutor = $autor
                    ? \Illuminate\Support\Str::of($autor->name)->explode(' ')
                        ->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('')
                    : '—';
                $corAutor = $autor ? ['brand', 'accent', 'amber', 'good'][mb_strlen($autor->name) % 4] : 'ink-faint';
            @endphp

            <article x-data="{ editando: false }" class="rounded-[5px] border border-line bg-surface px-[11px] py-[9px]">
                <div class="flex items-center gap-2">
                    <span class="shrink-0 h-5 w-5 rounded-full flex items-center justify-center text-[8.5px] font-semibold text-ink"
                          style="background: rgb(var(--{{ $corAutor }}) / 0.18)">{{ $iniciaisAutor }}</span>

                    <span class="text-[12.5px] font-semibold text-ink">
                        {{ $autor?->name ?? 'Autor removido' }}
                    </span>

                    <span class="min-w-0 truncate font-mono text-[10px] text-ink-faint">
                        {{ $comentario->created_at->diffForHumans(short: true) }}
                        {{-- A correção é dita: quem leu a versão anterior
                             precisa saber que ela mudou. --}}
                        @if ($comentario->editado_em)
                            · <span title="Corrigido em {{ $comentario->editado_em->format('d/m/Y H:i') }}">editado</span>
                        @endif
                    </span>

                    {{-- Só o autor corrige e apaga o próprio, e a mesma regra
                         vale no servidor: o botão some, a rota recusa. O `form`
                         aponta para fora — mexer no que já foi publicado é
                         envio próprio, e não pode ir de carona no salvar da
                         tarefa. --}}
                    @if ($doAutor)
                        <button type="button" @click="editando = ! editando"
                                title="Corrigir este comentário"
                                class="ml-auto shrink-0 p-1 rounded-tile text-ink-faint hover:text-brand transition">
                            <span class="block h-[13px] w-[13px]"><x-nav-icon name="pencil" /></span>
                        </button>
                        <button type="submit" form="apagar-comentario-{{ $comentario->id }}"
                                title="Apagar este comentário"
                                class="shrink-0 p-1 rounded-tile text-ink-faint hover:text-crit transition">
                            <span class="block h-[13px] w-[13px]"><x-nav-icon name="trash" /></span>
                        </button>
                    @endif
                </div>

                {{--
                    Texto puro, pelo escape normal do Blade: nada que se digite
                    no campo vira HTML. `whitespace-pre-line` guarda as quebras
                    de linha de quem enumerou à mão — sem isso, três linhas
                    escritas em sequência sairiam grudadas num parágrafo só.
                --}}
                <p @if ($doAutor) x-show="! editando" @endif
                   class="mt-1.5 text-[12.5px] leading-[1.5] text-ink-dim whitespace-pre-wrap">{{ $comentario->corpo }}</p>

                @if ($doAutor)
                    {{-- O campo troca de dono pelo atributo `form`: ele vive
                         aqui, no meio da lista, mas é enviado pelo formulário
                         de correção que `_comentarios-envios` monta fora. --}}
                    <div x-show="editando" x-cloak class="mt-1.5">
                        <textarea name="corpo" form="editar-comentario-{{ $comentario->id }}"
                                  rows="3" required maxlength="4000"
                                  class="block w-full text-[13px] rounded-control bg-input border-line text-ink">{{ $comentario->corpo }}</textarea>

                        <div class="mt-1.5 flex items-center justify-end gap-2">
                            {{-- Cancelar é só fechar: o texto original está na
                                 tela e nada foi enviado ainda. --}}
                            <button type="button" @click="editando = false"
                                    class="h-[26px] px-2.5 rounded-control border border-btn-line text-[12px] font-medium text-ink-dim hover:text-ink transition">
                                Cancelar
                            </button>
                            <button type="submit" form="editar-comentario-{{ $comentario->id }}"
                                    class="h-[26px] px-2.5 rounded-control bg-brand text-on-brand font-semibold text-[12px] hover:bg-brand-bright transition">
                                Corrigir
                            </button>
                        </div>
                    </div>
                @endif
            </article>
        @empty
            <p class="px-3 py-3 rounded-[5px] border border-dashed border-line text-center text-[12px] text-ink-faint">
                {{ $somenteLeitura
                    ? 'Esta tarefa foi encerrada sem comentários.'
                    : 'Nenhum comentário ainda — o primeiro costuma ser o que a tarefa não conseguiu dizer no título.' }}
            </p>
        @endforelse
    </div>

    @unless ($somenteLeitura)
        <div class="mt-4">
            <x-input-label for="comentario-{{ $tarefa->id }}" value="Novo comentário" />

            {{-- O campo não formata nada: o que se digita é o que fica gravado
                 e o que aparece na tela, quebras de linha inclusive. --}}
            <textarea id="comentario-{{ $tarefa->id }}" name="comentario" rows="3" maxlength="4000"
                      placeholder="O que mais precisa ser dito sobre esta tarefa…"
                      class="mt-1 block w-full text-[13px] rounded-control bg-input border-line text-ink"></textarea>

            @error('comentario')
                <p class="mt-1 text-[12px] text-crit">{{ $message }}</p>
            @enderror

            {{--
                Duas saídas para o mesmo campo, e a diferença entre elas é de
                quem fica a vez.

                Salvar publica um comentário e não move nada. PERGUNTAR publica
                e passa a bola: a tarja aparece no card do outro lado, o sino o
                avisa e a rodada anda se ela estava com quem perguntou. É o
                mesmo texto — o que muda é se alguém está sendo cobrado por ele.

                O botão vive fora do formulário da tarefa pelo atributo `form`,
                como o corrigir e o apagar: formulário aninhado é HTML inválido.
                E ele copia o `corpo` para o campo escondido no clique, porque o
                textarea pertence ao outro formulário — sem isso, perguntar
                mandaria vazio.
            --}}
            @if (! in_array($tarefa->status, \App\Models\Tarefa::STATUS_TERMINAIS, true)
                    && ! $tarefa->esperaRespostaDe(auth()->user()))
                @php
                    // Quem recebe a pergunta, quando o quadro sabe sozinho. Nulo
                    // aqui não é impedimento: é uma pergunta a mais a fazer.
                    $outroLado = $tarefa->outroLadoDe(auth()->user());
                    $candidatos = $outroLado
                        ? collect()
                        : collect($usuarios ?? [])->reject(fn ($u) => $u->id === auth()->id());
                @endphp

                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <button type="submit" form="perguntar-{{ $tarefa->id }}"
                            onclick="document.getElementById('pergunta-corpo-{{ $tarefa->id }}').value =
                                     document.getElementById('comentario-{{ $tarefa->id }}').value"
                            class="shrink-0 h-[28px] px-2.5 rounded-control border text-[12px] font-semibold transition hover:bg-chip"
                            style="border-color: rgb(var(--brand) / 0.45); color: rgb(var(--brand-text))">
                        Perguntar
                    </button>

                    {{--
                        Sem outro lado, a tela PERGUNTA a quem passar a vez.

                        O caso é comum e não é erro: a tarefa é sua e ninguém
                        entrou na conversa ainda. Antes o botão aparecia e o
                        envio morria com "não há outro lado" — uma recusa que
                        culpa a pessoa por uma informação que a tela nunca pediu.
                        Botão que some seria pior ainda: some sem dizer por quê,
                        e some justamente de quem só tem esse caminho.

                        O `form` liga o select ao envio escondido, como o botão.
                    --}}
                    @if ($outroLado === null)
                        <select name="pergunta_para_id" form="perguntar-{{ $tarefa->id }}" required
                                class="shrink-0 h-[28px] py-0 max-w-[200px] text-[12px] rounded-control
                                       bg-input border-line text-ink-dim">
                            <option value="">Perguntar a quem…</option>
                            @foreach ($candidatos as $candidato)
                                <option value="{{ $candidato->id }}">{{ $candidato->name }}</option>
                            @endforeach
                        </select>
                    @endif

                    <p class="min-w-0 flex-1 text-[11.5px] text-ink-faint">
                        Salvar publica o comentário.
                        <strong class="font-semibold">Perguntar</strong>
                        @if ($outroLado === null)
                            publica e passa a vez para quem você escolher — esta tarefa ainda não tem outro lado.
                        @else
                            publica e passa a vez para o outro lado.
                        @endif
                    </p>
                </div>
            @else
                {{-- Sem botão próprio, o campo precisa dizer quando é publicado:
                     caixa de texto que não anuncia o próprio envio é caixa que se
                     preenche e se perde ao fechar o modal. --}}
                <p class="mt-1 text-[11.5px] text-ink-faint">
                    Entra na tarefa quando você salvar. Em branco, nada é publicado.
                </p>
            @endif
        </div>
    @endunless
</div>
