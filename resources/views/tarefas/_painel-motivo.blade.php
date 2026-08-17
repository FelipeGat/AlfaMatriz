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
    {{-- `data-parcial` para todos os destinos: a resposta redesenha o quadro
         e devolve as rolagens a onde estavam — e `data-acompanha` completa o
         movimento, rolando até o card no destino (ver `enviar()` no script do
         quadro). Sem isso o card confirmado aparecia fora da vista, e o
         painel que acabou de ser respondido lia como resposta perdida. No
         bloqueio o atributo sai: travar não tira a tarefa da etapa, e quem
         travou continua olhando para o mesmo card. --}}
    {{-- `enctype` pelas imagens da devolução: o envio normal é interceptado e
         vai como `FormData`, que já é multipart — o atributo é o reserva do
         envio nativo, que sem ele descartaria os arquivos em silêncio. --}}
    <form method="POST" enctype="multipart/form-data"
          :action="pendente.acao" @submit="enviandoPendente = true" @click.stop data-parcial
          :data-acompanha="pendente.status ? pendente.id : false"
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

        {{-- As imagens da devolução: o print do que reprovou viaja no MESMO
             envio que move o card — junto do motivo de que ele é a metade que
             o texto não carrega. Só na devolução para correção (`imagens` na
             receita). Duas portas, seletor e Ctrl+V, e um funil só: quem
             filtra e reescreve a carga é o quadro (`acrescentarImagens`),
             porque o `$refs` do clone não chega lá. A prévia usa a mesma
             grade dos anexos do modal (`_anexos`). --}}
        <template x-if="pendente.imagens">
            <div class="mt-2">
                <div x-show="imagensPendentes.length" x-cloak class="mb-1.5 grid grid-cols-4 gap-1.5">
                    <template x-for="(imagem, indice) in imagensPendentes" :key="imagem.url">
                        <div class="group relative aspect-[4/3] rounded-[5px] border border-line bg-surface overflow-hidden">
                            <img :src="imagem.url" :alt="imagem.arquivo.name" class="h-full w-full object-cover">

                            {{-- Tirar da devolução antes de enviar. Sempre
                                 visível, ao contrário da lixeira do modal: a
                                 prévia é rascunho, não acervo — aqui desfazer
                                 É o gesto esperado, e são no máximo três. --}}
                            <button type="button" @click.stop="removerImagem(indice)"
                                    title="Tirar da devolução" aria-label="Tirar da devolução"
                                    class="absolute top-1 right-1 h-5 w-5 rounded-badge flex items-center justify-center
                                           text-white transition hover:brightness-125"
                                    style="background: rgb(0 0 0 / 0.55)">
                                <span class="block h-[11px] w-[11px]"><x-nav-icon name="x-mark" :peso="1.9" /></span>
                            </button>
                        </div>
                    </template>
                </div>

                <label class="inline-flex h-[26px] px-2.5 rounded-control border border-btn-line items-center gap-1.5
                              text-[12px] font-medium text-ink-dim cursor-pointer transition hover:text-ink">
                    <span class="h-[13px] w-[13px]"><x-nav-icon name="paperclip" :peso="1.8" /></span>
                    Anexar imagens
                    <input type="file" name="anexos[]" multiple class="sr-only"
                           accept="{{ \App\Models\TarefaAnexo::ACEITE_DE_IMAGENS }}"
                           @change="escolherImagens($event)">
                </label>

                {{-- A outra porta é dita, como na seção de anexos — o colar
                     que ninguém anuncia é feature que não existe. EMBAIXO do
                     botão, e não ao lado: o painel vive num card de coluna de
                     272px, e na linha do botão sobravam ~85px — o texto saía
                     num filete de cinco linhas. É a armadilha do texto de
                     ajuda: ou aparece inteiro, ou não aparece. --}}
                <p class="mt-1.5 text-[11.5px] leading-[1.45] text-ink-faint">
                    Print cola com <strong class="font-semibold text-ink-mute">Ctrl+V</strong>, aqui mesmo.
                </p>

                {{-- A recusa é dita inteira ou não é dita, como nos anexos do
                     modal: frase pela metade manda tentar de novo sem saber o
                     que mudar. --}}
                <p x-show="avisoDeImagens" x-cloak x-text="avisoDeImagens"
                   class="mt-1.5 text-[11.5px] leading-[1.45] text-crit"></p>
            </div>
        </template>

        {{-- Quem revisa / quem testa (US-087): nos portões de exame o painel
             oferece uma pessoa, não pede um texto. O responsável entra na
             lista como qualquer um — apontá-lo é o "dev valida" de sempre:
             quem move o card de outra pessoa devolve a bola para ela, e o
             sino avisa. `py-0` sem mexer no padding horizontal, como os
             outros selects do app: sobrescrevê-lo tirava o espaço da seta e
             o nome aparecia cortado embaixo dela. --}}
        <template x-if="pendente.pessoa">
            <div class="mt-2">
                <label class="block mb-[5px] font-mono text-[9.5px] uppercase tracking-[0.08em] text-ink-faint"
                       x-text="pendente.pessoa"></label>
                <select name="interlocutor_id"
                        class="block w-full h-[30px] py-0 rounded-[5px] bg-input text-ink text-[12px]"
                        :style="`border: 1px solid rgb(var(--${pendente.cor}) / 0.4)`">
                    <option value="">— sem apontar —</option>
                    @foreach ($usuarios as $usuario)
                        <option value="{{ $usuario->id }}">{{ $usuario->name }}</option>
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
