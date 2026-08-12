@php
    /**
     * O checklist da tarefa, dentro do modal de edição.
     *
     * Nenhum `<form>` aqui: este bloco vive DENTRO do formulário da tarefa, e
     * formulário aninhado é HTML inválido — o navegador descarta o interno e a
     * falha é silenciosa. Os envios ficam em `_checklist-envios`, e os campos
     * daqui os alcançam pelo atributo `form`, como já fazem os comentários.
     */
    $progresso = $tarefa->progressoDoChecklist();
@endphp

{{--
    O componente vive no `<script>` do quadro (`index.blade.php`), e não aqui
    dentro do atributo. Ele já morou no `x-data` e o atributo QUEBRAVA: um
    comentário de código com aspas duplas fecha o atributo no meio, o Alpine
    recebe JavaScript truncado e o bloco inteiro morre em silêncio — a lista
    aparece na tela e nada nela responde.
--}}
<div class="pt-4 border-t border-rule" x-data="checklist({{ $tarefa->id }})">
    <div class="flex items-center gap-2">
        <h4 class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Checklist</h4>

        {{-- O progresso só existe quando há lista: "0 de 0" anunciaria como
             pendência uma tarefa que simplesmente não tem checklist. --}}
        @if ($progresso)
            <span class="font-mono text-[10.5px] text-ink-mute">
                {{ $progresso['feitos'] }}/{{ $progresso['total'] }}
            </span>
            <div class="flex-1 h-1 rounded-full overflow-hidden" style="background: rgb(var(--line))">
                <div class="h-full rounded-full transition-all"
                     style="width: {{ round($progresso['feitos'] / $progresso['total'] * 100) }}%;
                            background: rgb(var(--{{ $progresso['feitos'] === $progresso['total'] ? 'good' : 'brand' }}))"></div>
            </div>
        @endif
    </div>

    <ul x-ref="lista" class="mt-2 space-y-1">
        @foreach ($tarefa->itens as $item)
            <li data-item="{{ $item->id }}" draggable="true"
                @dragstart="arrastando = $el"
                @dragend="arrastando = null"
                @dragover.prevent
                @drop.prevent="soltarSobre($el)"
                class="group flex items-center gap-2 rounded-control px-1 py-0.5 hover:bg-chip transition"
                :class="arrastando === $el && 'opacity-40'">

                <span class="shrink-0 cursor-grab font-mono text-[11px] text-ink-faint select-none"
                      title="Arraste para reordenar" aria-hidden="true">⠿</span>

                {{--
                    O `feito=0` escondido vem ANTES da caixa e no mesmo envio:
                    caixa desmarcada não manda nada, e sem este par o item nunca
                    voltaria a ser pendente. A ordem importa — o valor que vale
                    é o último enviado, então a caixa marcada precisa vir depois.
                --}}
                <input type="hidden" name="feito" value="0" form="item-{{ $item->id }}">
                <input type="checkbox" name="feito" value="1" form="item-{{ $item->id }}"
                       @checked($item->feito)
                       @change="$el.form.requestSubmit()"
                       class="shrink-0 rounded border-line bg-input text-brand focus:ring-brand">

                {{--
                    Edição no lugar: o campo não tem moldura até receber foco.
                    Um input emoldurado por item transformaria a lista de
                    conferência num formulário de cadastro, que é o oposto do
                    que ela é — algo para correr o olho e marcar.
                --}}
                {{--
                    Os `!` não são preguiça: o projeto estiliza `input[type=text]`
                    globalmente (fundo, moldura e raio), e um seletor de
                    elemento+atributo vence a classe utilitária por
                    especificidade. Sem eles, cada item vira uma caixa de
                    formulário e a lista de conferência passa a parecer um
                    cadastro de cinco campos. O foco continua desenhando a
                    moldura — é o que diz "agora dá para escrever".
                --}}
                <input type="text" name="texto" value="{{ $item->texto }}" form="item-{{ $item->id }}"
                       maxlength="255"
                       @keydown.enter.prevent="$el.blur()"
                       @blur="if ($el.value.trim() && $el.value !== $el.defaultValue) $el.form.requestSubmit()"
                       class="flex-1 min-w-0 px-0 py-0.5 text-[12.5px] transition
                              !bg-transparent !rounded-none !border-x-0 !border-t-0 !border-b !border-transparent
                              focus:!border-brand
                              {{ $item->feito ? 'text-ink-faint line-through' : 'text-ink' }}">

                {{-- O remover só aparece no hover da linha: cinco lixeiras
                     acesas numa lista de cinco itens disputam a leitura com o
                     que se veio conferir. --}}
                <button type="submit" form="apagar-item-{{ $item->id }}"
                        title="Remover item" aria-label="Remover item"
                        class="shrink-0 h-5 w-5 rounded-control text-ink-faint opacity-0 group-hover:opacity-100
                               focus:opacity-100 hover:text-crit-text transition">✕</button>
            </li>
        @endforeach
    </ul>

    {{-- O campo de novo item é a última linha da lista, e não um botão que
         abre um campo: a diferença é um clique por item numa tela em que se
         escrevem vários seguidos. --}}
    <div class="mt-1.5 flex items-center gap-2 px-1">
        <span class="shrink-0 font-mono text-[11px] text-ink-faint select-none" aria-hidden="true">+</span>
        <input type="text" name="texto" form="novo-item-{{ $tarefa->id }}" maxlength="255"
               placeholder="Novo item — Enter para incluir"
               class="flex-1 min-w-0 px-0 py-0.5 text-[12.5px] text-ink placeholder-ink-faint transition
                      !bg-transparent !rounded-none !border-x-0 !border-t-0 !border-b !border-transparent
                      focus:!border-brand">
    </div>
</div>
