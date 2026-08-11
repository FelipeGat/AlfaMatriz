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
            @php $doAutor = ! $somenteLeitura && $comentario->autor_id === auth()->id(); @endphp

            <article x-data="{ editando: false }" class="rounded-ctl border border-line bg-chip px-3 py-2.5">
                <div class="flex items-center gap-2">
                    <p class="min-w-0 flex-1 truncate font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                        {{ $comentario->autor?->name ?? 'Autor removido' }}
                        · {{ $comentario->created_at->format('d/m/Y H:i') }}
                        {{-- A correção é dita: quem leu a versão anterior
                             precisa saber que ela mudou. --}}
                        @if ($comentario->editado_em)
                            · <span title="Corrigido em {{ $comentario->editado_em->format('d/m/Y H:i') }}">editado</span>
                        @endif
                    </p>

                    {{-- Só o autor corrige e apaga o próprio, e a mesma regra
                         vale no servidor: o botão some, a rota recusa. O `form`
                         aponta para fora — mexer no que já foi publicado é
                         envio próprio, e não pode ir de carona no salvar da
                         tarefa. --}}
                    @if ($doAutor)
                        <button type="button" @click="editando = ! editando"
                                title="Corrigir este comentário"
                                class="shrink-0 p-1 rounded-tile text-ink-faint hover:text-brand transition">
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
                   class="mt-1.5 text-[13px] leading-snug text-ink-dim whitespace-pre-line">{{ $comentario->corpo }}</p>

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
            <p class="text-[12.5px] text-ink-mute">
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

            {{-- Sem botão próprio, o campo precisa dizer quando é publicado:
                 caixa de texto que não anuncia o próprio envio é caixa que se
                 preenche e se perde ao fechar o modal. --}}
            <p class="mt-1 text-[11.5px] text-ink-faint">
                Entra na tarefa quando você salvar. Em branco, nada é publicado.
            </p>
        </div>
    @endunless
</div>
