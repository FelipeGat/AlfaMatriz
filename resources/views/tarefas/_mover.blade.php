@php
    /**
     * O menu "Mover ▾" do card: uma LISTA DE BOTÕES, um por destino.
     *
     * Era um `<select>` com um textarea condicional embaixo e um "Confirmar" no
     * pé — o menu tentava ser o formulário inteiro. Três problemas nisso: o
     * select esconde os destinos até ser aberto (e são três ou quatro, não
     * trinta); "Confirmar" é o que se aperta sem ler; e o texto do motivo era
     * pedido ali, longe da coluna de destino, duplicando o painel que o quadro
     * já tem.
     *
     * Agora cada destino é um botão que só faz uma coisa: abrir o painel de
     * motivo (`abrirPendente`, no `index.blade.php`), que é quem nomeia o
     * resultado no próprio botão — "Subiu para produção", "Devolver para
     * correção". Onde a transição não pede texto, o painel confirma e envia
     * mesmo assim: o gesto é sempre o mesmo, o que muda é o que ele pergunta.
     *
     * Medidas do `design/AlfaMatriz Tarefas.dc.html`: item de 30px, raio 4px,
     * ponto de 7px na cor da etapa de DESTINO, e "pede motivo" em mono 9px à
     * direita quando a transição cobra texto.
     *
     * Espera: $tarefa, $transicoes.
     */
@endphp

