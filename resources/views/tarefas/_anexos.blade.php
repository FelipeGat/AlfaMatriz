@php
    /**
     * Os anexos da tarefa (US-064).
     *
     * "O botão saiu do lugar" é uma frase que só quem viu a tela entende. Na
     * revisão — que é onde a feature nasceu — descrever um defeito por escrito
     * custa rodadas que uma captura encerra de uma vez. O log do erro e a
     * planilha do cliente entraram depois, pelo mesmo motivo e no mesmo gesto.
     *
     * Duas formas na mesma seção, e não duas seções: figura vira miniatura,
     * porque um print reduzido a uma linha de texto perde justamente o que veio
     * mostrar; arquivo vira linha, porque um log de 800 KB não tem miniatura
     * que se olhe. Separá-los em seções distintas custaria uma terceira caixa
     * num modal de 620px para dividir coisas que se anexam pela mesma razão.
     *
     * Nenhum `<form>` aqui, e nem os envios escondidos que o checklist usa: o
     * upload vai por `fetch`, como os anexos de cobrança e de conta a pagar
     * (`components/anexos-modal`). O motivo está no `anexarArquivo` do
     * controller — recarregar a tela ao colar um print descartaria o comentário
     * que ainda está sendo escrito, no mesmo modal, para explicá-lo.
     *
     * `$somenteLeitura` é o histórico: tarefa encerrada se lê, não se anexa. Lá
     * a lista sai pronta do Blade, sem Alpine nenhum — a página do histórico
     * não tem por que carregar um ouvinte de colar.
     *
     * `$tarefa` nulo é a CRIAÇÃO (AC-234), e é o único modo em que a seção não
     * fala com o servidor: sem id, não há a que prender arquivo nenhum. Os
     * arquivos ficam na tela e viajam no mesmo POST que cria a tarefa, pelo
     * próprio `input`, que aqui é a carga do formulário — ver `sincronizar` no
     * `anexosDaTarefa`.
     */
    $somenteLeitura ??= false;
    $tarefa ??= null;
    $criacao = ! $tarefa;
    $anexos = $tarefa?->anexos ?? collect();

    // A separação é a mesma nos dois modos; no editável ela se repete em
    // JavaScript porque lá a lista muda sem recarregar a página.
    [$imagens, $arquivos] = $anexos->partition->eh_imagem;
@endphp

