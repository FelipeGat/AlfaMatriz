@php
    /**
     * A galeria de imagens da tarefa (US-064).
     *
     * "O botão saiu do lugar" é uma frase que só quem viu a tela entende. Na
     * revisão — que é onde a feature nasceu — descrever um defeito por escrito
     * custa rodadas que uma captura encerra de uma vez.
     *
     * Nenhum `<form>` aqui, e nem os envios escondidos que o checklist usa: o
     * upload vai por `fetch`, como os anexos de cobrança e de conta a pagar
     * (`components/anexos-modal`). O motivo está no `anexarImagem` do
     * controller — recarregar a tela ao colar um print descartaria o comentário
     * que ainda está sendo escrito, no mesmo modal, para explicá-lo.
     *
     * `$somenteLeitura` é o histórico: tarefa encerrada se lê, não se anexa. Lá
     * a lista sai pronta do Blade, sem Alpine nenhum — a página do histórico
     * não tem por que carregar um ouvinte de colar.
     */
    $somenteLeitura ??= false;
    $imagens = $tarefa->imagens;
@endphp

@if ($somenteLeitura)
    @if ($imagens->isNotEmpty())
        <div class="pt-4 border-t border-rule">
            <div class="flex items-center gap-2 mb-2.5">
                <h4 class="flex-1 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Imagens</h4>
                <span class="font-mono text-[10.5px] text-ink-mute">{{ $imagens->count() }}</span>
            </div>

            <div class="grid grid-cols-4 gap-1.5">
                @foreach ($imagens as $imagem)
                    <a href="{{ $imagem->url }}" target="_blank" rel="noopener"
                       title="{{ $imagem->nome_original }} · {{ $imagem->tamanho_formatado }} · {{ $imagem->autor_nome }}"
                       class="block aspect-[4/3] rounded-[5px] border border-line bg-surface overflow-hidden
                              transition hover:border-brand">
                        <img src="{{ $imagem->url }}" alt="{{ $imagem->nome_original }}"
                             loading="lazy" class="h-full w-full object-cover">
                    </a>
                @endforeach
            </div>
        </div>
    @endif
@else
    {{--
        O componente mora no `<script>` do quadro (`index.blade.php`), e não
        aqui dentro do atributo: uma aspa dupla num comentário de código fecha o
        atributo no meio, o Alpine recebe JavaScript truncado e o bloco morre em
        silêncio. É a mesma armadilha que o checklist documenta.
    --}}
    <div class="pt-4 border-t border-rule"
         x-data="imagensDaTarefa({{ $tarefa->id }}, @js($imagens))"
         @paste.window="colar($event)">

        <div class="flex items-center gap-2 mb-2.5">
            <h4 class="flex-1 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Imagens</h4>

            {{-- A contagem só existe quando há o que contar, como o progresso do
                 checklist: um "0" anunciaria como lacuna uma tarefa que
                 simplesmente não precisa de imagem nenhuma. --}}
            <span x-show="imagens.length" x-cloak x-text="imagens.length"
                  class="font-mono text-[10.5px] text-ink-mute"></span>
        </div>

        <div x-show="imagens.length" x-cloak class="grid grid-cols-4 gap-1.5">
            <template x-for="imagem in imagens" :key="imagem.id">
                <div class="group relative aspect-[4/3] rounded-[5px] border border-line bg-surface overflow-hidden">
                    {{-- Abre em aba nova, e não num visualizador próprio: o
                         painel do modal tem `transform`, que faz de qualquer
                         `position:fixed` dentro dele um prisioneiro da caixa de
                         620px — a imagem ampliada sairia menor que a miniatura.
                         A aba do navegador já traz zoom, salvar e girar. --}}
                    <a :href="imagem.url" target="_blank" rel="noopener"
                       :title="imagem.nome_original + ' · ' + imagem.tamanho_formatado + ' · ' + imagem.autor_nome"
                       class="block h-full w-full transition group-hover:opacity-90">
                        <img :src="imagem.url" :alt="imagem.nome_original" loading="lazy"
                             class="h-full w-full object-cover">
                    </a>

                    {{-- Só quem anexou apaga, e a mesma regra vale no servidor:
                         o botão some, a rota recusa. Ele aparece no hover pelo
                         mesmo motivo da lixeira do checklist — doze lixeiras
                         acesas sobre doze miniaturas disputam a leitura com o
                         que se veio ver. --}}
                    <template x-if="imagem.autor_id === {{ auth()->id() ?? 'null' }}">
                        <button type="button" @click="remover(imagem)"
                                title="Remover imagem" aria-label="Remover imagem"
                                class="absolute top-1 right-1 h-5 w-5 rounded-badge flex items-center justify-center
                                       text-white opacity-0 group-hover:opacity-100 focus:opacity-100 transition"
                                style="background: rgb(0 0 0 / 0.55)">
                            <span class="block h-[11px] w-[11px]"><x-nav-icon name="trash" :peso="1.9" /></span>
                        </button>
                    </template>
                </div>
            </template>
        </div>

        {{-- O vazio é dito, e não deduzido de um espaço em branco — mesma
             escolha da conversa. --}}
        <p x-show="! imagens.length" x-cloak
           class="px-3 py-3 rounded-[5px] border border-dashed border-line text-center text-[12px] text-ink-faint">
            Nenhuma imagem ainda — na revisão, um print costuma encerrar a dúvida que três respostas não encerram.
        </p>

        <div class="mt-1.5 flex flex-wrap items-center gap-2">
            {{--
                O `input` não tem `name` de propósito.

                Ele vive DENTRO do formulário da tarefa, que não é multipart —
                com nome, ele entraria no envio do salvar como um campo vazio a
                mais, sem nunca carregar arquivo nenhum. Sem nome, não é
                enviado por ninguém: quem lê os arquivos é o `escolher()`.
            --}}
            <label class="shrink-0 h-[26px] px-2.5 rounded-control border border-btn-line flex items-center gap-1.5
                          text-[12px] font-medium text-ink-dim cursor-pointer transition hover:text-ink"
                   :class="enviando && 'opacity-50 pointer-events-none'">
                <span class="h-[13px] w-[13px]"><x-nav-icon name="imagem" :peso="1.8" /></span>
                <span x-text="enviando ? 'Enviando…' : 'Anexar imagem'">Anexar imagem</span>
                <input type="file" accept="image/png,image/jpeg,image/gif,image/webp" multiple
                       class="sr-only" @change="escolher($event)" :disabled="enviando">
            </label>

            <p class="min-w-0 flex-1 text-[11.5px] text-ink-faint">
                Ou cole com <strong class="font-semibold text-ink-mute">Ctrl+V</strong> com a tarefa aberta.
                Até três por vez.
            </p>
        </div>

        {{-- A recusa é dita inteira ou não é dita: mensagem de erro pela metade
             manda a pessoa tentar de novo sem saber o que mudar. --}}
        <p x-show="erro" x-cloak x-text="erro" class="mt-1.5 text-[11.5px] leading-[1.45] text-crit"></p>
    </div>
@endif
