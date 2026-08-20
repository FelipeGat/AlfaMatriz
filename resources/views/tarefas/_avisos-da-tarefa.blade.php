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
         style="background: var(--pergunta-tint); border-color: var(--pergunta-line);
                border-left-color: rgb(var(--pergunta))">
        <div class="flex items-center gap-2.5">
            <span class="h-3.5 w-3.5 shrink-0 text-pergunta"><x-nav-icon name="duvida" :peso="1.8" /></span>
            <span class="flex-1 min-w-0 text-[12.5px] font-medium text-ink">
                Aguardando resposta de {{ $tarefa->perguntaPara?->name ?? 'alguém' }}
            </span>
            <span class="shrink-0 font-sans tabular text-[10.5px] whitespace-nowrap text-pergunta">
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
         style="background: var(--retorno-tint); border-color: var(--retorno-line);
                border-left-color: rgb(var(--retorno))">
        <div class="flex items-center gap-2.5">
            <span class="h-3.5 w-3.5 shrink-0" style="color: rgb(var(--retorno))">
                <x-nav-icon name="arrow-uturn-left" :peso="1.8" />
            </span>
            <span class="flex-1 min-w-0 font-mono text-[10.5px] font-semibold uppercase tracking-[0.08em]"
                  style="color: rgb(var(--retorno))">{{ $tarefa->rotuloDoRetorno() }}</span>
        </div>

        @if (filled($tarefa->retorno_motivo))
            <p class="mt-1.5 text-[12.5px] leading-[1.45] text-ink whitespace-pre-wrap">{{ $tarefa->retorno_motivo }}</p>
        @endif

        {{-- As imagens que vieram COM esta devolução — a metade do motivo que
             o texto não carrega. Elas também estão na seção de anexos, mas lá
             misturadas ao acervo inteiro da tarefa; aqui o banner responde
             "o que reprovou" com o print ao lado da frase. Mesma grade e
             mesmo par miniatura/original dos anexos (`_anexos`). --}}
        @if ($tarefa->retornoAnexos()->isNotEmpty())
            <div class="mt-2 grid grid-cols-4 gap-1.5">
                @foreach ($tarefa->retornoAnexos() as $imagem)
                    <a href="{{ $imagem->url }}" target="_blank" rel="noopener"
                       title="{{ $imagem->nome_original }} · {{ $imagem->tamanho_formatado }} · {{ $imagem->autor_nome }}"
                       class="block aspect-[4/3] rounded-[5px] border border-line bg-surface overflow-hidden
                              transition hover:border-brand">
                        <img src="{{ $imagem->url_miniatura }}" alt="{{ $imagem->nome_original }}"
                             loading="lazy" class="h-full w-full object-cover">
                    </a>
                @endforeach
            </div>
        @endif
    </div>
@endif

@if ($tarefa->estaBloqueada())
    <div class="flex items-center gap-2.5 px-[11px] py-[9px] rounded-[5px] border border-l-2"
         style="background: var(--bloqueio-tint); border-color: var(--bloqueio-line);
                border-left-color: rgb(var(--bloqueio))">
        <span class="h-3.5 w-3.5 shrink-0" style="color: rgb(var(--bloqueio))">
            <x-nav-icon name="cadeado-fechado" :peso="1.8" />
        </span>
        <span class="flex-1 min-w-0 text-[12.5px] text-ink">{{ $tarefa->bloqueio_motivo }}</span>
        <span class="shrink-0 font-sans tabular text-[10.5px] font-semibold whitespace-nowrap"
              style="color: rgb(var(--bloqueio))">{{ $tarefa->bloqueadaHa() }}</span>

        {{-- Destravar é envio próprio, e o formulário da tarefa não pode aninhar
             outro: o `form` aponta para fora, como o corrigir e o apagar da
             conversa. --}}
        <button type="submit" form="bloquear-tarefa-{{ $tarefa->id }}"
                class="shrink-0 h-6 px-2.5 rounded-tile border text-[11.5px] font-semibold transition hover:bg-chip"
                style="border-color: var(--bloqueio-line); color: rgb(var(--bloqueio))">
            Destravar
        </button>
    </div>
@endif

