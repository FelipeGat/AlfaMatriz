@php
    /**
     * Arquivar a tarefa (#208): o gesto, no modal, para quem faz triagem.
     *
     * Recolhido atrás de uma linha discreta, como o "Marcar como duplicada":
     * é decisão de arrumação, não trabalho do dia, e um botão aceso no rodapé
     * convidaria a arquivar o que só está difícil.
     *
     * A tarja da tarefa JÁ arquivada mora em `_avisos-da-tarefa`, no topo do
     * modal, com o Desarquivar. O envio é próprio (`arquivar-{id}`, em
     * `_modais`): formulário aninhado é HTML inválido, e os campos apontam
     * para fora pelo atributo `form`.
     *
     * Espera: $tarefa.
     */
    $podeArquivar = (auth()->user()?->podeTriarTarefas() ?? false)
        && ! $tarefa->estaArquivada()
        && ! in_array($tarefa->status, \App\Models\Tarefa::STATUS_TERMINAIS, true);
@endphp

@if ($podeArquivar)
    <div x-data="{ arquivando: false, motivo: '' }">
        <button type="button" @click="arquivando = ! arquivando"
                class="text-[11.5px] text-ink-faint transition hover:text-ink">
            Não vai andar agora? Arquivar
        </button>

        <div x-show="arquivando" x-cloak class="mt-2 flex flex-col gap-2">
            <div class="flex items-end gap-2">
                <div class="flex-1 min-w-0">
                    <label for="arquivar-motivo-{{ $tarefa->id }}" class="block mb-[5px] text-[12px] font-medium text-ink-dim">
                        Por que arquivar?
                    </label>
                    <select id="arquivar-motivo-{{ $tarefa->id }}" name="motivo" x-model="motivo"
                            form="arquivar-{{ $tarefa->id }}" required
                            class="block w-full h-[34px] py-0 rounded-control bg-input border-line text-ink text-[12.5px]">
                        <option value="">Escolha…</option>
                        @foreach (\App\Models\Tarefa::MOTIVOS_DE_ARQUIVAMENTO as $chave => $rotulo)
                            <option value="{{ $chave }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" form="arquivar-{{ $tarefa->id }}" :disabled="! motivo"
                        class="shrink-0 h-[34px] px-3 rounded-control border border-btn-line text-[12.5px] font-semibold
                               text-ink-dim transition hover:text-ink hover:bg-chip disabled:cursor-not-allowed disabled:opacity-45">
                    Arquivar
                </button>
            </div>

            <textarea name="nota" form="arquivar-{{ $tarefa->id }}" rows="2" maxlength="2000"
                      placeholder="Opcional: o que quem reabrir vai querer saber…"
                      class="block w-full px-2.5 py-2 rounded-control bg-input border-line text-ink
                             text-[12.5px] leading-[1.45] resize-y"></textarea>

            <p class="text-[11px] leading-[1.4] text-ink-faint">
                Sai do quadro guardando a etapa, o responsável e a conversa.
                @if ($tarefa->criadoPor && $tarefa->criadoPor->id !== auth()->id())
                    {{ $tarefa->criadoPor->name }} recebe o aviso, e um comentário de quem abriu a traz de volta.
                @endif
                Para "não vamos fazer", cancele.
            </p>
        </div>
    </div>
@endif
