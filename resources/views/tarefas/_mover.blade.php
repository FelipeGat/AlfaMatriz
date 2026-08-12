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

            @foreach ($transicoes as $destino)
                @php
                    /**
                     * Esta transição cobra texto?
                     *
                     * Voltar para a bancada só cobra quando a tarefa vem de um
                     * PORTÃO — aí é reprovação. Do Backlog é só começar a
                     * trabalhar, e anunciar "pede motivo" ali seria prometer uma
                     * pergunta que o painel não vai fazer.
                     */
                    $pedeMotivo = match ($destino) {
                        'em_desenvolvimento' => in_array($tarefa->status, \App\Models\Tarefa::PORTOES, true),
                        'cancelada' => true,
                        'concluida' => $tarefa->tipo === 'desenvolvimento',
                        default => false,
                    };

                    /**
                     * O nome do destino, e a exceção que o protótipo faz.
                     *
                     * Vindo de um PORTÃO, "Em andamento" não descreve o que o
                     * clique faz: a tarefa não está avançando para a bancada,
                     * está sendo REPROVADA e devolvida. Um menu que chama as
                     * duas coisas pelo mesmo nome esconde a única que tem
                     * consequência — e o card do outro lado vai amanhecer com
                     * uma tarja que ninguém acha que pediu.
                     *
                     * Vindo do Backlog é literalmente começar a trabalhar, e aí
                     * "Em andamento" é o nome certo.
                     */
                    $rotulo = $destino === 'em_desenvolvimento'
                            && in_array($tarefa->status, \App\Models\Tarefa::PORTOES, true)
                        ? 'Devolver para correção'
                        : \App\Models\Tarefa::rotuloDaEtapa($destino);
                @endphp

                <button type="button" @click.stop="abrirPendente({{ $tarefa->id }}, '{{ $destino }}', '{{ $tarefa->status }}', '{{ $tarefa->tipo }}')"
                        class="flex items-center gap-2 w-full h-[30px] px-2 rounded-tile border border-btn-line
                               bg-transparent text-ink text-[12.5px] text-left transition hover:border-brand/50">
                    <span class="h-[7px] w-[7px] shrink-0 rounded-full"
                          style="background: rgb(var(--{{ \App\Models\Tarefa::corDaEtapa($destino) }}))"></span>

                    <span class="flex-1 min-w-0 truncate">{{ $rotulo }}</span>

                    @if ($pedeMotivo)
                        <span class="shrink-0 font-mono text-[9px] uppercase tracking-[0.06em] text-ink-faint">
                            pede motivo
                        </span>
                    @endif
                </button>
            @endforeach
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
                <x-nav-icon name="cadeado-fechado" :peso="1.8" class="h-3 w-3 shrink-0" />
                Marcar como bloqueada
            </button>
        @endunless
    </div>
@endif