@if (! empty($transicoes) || ! $tarefa->estaBloqueada())
    <div x-show="menuAberto" x-cloak @click.stop
         class="mt-[9px] pt-[9px] border-t border-rule flex flex-col gap-1"
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 -translate-y-1">

        @if (! empty($transicoes))
            <p class="mb-0.5 font-mono text-[9.5px] uppercase tracking-[0.1em] text-ink-faint">Mover para</p>

            {{--
                UM formulário para o menu inteiro, e não um por destino: entre
                os destinos que movem na hora só muda o `status`, e ele viaja
                no `value` do botão que enviou. Cada `<form>` pagava cabeçalho,
                CSRF e `de_status` próprios — com o movimento livre (US-079)
                listando o quadro inteiro no menu de quem triaga, essa
                repetição em 120 cards estourava sozinha o orçamento de peso
                do quadro (`QuadroLeveTest`).

                `contents` porque o layout é do pai: os botões continuam
                filhos do flex-col, para o `gap` valer entre eles.
            --}}
            <form method="POST" action="{{ route('tarefas.mover', $tarefa) }}" class="contents">
                @csrf
                {{-- A etapa que o card tinha quando a tela foi montada: se
                     alguém moveu enquanto o menu estava aberto, o envio é
                     recusado em vez de sobrescrever (AC-208). --}}
                <input type="hidden" name="de_status" value="{{ $tarefa->status }}">

            @foreach ($transicoes as $destino)
                @php
                    /**
                     * Este destino abre o painel de motivo?
                     *
                     * É a mesma pergunta que o `painelDe` do protótipo faz, e a
                     * resposta manda em DUAS coisas ao mesmo tempo: se o botão
                     * abre o painel ou move na hora, e se ele anuncia "pede
                     * motivo". As duas precisam concordar — um destino que diz
                     * "pede motivo" e move em silêncio, ou que não diz nada e
                     * abre um painel, ensina a não ler o rótulo.
                     *
                     * Voltar para a bancada só entra quando a tarefa vem de um
                     * PORTÃO: aí é reprovação, e o texto é obrigatório. Do
                     * Backlog é literalmente começar a trabalhar, e um painel
                     * ali pediria justificativa para pegar a própria tarefa.
                     *
                     * `pronta_producao` entra mesmo com o texto OPCIONAL: o
                     * painel dela carrega o "Validado no staging", e sem esse
                     * carimbo o motor recusa a passagem. Mover na hora daria
                     * uma recusa que a tela nunca deu chance de evitar.
                     */
                    $abrePainel = match ($destino) {
                        'em_desenvolvimento' => in_array($tarefa->status, \App\Models\Tarefa::PORTOES, true),
                        'cancelada', 'concluida', 'pronta_producao' => true,
                        // Os portões de exame abrem painel para oferecer o
                        // "quem revisa / quem testa" (US-087) — aqui e no
                        // arrasto (`etapasComTexto`), que na primeira versão
                        // passava reto e se lia como feature que não existe.
                        'em_revisao', 'em_staging' => $tarefa->tipo === 'desenvolvimento',
                        default => false,
                    };

                    // O aviso à direita do item diz o que o painel vai pedir —
                    // e apontar não é motivo: nos portões de exame ele oferece
                    // uma escolha opcional, não cobra um texto.
                    $dica = in_array($destino, \App\Models\Tarefa::PORTOES_DE_EXAME, true)
                        ? 'apontar quem'
                        : 'pede motivo';

                    /**
                     * O nome do destino, e a exceção que o protótipo faz.
                     *
                     * Vindo de um PORTÃO, "Em andamento" não descreve o que o
                     * clique faz: a tarefa não está avançando para a bancada,
                     * está sendo REPROVADA e devolvida. Um menu que chama as
                     * duas coisas pelo mesmo nome esconde a única que tem
                     * consequência — e o card do outro lado vai amanhecer com
                     * uma tarja que ninguém acha que pediu.
                     */
                    $rotulo = $destino === 'em_desenvolvimento'
                            && in_array($tarefa->status, \App\Models\Tarefa::PORTOES, true)
                        ? 'Devolver para correção'
                        : \App\Models\Tarefa::rotuloDaEtapa($destino);

                    $linha = 'flex items-center gap-2 w-full h-[30px] px-2 rounded-tile border border-btn-line'
                        .' bg-transparent text-ink text-[12.5px] text-left transition hover:border-brand/50';
                @endphp

                {{--
                    Destino que NÃO abre painel move na hora — é o `submit` do
                    formulário único, levando o próprio destino no `value`.

                    Antes todos chamavam `abrirPendente`, e para os que não têm
                    receita — Em staging, Backlog, Aberta, Em revisão — ele saía
                    pelo `if (! receita) return`: o clique não fazia nada, em
                    silêncio. O que abre painel é `type="button"` DENTRO do
                    mesmo formulário, justamente para não enviá-lo.
                --}}
                @if ($abrePainel)
                    <button type="button" class="{{ $linha }}"
                            @click.stop="abrirPendente({{ $tarefa->id }}, '{{ $destino }}', '{{ $tarefa->status }}', '{{ $tarefa->tipo }}')">
                        <span class="h-[7px] w-[7px] shrink-0 rounded-full"
                              style="background: rgb(var(--{{ \App\Models\Tarefa::corDaEtapa($destino) }}))"></span>

                        <span class="flex-1 min-w-0 truncate">{{ $rotulo }}</span>

                        <span class="shrink-0 font-mono text-[9px] uppercase tracking-[0.06em] text-ink-faint">
                            {{ $dica }}
                        </span>
                    </button>
                @else
                    <button type="submit" name="status" value="{{ $destino }}" class="{{ $linha }}">
                        <span class="h-[7px] w-[7px] shrink-0 rounded-full"
                              style="background: rgb(var(--{{ \App\Models\Tarefa::corDaEtapa($destino) }}))"></span>

                        <span class="flex-1 min-w-0 truncate">{{ $rotulo }}</span>
                    </button>
                @endif
            @endforeach
            </form>
        @endif

        {{--
            Bloquear fecha a lista, e não entra nela: travar NÃO é mover — a
            tarefa fica na etapa em que está. Listá-lo junto dos destinos faria
            o menu voltar a ensinar que ela muda de lugar quando trava, que é
            exatamente a ideia que a tarja no card veio desfazer. Daí o âmbar e
            a borda âmbar, separando-o da lista sem precisar de um título.

            Antes havia aqui um formulário de bloqueio SEMPRE ABERTO, com
            textarea e botão próprios: o menu perguntava o motivo do bloqueio a
            quem tinha aberto o menu para mover. O texto agora é pedido pelo
            painel, no mesmo lugar em que todos os outros são.
        --}}
        @unless ($tarefa->estaBloqueada())
            <button type="button" @click.stop="abrirPendente({{ $tarefa->id }}, 'bloqueio', '{{ $tarefa->status }}', '{{ $tarefa->tipo }}')"
                    class="flex items-center gap-2 w-full h-[30px] px-2 rounded-tile border
                           text-[12.5px] font-semibold text-left transition hover:brightness-110"
                    style="border-color: var(--warn-line); background: var(--warn-tint); color: rgb(var(--warn))">
                <span class="h-3 w-3 shrink-0"><x-nav-icon name="cadeado-fechado" :peso="1.8" /></span>
                Marcar como bloqueada
            </button>
        @endunless
    </div>
@endif
