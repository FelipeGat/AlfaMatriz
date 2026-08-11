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
                    Texto puro, pelo escape normal do Blade: nada que se digite
                    no campo vira HTML. `whitespace-pre-line` guarda as quebras
                    de linha de quem enumerou à mão — sem isso, três linhas
                    escritas em sequência sairiam grudadas num parágrafo só.
                --}}
                <p class="mt-1.5 text-[13px] leading-snug text-ink-dim whitespace-pre-line">{{ $comentario->corpo }}</p>
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
        <form method="POST" action="{{ route('tarefas.comentarios.store', $tarefa) }}" class="mt-4">
            @csrf

            <x-input-label for="corpo-{{ $tarefa->id }}" value="Novo comentário" />

            {{-- O campo não formata nada: o que se digita é o que fica
                 gravado e o que aparece na tela, quebras de linha inclusive.
                 Quem quiser enumerar escreve os traços à mão, e eles
                 continuam traços. --}}
            <textarea id="corpo-{{ $tarefa->id }}" name="corpo" rows="3" required maxlength="4000"
                      placeholder="O que mais precisa ser dito sobre esta tarefa…"
                      class="mt-1 block w-full text-[13px] rounded-control bg-input border-line text-ink"></textarea>

            @error('corpo')
                <p class="mt-1 text-[12px] text-crit">{{ $message }}</p>
            @enderror

            <div class="mt-2 flex items-center justify-end gap-3">
                <button type="submit"
                        class="h-[30px] px-3 rounded-control bg-brand text-on-brand font-semibold text-[12px] hover:bg-brand-bright transition">
                    Comentar
                </button>
            </div>
        </form>
    @endunless
</div>