@if ($somenteLeitura)
    @if ($anexos->isNotEmpty())
        <div class="pt-4 border-t border-rule">
            <div class="flex items-center gap-2 mb-2.5">
                <h4 class="flex-1 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Anexos</h4>
                <span class="font-mono text-[10.5px] text-ink-mute">{{ $anexos->count() }}</span>
            </div>

            @if ($imagens->isNotEmpty())
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
            @endif

            @if ($arquivos->isNotEmpty())
                <div class="{{ $imagens->isNotEmpty() ? 'mt-1.5' : '' }} flex flex-col gap-1">
                    @foreach ($arquivos as $arquivo)
                        <a href="{{ $arquivo->url }}"
                           title="{{ $arquivo->nome_original }} · {{ $arquivo->tamanho_formatado }} · {{ $arquivo->autor_nome }}"
                           class="flex items-center gap-1.5 px-2 h-[26px] rounded-[5px] border border-line bg-surface
                                  transition hover:border-brand">
                            <span class="shrink-0 h-[13px] w-[13px] text-ink-faint"><x-nav-icon name="document" :peso="1.8" /></span>
                            {{-- `min-w-0` junto do `truncate`: sem ele o nome
                                 longo não corta, ele empurra o tamanho para
                                 fora da linha. --}}
                            <span class="min-w-0 flex-1 truncate text-[12px] text-ink-dim">{{ $arquivo->nome_original }}</span>
                            <span class="shrink-0 font-mono text-[10.5px] text-ink-faint">{{ $arquivo->tamanho_formatado }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
@else
    {{--
        O componente mora no `<script>` do quadro (`index.blade.php`), e não
        aqui dentro do atributo: uma aspa dupla num comentário de código fecha o
        atributo no meio, o Alpine recebe JavaScript truncado e o bloco morre em
        silêncio. É a mesma armadilha que o checklist documenta.
    --}}
    {{-- `@reset.window` e não `@reset`: o evento nasce no `<form>`, que é o PAI
         desta caixa, e sobe daqui para fora — um ouvinte pendurado aqui dentro
         nunca o veria passar. Quem o dispara é o `form.reset()` que o quadro
         chama depois de criar a tarefa; sem ele, a lista da tela sobreviveria à
         tarefa que acabou de nascer e a próxima começaria com os anexos da
         anterior. --}}
    <div class="pt-4 border-t border-rule"
         x-data="anexosDaTarefa({{ $tarefa?->id ?? 'null' }}, @js($anexos))"
         @paste.window="colar($event)"
         @reset.window="esvaziar($event)">

        <div class="flex items-center gap-2 mb-2.5">
            <h4 class="flex-1 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Anexos</h4>

            {{-- A contagem só existe quando há o que contar, como o progresso do
                 checklist: um "0" anunciaria como lacuna uma tarefa que
                 simplesmente não precisa de anexo nenhum. --}}
            <span x-show="anexos.length" x-cloak x-text="anexos.length"
                  class="font-mono text-[10.5px] text-ink-mute"></span>
        </div>

        <div x-show="imagens.length" x-cloak class="grid grid-cols-4 gap-1.5">
            <template x-for="anexo in imagens" :key="anexo.id">
                <div class="group relative aspect-[4/3] rounded-[5px] border border-line bg-surface overflow-hidden">
                    {{-- Abre em aba nova, e não num visualizador próprio: o
                         painel do modal tem `transform`, que faz de qualquer
                         `position:fixed` dentro dele um prisioneiro da caixa de
                         620px — a imagem ampliada sairia menor que a miniatura.
                         A aba do navegador já traz zoom, salvar e girar. --}}
                    <a :href="anexo.url" target="_blank" rel="noopener"
                       :title="anexo.nome_original + ' · ' + anexo.tamanho_formatado + ' · ' + anexo.autor_nome"
                       class="block h-full w-full transition group-hover:opacity-90">
                        <img :src="anexo.url" :alt="anexo.nome_original" loading="lazy"
                             class="h-full w-full object-cover">
                    </a>

                    {{-- Só quem anexou apaga, e a mesma regra vale no servidor:
                         o botão some, a rota recusa. Ele aparece no hover pelo
                         mesmo motivo da lixeira do checklist — doze lixeiras
                         acesas sobre doze miniaturas disputam a leitura com o
                         que se veio ver. --}}
                    <template x-if="anexo.autor_id === {{ auth()->id() ?? 'null' }}">
                        <button type="button" @click="remover(anexo)"
                                title="Remover anexo" aria-label="Remover anexo"
                                class="absolute top-1 right-1 h-5 w-5 rounded-badge flex items-center justify-center
                                       text-white opacity-0 group-hover:opacity-100 focus:opacity-100 transition"
                                style="background: rgb(0 0 0 / 0.55)">
                            <span class="block h-[11px] w-[11px]"><x-nav-icon name="trash" :peso="1.9" /></span>
                        </button>
                    </template>
                </div>
            </template>
        </div>

        {{-- O arquivo que não é figura: linha com ícone, nome e tamanho. Ele
             baixa em vez de abrir — quem decide isso é a rota (`verAnexo`), não
             o link. --}}
        <div x-show="arquivos.length" x-cloak
             :class="imagens.length && 'mt-1.5'" class="flex flex-col gap-1">
            <template x-for="anexo in arquivos" :key="anexo.id">
                <div class="group flex items-center gap-1.5 px-2 h-[26px] rounded-[5px] border border-line bg-surface
                            transition hover:border-brand">
                    <span class="shrink-0 h-[13px] w-[13px] text-ink-faint"><x-nav-icon name="document" :peso="1.8" /></span>

                    <a :href="anexo.url"
                       :title="anexo.nome_original + ' · ' + anexo.tamanho_formatado + ' · ' + anexo.autor_nome"
                       class="min-w-0 flex-1 truncate text-[12px] text-ink-dim transition hover:text-ink"
                       x-text="anexo.nome_original"></a>

                    <span class="shrink-0 font-mono text-[10.5px] text-ink-faint" x-text="anexo.tamanho_formatado"></span>

                    {{-- A lixeira ocupa o lugar mesmo invisível (`invisible`, e
                         não `hidden`): aparecendo só no hover ela empurraria o
                         tamanho para a esquerda a cada passada do mouse, e uma
                         lista inteira dançaria linha a linha. --}}
                    <template x-if="anexo.autor_id === {{ auth()->id() ?? 'null' }}">
                        <button type="button" @click="remover(anexo)"
                                title="Remover anexo" aria-label="Remover anexo"
                                class="shrink-0 h-[13px] w-[13px] text-ink-faint invisible
                                       group-hover:visible focus:visible transition hover:text-crit">
                            <x-nav-icon name="trash" :peso="1.9" />
                        </button>
                    </template>
                </div>
            </template>
        </div>

        {{-- O vazio é dito, e não deduzido de um espaço em branco — mesma
             escolha da conversa. Na criação ele diz outra coisa: ali a frase
             sobre a revisão falaria de uma conversa que ainda não começou, e o
             que importa é que a prova pode entrar agora, sem um segundo gesto
             depois de salvar. --}}
        <p x-show="! anexos.length" x-cloak
           class="px-3 py-3 rounded-[5px] border border-dashed border-line text-center text-[12px] text-ink-faint">
            @if ($criacao)
                Nenhum anexo ainda — o print do defeito ou o log do erro pode entrar já aqui, e vai junto ao salvar.
            @else
                Nenhum anexo ainda — na revisão, um print ou o log do erro costuma encerrar a dúvida que três respostas não encerram.
            @endif
        </p>

        <div class="mt-1.5 flex flex-wrap items-center gap-2">
            {{--
                O `name` do `input` é o que separa os dois modos, e é a linha
                mais fácil de quebrar em silêncio desta seção.

                NA EDIÇÃO ele não tem nome de propósito: vive dentro do
                formulário da tarefa, que não é multipart, e com nome entraria
                no envio do Salvar como um campo vazio a mais, sem nunca
                carregar arquivo nenhum. Quem lê os arquivos ali é o
                `escolher()`, que os manda por `fetch`.

                NA CRIAÇÃO ele É a carga: não há id para o `fetch` mirar, então
                os arquivos viajam no POST que cria a tarefa, por este campo. O
                `sincronizar()` reescreve o que ele carrega a cada arquivo que
                entra ou sai — inclusive os colados, que nunca passaram pelo
                seletor. Tirar o nome daqui faz a tarefa nascer sem os anexos
                que a tela mostrou, sem erro nenhum.
            --}}
            <label class="shrink-0 h-[26px] px-2.5 rounded-control border border-btn-line flex items-center gap-1.5
                          text-[12px] font-medium text-ink-dim cursor-pointer transition hover:text-ink"
                   :class="enviando && 'opacity-50 pointer-events-none'">
                <span class="h-[13px] w-[13px]"><x-nav-icon name="paperclip" :peso="1.8" /></span>
                <span x-text="rotuloDoBotao">Anexar arquivo</span>
                <input type="file" accept="{{ \App\Models\TarefaAnexo::ACEITE_DO_SELETOR }}" multiple
                       @if ($criacao) name="anexos[]" @endif x-ref="carga"
                       class="sr-only" @change="escolher($event)" :disabled="enviando">
            </label>

            <p class="min-w-0 flex-1 text-[11.5px] text-ink-faint">
                @if ($criacao)
                    Imagem, PDF, log ou planilha — vão junto ao salvar. Print cola com
                    <strong class="font-semibold text-ink-mute">Ctrl+V</strong>, aqui mesmo.
                @else
                    Imagem, PDF, log ou planilha. Print cola com
                    <strong class="font-semibold text-ink-mute">Ctrl+V</strong>, com a tarefa aberta.
                @endif
            </p>
        </div>

        {{-- A recusa é dita inteira ou não é dita: mensagem de erro pela metade
             manda a pessoa tentar de novo sem saber o que mudar. --}}
        <p x-show="erro" x-cloak x-text="erro" class="mt-1.5 text-[11.5px] leading-[1.45] text-crit"></p>
    </div>
@endif
