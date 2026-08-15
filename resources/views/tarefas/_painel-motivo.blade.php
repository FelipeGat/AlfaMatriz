{{--
    O pedido de motivo — o MOLDE, um só para o quadro inteiro.

    Ele já morou dentro de cada card, guardado por `pendente.id === {id}`. Só que
    `pendente` é estado do quadro: um painel por vez, nunca dois. Cada card
    baixava a cópia inteira do formulário e 119 delas nunca serviriam — 4090
    bytes por card, 479 KB num quadro de 120 tarefas.

    O que ele NÃO deixou de ser: um painel que aparece DENTRO do card. O molde é
    clonado para `[data-motivo="{id}"]`, que mora no `<article>` daquela tarefa —
    ver `sincronizarPainelDoMotivo()` no script do quadro. Isto é requisito, e
    não detalhe: o painel já foi um bloco flutuante ancorado ao rodapé do quadro,
    e foi revertido porque a pessoa soltava o card na terceira coluna e o pedido
    de texto aparecia lá embaixo, longe do card de que falava.

    A borda esquerda de 2px na cor do destino é a mesma gramática das tarjas: o
    painel é uma notícia do card, não uma janela por cima dele.

    Fica num `<template>`: o navegador não desenha o conteúdo e o Alpine não o
    inicia — ele só ganha vida na cópia, dentro do card.
--}}
<template data-molde-do-motivo>
    {{--
        A guarda `x-if="pendente"` NÃO é decoração.

        Fechar o painel zera `pendente`, e os bindings do clone ainda estão
        vivos quando isso acontece: sem a guarda, todos eles leem `pendente.cor`,
        `pendente.acao`, `pendente.obrigatorio` de um `null` e o console enche de
        TypeError a cada cancelamento. O `x-if` desmonta a árvore antes de os
        efeitos rodarem, que é o que o `<template x-if="pendente && pendente.id
        === {id}">` de dentro do card fazia quando o formulário morava lá.
    --}}
    <template x-if="pendente">
    {{-- `data-parcial` só no bloqueio. Travar não tira a tarefa da etapa —
         quem travou continua olhando para o mesmo card, e recarregar ali
         custa a rolagem da coluna. Os outros destinos MOVEM a tarefa: o
         card sai de onde estava, e a recarga é o que confirma o movimento
         de corpo inteiro, com a guarda de concorrência do `de_status`
         respondendo pelo caminho de sempre. --}}
    <form method="POST" :action="pendente.acao" @submit="enviandoPendente = true" @click.stop data-parcial
          class="mt-[10px] p-[10px] rounded-[5px] border border-l-2"
          :style="`background: rgb(var(--${pendente.cor}) / calc(var(--tint-alpha) / 2));
                   border-color: rgb(var(--${pendente.cor}) / 0.4);
                   border-left-color: rgb(var(--${pendente.cor}))`">
        @csrf
        <input type="hidden" name="status" :value="pendente.status">
        <input type="hidden" name="de_status" :value="pendente.de">

        {{-- "Devolvendo para Em andamento": o verbo diz o que está
             acontecendo e o destino vem em negrito, na cor dele.
             "Confirmar" sozinho é o que se aperta sem ler. --}}
        <div class="flex items-center gap-2">
            <span class="h-[7px] w-[7px] shrink-0 rounded-full"
                  :style="`background: rgb(var(--${pendente.cor}))`"></span>
            <p class="flex-1 min-w-0 text-[12.5px] text-ink">
                <span x-text="pendente.verbo"></span>
                <strong class="font-semibold" :style="`color: rgb(var(--${pendente.cor}))`"
                        x-text="pendente.label"></strong>
            </p>
            <button type="button" @click.stop="fecharPendente()" title="Cancelar" aria-label="Cancelar"
                    class="shrink-0 h-5 w-5 rounded-badge flex items-center justify-center text-ink-mute
                           transition hover:text-ink">
                <span class="h-3 w-3"><x-nav-icon name="x-mark" :peso="2" /></span>
            </button>
        </div>

        {{-- Uma linha dizendo POR QUE o texto está sendo pedido: sem ela o
             campo é uma exigência sem causa, e a pessoa escreve qualquer
             coisa para passar. --}}
        <p class="mt-1.5 text-[11.5px] leading-[1.45] text-ink-dim" x-text="pendente.porque"></p>

        <template x-if="pendente.campo">
            <textarea x-ref="textoPendente" :name="pendente.campo" rows="2"
                      :placeholder="pendente.placeholder" x-model="textoPendente"
                      class="mt-2 block w-full px-[9px] py-[7px] rounded-[5px] bg-input text-ink
                             text-[12px] leading-[1.45] resize-y focus:ring-0"
                      :style="`border: 1px solid rgb(var(--${pendente.cor}) / 0.4)`"></textarea>
        </template>

        {{-- Quem revisa / quem testa (US-087): nos portões de exame o painel
             oferece uma pessoa, não pede um texto. O responsável aparece
             desabilitado — a bola do portão vai para quem examina, e a tela
             não deve oferecer a escolha que o motor vai recusar. --}}
        <template x-if="pendente.pessoa">
            <div class="mt-2">
                <label class="block mb-[5px] font-mono text-[9.5px] uppercase tracking-[0.08em] text-ink-faint"
                       x-text="pendente.pessoa"></label>
                <select name="interlocutor_id"
                        class="block w-full h-[30px] px-[9px] rounded-[5px] bg-input text-ink text-[12px] focus:ring-0"
                        :style="`border: 1px solid rgb(var(--${pendente.cor}) / 0.4)`">
                    <option value="">— sem apontar · a coluna é a fila —</option>
                    @foreach ($usuarios as $usuario)
                        <option value="{{ $usuario->id }}"
                                :disabled="pendente.responsavel === {{ $usuario->id }}">{{ $usuario->name }}</option>
                    @endforeach
                </select>
            </div>
        </template>

        {{-- O carimbo do staging. Nasce marcado: o caminho comum é ter
             validado, e quem NÃO validou é quem precisa agir para dizer. --}}
        <template x-if="pendente.pedeAprovacao">
            <label class="mt-2 flex items-center gap-[7px] text-[11.5px] text-ink-dim cursor-pointer">
                <input type="checkbox" name="relatorio_aprovado" value="1" checked
                       class="h-[14px] w-[14px] rounded-[3px] bg-input border-btn-line text-brand focus:ring-0">
                Validado no staging
            </label>
        </template>

        {{-- O botão ocupa a largura INTEIRA. Com um Cancelar ao lado ele
             era espremido a ~110px e "Liberar para o admin subir" virava
             "Liberar para o a…" — desistir é o × do cabeçalho ou Esc. --}}
        <div class="mt-2 flex flex-col gap-[7px]">
            <p class="font-mono text-[9.5px] uppercase tracking-[0.08em] truncate"
               :style="`color: ${(pendente.obrigatorio && ! textoPendente.trim())
                   ? 'rgb(var(--warn))' : 'rgb(var(--ink-faint))'}`"
               x-text="pendente.obrigatorio ? 'obrigatório' : 'opcional'"></p>

            <button type="submit"
                    :disabled="(pendente.obrigatorio && ! textoPendente.trim()) || enviandoPendente"
                    class="w-full h-[30px] px-2.5 rounded-[5px] text-[12px] font-semibold whitespace-nowrap
                           transition disabled:cursor-not-allowed"
                    :style="(pendente.obrigatorio && ! textoPendente.trim()) || enviandoPendente
                        ? 'background: rgb(var(--line)); color: rgb(var(--ink-faint))'
                        : `background: rgb(var(--${pendente.cor})); color: rgb(var(--on-brand))`"
                    x-text="enviandoPendente ? 'Enviando…' : pendente.acaoRotulo"></button>
        </div>
    </form>
    </template>
</template>
