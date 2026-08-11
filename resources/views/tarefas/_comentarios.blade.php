@php
    /**
     * Conversa da tarefa: o que o título e o resumo não cabem (US-049).
     *
     * O card é relance e o formulário é cadastro — nenhum dos dois é lugar
     * para "o cliente ligou de novo, agora o erro é outro". Isso vive aqui,
     * datado e assinado, dentro do mesmo modal em que a tarefa se edita.
     *
     * `$somenteLeitura` é o histórico: tarefa encerrada se lê, não se comenta
     * — quem quer voltar a escrever reabre a tarefa (AC-131), e aí ela está
     * no quadro de novo.
     */
    $somenteLeitura ??= false;
    $comentarios = $tarefa->comentarios;
@endphp

<div class="px-6 pb-6 {{ $somenteLeitura ? 'pt-4' : 'pt-5 border-t border-rule' }}">
    <div class="flex items-center gap-2">
        <h4 class="font-display text-[14px] font-semibold text-ink">Comentários</h4>
        <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
            {{ $comentarios->count() }}
        </span>
    </div>

    {{-- Altura limitada com rolagem própria: uma tarefa velha pode ter trinta
         comentários, e o campo de escrever não pode ir parar fora da tela por
         causa disso. --}}
    <div class="mt-3 space-y-2 max-h-[240px] overflow-y-auto overscroll-y-contain">
        @forelse ($comentarios as $comentario)
            <article class="rounded-ctl border border-line bg-chip px-3 py-2.5">
                <div class="flex items-center gap-2">
                    <p class="min-w-0 flex-1 truncate font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                        {{ $comentario->autor?->name ?? 'Autor removido' }}
                        · {{ $comentario->created_at->format('d/m/Y H:i') }}
                    </p>

                    {{-- Só o autor apaga o próprio, e a mesma regra vale no
                         servidor: o botão some, a rota recusa. --}}
                    @if (! $somenteLeitura && $comentario->autor_id === auth()->id())
                        <form method="POST" action="{{ route('tarefas.comentarios.destroy', $comentario) }}"
                              class="shrink-0">
                            @csrf
                            @method('DELETE')
                            <button type="submit" title="Apagar este comentário"
                                    class="p-1 rounded-tile text-ink-faint hover:text-crit transition">
                                <span class="block h-[13px] w-[13px]"><x-nav-icon name="trash" /></span>
                            </button>
                        </form>
                    @endif
                </div>

                {{--
                    `{!! !!}` é seguro aqui e só aqui: `marcadoresEmHtml()`
                    escapa o texto ANTES de montar qualquer tag, e as únicas
                    tags que ela emite são de lista e parágrafo.

                    O estilo das listas vive na casca porque o corpo vem do
                    model sem classe nenhuma — o Tailwind zera marcador e
                    recuo, então sem isto a lista sairia como texto corrido e
                    o marcador não apareceria.
                --}}
                <div class="mt-1.5 text-[13px] leading-snug text-ink-dim
                            [&_p]:mt-1.5 [&_p:first-child]:mt-0
                            [&_ul]:mt-1.5 [&_ul]:pl-4 [&_ul]:list-disc
                            [&_ol]:mt-1.5 [&_ol]:pl-5 [&_ol]:list-decimal
                            [&_li]:mt-0.5 [&_li]:marker:text-ink-faint">
                    {!! $comentario->corpoEmHtml() !!}
                </div>
            </article>
        @empty
            <p class="text-[12.5px] text-ink-mute">
                {{ $somenteLeitura
                    ? 'Esta tarefa foi encerrada sem comentários.'
                    : 'Nenhum comentário ainda — o primeiro costuma ser o que a tarefa não conseguiu dizer no título.' }}
            </p>
        @endforelse
    </div>

    @unless ($somenteLeitura)
        {{--
            Os marcadores são um atalho de digitação, não um editor: os botões
            escrevem `- ` e `1. ` no campo, e o Enter continua a lista sozinho
            (e a encerra quando o item sai vazio). O que fica gravado é o texto
            que se vê — quem preferir digitar o traço na mão tem o mesmo
            resultado, e quem abrir o comentário por outro caminho lê algo que
            continua fazendo sentido.
        --}}
        <form method="POST" action="{{ route('tarefas.comentarios.store', $tarefa) }}" class="mt-4"
              x-data="{
                  /** Aplica o marcador nas linhas tocadas pela seleção. */
                  marcar(tipo) {
                      const campo = this.$refs.campo;
                      const valor = campo.value;
                      const inicio = valor.lastIndexOf('\n', Math.max(campo.selectionStart - 1, 0)) + 1;
                      let fim = valor.indexOf('\n', campo.selectionEnd);
                      if (fim === -1) fim = valor.length;

                      const linhas = valor.slice(inicio, fim).split('\n').map((linha, i) => {
                          const limpa = linha.replace(/^\s*([-*•]|\d+[.)])\s+/, '');
                          return (tipo === 'numero' ? (i + 1) + '. ' : '- ') + limpa;
                      });

                      campo.setRangeText(linhas.join('\n'), inicio, fim, 'end');
                      campo.focus();
                  },

                  /** Enter dentro de uma lista abre o próximo item. */
                  continuar(evento) {
                      const campo = this.$refs.campo;
                      if (campo.selectionStart !== campo.selectionEnd) return;

                      const valor = campo.value;
                      const inicio = valor.lastIndexOf('\n', Math.max(campo.selectionStart - 1, 0)) + 1;
                      const linha = valor.slice(inicio, campo.selectionStart);
                      const marcador = linha.match(/^\s*([-*•]|(\d+)[.)])\s+/);
                      if (! marcador) return;

                      evento.preventDefault();

                      // Item vazio quer dizer que a lista acabou: o marcador
                      // solto sai e a escrita volta a ser parágrafo.
                      if (linha.trim() === marcador[0].trim()) {
                          campo.setRangeText('', inicio, campo.selectionStart, 'end');
                          return;
                      }

                      const proximo = marcador[2] ? (Number(marcador[2]) + 1) + '. ' : '- ';
                      campo.setRangeText('\n' + proximo, campo.selectionStart, campo.selectionStart, 'end');
                  },
              }">
            @csrf

            <div class="flex items-center gap-2">
                <x-input-label for="corpo-{{ $tarefa->id }}" value="Novo comentário" class="flex-1" />
                <button type="button" @click="marcar('ponto')" title="Lista com marcador"
                        class="h-[26px] px-2 rounded-control border border-btn-line font-mono text-[11px] text-ink-mute hover:text-brand hover:border-brand transition">
                    • lista
                </button>
                <button type="button" @click="marcar('numero')" title="Lista numerada"
                        class="h-[26px] px-2 rounded-control border border-btn-line font-mono text-[11px] text-ink-mute hover:text-brand hover:border-brand transition">
                    1. lista
                </button>
            </div>

            <textarea id="corpo-{{ $tarefa->id }}" name="corpo" x-ref="campo" rows="3" required maxlength="4000"
                      @keydown.enter="continuar($event)"
                      placeholder="O que mais precisa ser dito sobre esta tarefa…"
                      class="mt-1 block w-full text-[13px] rounded-control bg-input border-line text-ink"></textarea>

            @error('corpo')
                <p class="mt-1 text-[12px] text-crit">{{ $message }}</p>
            @enderror

            <div class="mt-2 flex items-center justify-between gap-3">
                <p class="text-[11.5px] text-ink-faint">
                    Comece a linha com <span class="font-mono">-</span> para marcador ou
                    <span class="font-mono">1.</span> para numeração.
                </p>
                <button type="submit"
                        class="h-[30px] px-3 rounded-control bg-brand text-on-brand font-semibold text-[12px] hover:bg-brand-bright transition">
                    Comentar
                </button>
            </div>
        </form>
    @endunless
</div>
