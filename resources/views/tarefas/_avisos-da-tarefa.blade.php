@php
    /**
     * Os três banners do topo do modal, na ordem do card: pergunta, retorno,
     * bloqueio.
     *
     * Eles respondem "por que esta tarefa está parada" antes de qualquer campo.
     * Enterrados no meio do formulário, seriam lidos depois de a pessoa já ter
     * decidido o que veio fazer.
     *
     * Partial própria porque os três mudam sem que a tarefa mude de etapa —
     * perguntar, responder, travar e destravar acontecem com o modal aberto, e
     * é este bloco que volta redesenhado no JSON para ser trocado no lugar. O
     * resto do formulário fica onde está de propósito: é o que preserva o
     * título editado e o comentário ainda não publicado.
     *
     * Espera: $tarefa.
     */
@endphp

@if ($tarefa->temPergunta())
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
    O retorno faltava aqui, e era o único dos três que só existia no card. Lá o
    motivo é clamp de duas linhas — quem abria a tarefa para LER o que reprovou
    encontrava o formulário sem nenhuma menção à devolução, e a única cópia
    inteira do texto estava no `title` da tarja. Por isso este não tem clamp: é
    este o lugar onde o motivo aparece por extenso, com as quebras de linha que
    quem escreveu deu.
--}}
@if ($tarefa->temRetorno())
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

@if ($tarefa->estaBloqueada())
    <div class="flex items-center gap-2.5 px-[11px] py-[9px] rounded-[5px] border border-l-2"
         style="background: var(--warn-tint); border-color: var(--warn-line);
                border-left-color: rgb(var(--warn))">
        <span class="h-3.5 w-3.5 shrink-0" style="color: rgb(var(--warn))">
            <x-nav-icon name="cadeado-fechado" :peso="1.8" />
        </span>
        <span class="flex-1 min-w-0 text-[12.5px] text-ink">{{ $tarefa->bloqueio_motivo }}</span>
        <span class="shrink-0 font-mono text-[10.5px] font-semibold whitespace-nowrap"
              style="color: rgb(var(--warn))">{{ $tarefa->bloqueadaHa() }}</span>

        {{-- Destravar é envio próprio, e o formulário da tarefa não pode aninhar
             outro: o `form` aponta para fora, como o corrigir e o apagar da
             conversa. --}}
        <button type="submit" form="bloquear-tarefa-{{ $tarefa->id }}"
                class="shrink-0 h-6 px-2.5 rounded-tile border text-[11.5px] font-semibold transition hover:bg-chip"
                style="border-color: var(--warn-line); color: rgb(var(--warn))">
            Destravar
        </button>
    </div>
@endif
