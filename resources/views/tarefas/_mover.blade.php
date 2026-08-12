@php
    /**
     * Menu "Mover ▾" do card: caminho acessível (teclado, celular) para as
     * mesmas transições que o arrastar oferece, e o único caminho para as
     * que pedem texto — ajustes necessários, cancelamento e conclusão com
     * relatório de teste (Q-013, US-037).
     */
@endphp

@if (! empty($transicoes))
    {{--
        `Js::from` e não `@json`: dentro de um atributo HTML, a primeira aspa
        dupla do JSON fecharia o `x-data` no meio, o Alpine não avaliaria nada
        e o select do menu sairia SEM OPÇÃO — o caminho acessível morreria em
        silêncio, porque a rota continua respondendo. É a mesma escolha de
        `clientes/_form.blade.php`.
    --}}
    {{--
        Só os formulários: o gatilho virou o chevron do rodapé do card
        (`_card.blade.php`), que abre e fecha o mesmo `menuAberto`. Como texto,
        ele gastava uma linha inteira do card para abrir um menu que quase
        sempre fica fechado.
    --}}
    <div x-show="menuAberto" x-cloak class="mt-2 pt-2 border-t border-rule" @click.stop
         x-data="{ transicoesDoCard: {{ Illuminate\Support\Js::from($transicoes) }} }">
        <form method="POST"
              action="{{ route('tarefas.mover', $tarefa) }}" class="mt-2 space-y-2">
            @csrf
            {{-- A etapa que o card tinha quando esta tela foi montada: se
                 alguém moveu enquanto o menu estava aberto, o envio é recusado
                 em vez de sobrescrever o movimento do outro (AC-208). --}}
            <input type="hidden" name="de_status" value="{{ $tarefa->status }}">
            {{--
                `py-0` junto com a altura fixa, como em Revendas e Clientes: o
                plugin de formulários dá ao select `padding: 8px` em cima e
                embaixo mais `line-height: 24px` — 42px de caixa. Com `h-8` e
                `box-sizing: border-box` isso não cabe, e o texto sai cortado.
            --}}
            <select name="status" x-model="destino"
                    class="w-full h-8 py-0 text-[12px] rounded-control bg-input border-line text-ink">
                <template x-for="status in transicoesDoCard" :key="status">
                    <option :value="status" x-text="rotulosStatus[status] ?? status"></option>
                </template>
            </select>

            <template x-if="destino === 'ajustes_necessarios'">
                <textarea name="motivo" rows="2" required placeholder="O que precisa ser corrigido…"
                          class="w-full text-[12px] rounded-control bg-input border-line text-ink"></textarea>
            </template>

            <template x-if="destino === 'cancelada'">
                <textarea name="motivo" rows="2" required placeholder="Motivo do cancelamento…"
                          class="w-full text-[12px] rounded-control bg-input border-line text-ink"></textarea>
            </template>

            {{--
                O relatório é pergunta do ciclo de desenvolvimento. Pedir notas
                de teste para "ligar para o fabricante" só ensinaria a escrever
                qualquer coisa no campo — e um campo que se aprende a preencher
                por obrigação deixa de valer como prova de teste em todas as
                outras tarefas.
            --}}
            @if ($tarefa->tipo === 'desenvolvimento')
                <template x-if="destino === 'concluida'">
                    <div class="space-y-2">
                        <textarea name="relatorio_notas" rows="2" required placeholder="Notas do relatório de teste…"
                                  class="w-full text-[12px] rounded-control bg-input border-line text-ink"></textarea>
                        <label class="flex items-center gap-2 text-[11.5px] text-ink-dim">
                            <input type="checkbox" name="relatorio_aprovado" value="1">
                            Relatório aprovado
                        </label>
                    </div>
                </template>
            @endif

            <button type="submit"
                    class="w-full h-8 rounded-control bg-brand text-on-brand font-semibold text-[12px] hover:bg-brand-bright transition">
                Confirmar
            </button>
        </form>

        {{--
            Bloquear tem formulário PRÓPRIO, e não uma opção do select acima:
            travar deixou de ser etapa, e listá-lo junto dos destinos faria o
            menu voltar a ensinar que a tarefa muda de lugar quando trava — que
            é exatamente a ideia que a tarja no card veio desfazer.

            Só aparece para tarefa solta: quem já está travado destrava pelo
            botão da tarja, que fica ao lado do motivo que deixou de valer.
        --}}
        @if (! $tarefa->estaBloqueada())
            <form method="POST"
                  action="{{ route('tarefas.bloquear', $tarefa) }}" class="mt-2 pt-2 border-t border-rule space-y-2">
                @csrf
                <textarea name="motivo" rows="2" required placeholder="Bloquear: esperando quem, e o quê…"
                          class="w-full text-[12px] rounded-control bg-input border-line text-ink"></textarea>
                <button type="submit"
                        class="w-full h-8 rounded-control border font-semibold text-[12px] transition hover:bg-chip"
                        style="border-color: rgb(var(--warn) / 0.45); color: rgb(var(--warn))">
                    Bloquear tarefa
                </button>
            </form>
        @endif
    </div>
@endif