{{--
    O quarto banner: o veredito do portão (US-084). Aparece nas duas colunas
    que esperam validação — staging e produção — e responde "o que falta para
    esta tarefa andar": aguardando, aprovado por alguém, reprovado por alguém.
    Os botões registram o veredito sem mover o card, pelos envios de
    `_checklist-envios`.

    O texto troca de ambiente porque os dois não são a mesma notícia: reprovar
    no staging atrasa a entrega, reprovar em produção significa que o defeito
    está com o cliente agora — e uma frase só para os dois faria a segunda ser
    lida como a primeira.

    Some quando bloqueada: travada, o teste não é o assunto — e o banner do
    bloqueio já está dizendo o que é.
--}}
@if ($tarefa->tipo === 'desenvolvimento'
    && in_array($tarefa->status, \App\Models\Tarefa::PORTOES_DE_VEREDITO, true)
    && ! $tarefa->estaBloqueada())
    @php
        $noAr = $tarefa->status === 'em_producao';
        $testeDaPassagem = $tarefa->testeDestaPassagem();
        // A espera é o PORTÃO DE EXAME — a mesma notícia da faixa "Teste com
        // Fulano" do card, e por isso a mesma cor. Reprovado usa a cor do
        // RETORNO, porque é exatamente o que a reprovação produz: a tarefa
        // volta. Nenhum dos três empresta tom de outra notícia (AC-358).
        $tomDoTeste = $testeDaPassagem === null ? 'exame' : ($testeDaPassagem->aprovado ? 'good' : 'retorno');

        // As frases nascem aqui, inteiras, e não montadas no meio do HTML: a
        // linha quebra em duas no card, e um `@if` no meio dela entregaria à
        // busca por texto (e ao leitor de tela) duas metades de frase.
        $emQueVersao = $tarefa->versao_producao ? ' em '.$tarefa->versao_producao : '';

        $esperando = $noAr
            ? ($tarefa->interlocutor
                ? 'No ar'.$emQueVersao.', aguardando a conferência de '.$tarefa->interlocutor->name
                : 'No ar'.$emQueVersao.', aguardando alguém conferir')
            : ($tarefa->interlocutor
                ? 'Na main, aguardando o teste de '.$tarefa->interlocutor->name
                : 'Na main, aguardando o teste do staging');

        $veredito = $noAr
            ? 'Produção '.($testeDaPassagem?->aprovado ? 'aprovada' : 'reprovada')
            : 'Staging '.($testeDaPassagem?->aprovado ? 'aprovado' : 'reprovado');
    @endphp

    <div x-data="{ reprovando: false }" class="px-[11px] py-[9px] rounded-[5px] border border-l-2"
         style="background: var(--{{ $tomDoTeste }}-tint);
                border-color: var(--{{ $tomDoTeste }}-line);
                border-left-color: rgb(var(--{{ $tomDoTeste }}))">
        <div class="flex items-center gap-2.5">
            <span class="h-3.5 w-3.5 shrink-0" style="color: rgb(var(--{{ $tomDoTeste }}))">
                <x-nav-icon :name="$testeDaPassagem?->aprovado ? 'check-circle' : 'alert-triangle'" :peso="1.8" />
            </span>
            <span class="flex-1 min-w-0 text-[12.5px] font-medium text-ink">
                @if ($testeDaPassagem === null)
                    {{-- O examinador apontado no movimento (US-087) dá nome à
                         espera: "aguardando a conferência de Fulano" diz quem o
                         quadro está esperando — sem apontado, a coluna é fila
                         e a espera é de quem chegar primeiro. Um eco só: a
                         frase quebrada em duas linhas rende com quebra no
                         meio, e a busca por texto (e o leitor de tela) veem
                         duas metades. --}}
                    {{ $esperando }}
                @else
                    {{ $veredito }} por {{ $testeDaPassagem->autor?->name ?? 'alguém' }}
                @endif
            </span>
            @if ($testeDaPassagem !== null)
                <span class="shrink-0 font-sans tabular text-[10.5px] font-semibold whitespace-nowrap"
                      style="color: rgb(var(--{{ $tomDoTeste }}))">
                    {{ \App\Models\Tarefa::duracaoCurta((int) $testeDaPassagem->created_at->diffInSeconds(now())) }}
                </span>
            @endif

            <button type="submit" form="testar-aprovar-{{ $tarefa->id }}"
                    class="shrink-0 h-6 px-2.5 rounded-tile border text-[11.5px] font-semibold transition hover:bg-chip"
                    style="border-color: var(--good-line); color: rgb(var(--good))">
                Aprovar
            </button>
            <button type="button" @click="reprovando = ! reprovando"
                    class="shrink-0 h-6 px-2.5 rounded-tile border text-[11.5px] font-semibold transition hover:bg-chip"
                    style="border-color: var(--retorno-line); color: rgb(var(--retorno))">
                Reprovar
            </button>
        </div>

        {{-- As notas da reprovação registrada, por extenso, como o motivo do
             retorno: é aqui que o dev vem LER o que falhou. --}}
        @if ($testeDaPassagem !== null && ! $testeDaPassagem->aprovado && filled($testeDaPassagem->notas))
            <p class="mt-1.5 text-[12.5px] leading-[1.45] text-ink whitespace-pre-wrap">{{ $testeDaPassagem->notas }}</p>
        @endif

        {{-- Reprovar exige dizer o quê (o motor recusa sem notas): o botão
             revela o campo em vez de enviar, como o bloqueio do rodapé. --}}
        <div x-show="reprovando" x-cloak class="mt-2 flex items-end gap-2">
            <div class="flex-1 min-w-0">
                <label for="teste-notas-{{ $tarefa->id }}" class="block mb-[5px] text-[12px] font-medium text-ink-dim">
                    O que reprovou?
                </label>
                <textarea id="teste-notas-{{ $tarefa->id }}" name="notas" form="testar-reprovar-{{ $tarefa->id }}"
                          rows="2" required placeholder="{{ $noAr ? 'O que falhou em produção…' : 'O que falhou no staging…' }}"
                          class="block w-full px-2.5 py-2 rounded-control bg-input border-line text-ink
                                 text-[12.5px] leading-[1.45] resize-y"></textarea>
            </div>
            <button type="submit" form="testar-reprovar-{{ $tarefa->id }}"
                    class="shrink-0 h-[34px] px-3 rounded-control border text-[12.5px] font-semibold transition hover:bg-chip"
                    style="border-color: var(--retorno-line); color: rgb(var(--retorno))">
                Reprovar teste
            </button>
        </div>
    </div>
@endif
