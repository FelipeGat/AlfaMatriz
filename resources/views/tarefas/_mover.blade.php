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
    <div class="mt-2 pt-2 border-t border-rule" @click.stop
         x-data="{ transicoesDoCard: {{ Illuminate\Support\Js::from($transicoes) }} }">
        <button type="button" @click="menuAberto = ! menuAberto"
                class="font-mono text-[10.5px] uppercase tracking-caps text-ink-mute hover:text-brand transition">
            Mover ▾
        </button>

        <form x-show="menuAberto" x-cloak method="POST"
              action="{{ route('tarefas.mover', $tarefa) }}" class="mt-2 space-y-2">
            @csrf
            <select name="status" x-model="destino"
                    class="w-full h-8 text-[12px] rounded-control bg-input border-line text-ink">
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

            <button type="submit"
                    class="w-full h-8 rounded-control bg-brand text-on-brand font-semibold text-[12px] hover:bg-brand-bright transition">
                Confirmar
            </button>
        </form>
    </div>
@endif
