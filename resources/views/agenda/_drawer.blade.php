{{--
    O drawer de um dia: tudo o que há nele, mais a carga por pessoa.

    Ele é `fixed` e vive no fim da tela, fora de qualquer coluna: a coluna da
    Semana tem `overflow-y-auto` para rolar por dentro, e um painel nascido lá
    dentro seria cortado pela borda dela em vez de cobrir a página.

    `z-[60]` no véu e `z-[61]` no painel, acima do `z-40` da sidebar: ela é
    `sticky`, e sticky cria contexto de empilhamento mesmo com `z-index:auto` —
    sem um número explícito aqui, o drawer seria pintado ATRÁS do menu.
--}}
<template x-if="drawer.aberto">
    <div>
        <div @click="drawer.aberto = false" class="fixed inset-0 z-[60] bg-black/35"></div>

        <div class="fixed inset-y-0 right-0 z-[61] flex w-[340px] flex-col border-l border-line bg-panel shadow-[-24px_0_48px_-24px_rgba(0,0,0,0.6)]"
             @keydown.escape.window="drawer.aberto = false">

            <div class="flex shrink-0 items-center gap-2.5 border-b border-line px-[18px] py-4">
                <p class="flex-1 font-display text-[15px] font-semibold text-ink" x-text="drawer.titulo"></p>
                <button type="button" @click="drawer.aberto = false" aria-label="Fechar"
                        class="flex h-[26px] w-[26px] shrink-0 items-center justify-center text-ink-mute transition hover:text-ink">
                    <span class="h-3.5 w-3.5"><x-nav-icon name="x-mark" :peso="1.7" /></span>
                </button>
            </div>

            {{-- A tira de carga: tarefa conta para o responsável, compromisso
                 para cada participante. É o dado que nem o card nem o dia
                 isolado mostram — 1 prazo + 2 reuniões pesa tanto quanto 3
                 prazos. Só aparece com mais de uma pessoa: comparar uma pessoa
                 com ela mesma não diz nada. --}}
            <template x-if="drawer.carga.length">
                <div class="flex shrink-0 flex-wrap gap-1.5 px-[18px] pb-3">
                    <template x-for="p in drawer.carga" :key="p.id">
                        <span class="flex h-[22px] items-center whitespace-nowrap rounded-full px-2 text-[11px] font-semibold"
                              :class="p.cheio ? 'text-warn' : 'text-ink-dim'"
                              :style="p.cheio
                                  ? 'background: rgb(var(--warn) / var(--tint-alpha))'
                                  : 'background: var(--chip)'"
                              x-text="`${p.nome} · ${p.qtd}`"></span>
                    </template>
                </div>
            </template>

            <div class="flex min-h-0 flex-1 flex-col gap-1.5 overflow-y-auto px-[18px] py-3">
                <template x-for="it in drawer.itens" :key="`${it.tipo}-${it.id}`">
                    <div @click="it.tipo === 'tarefa' ? abrirTarefa(it.id) : abrirCompromisso(it.id)"
                         class="cursor-pointer rounded-control px-[11px] py-2.5 transition hover:brightness-110"
                         :style="`background: ${tinteDe(it.tom)}; border-left: 2px solid ${corDe(it.tom)}`">
                        <span class="font-mono text-[9px] font-bold uppercase tracking-[0.06em]"
                              :style="`color: ${corDe(it.tom)}`" x-text="it.rotulo"></span>
                        <p class="mt-[3px] text-[13px] font-semibold text-ink" x-text="it.titulo"></p>
                        <p class="mt-[3px] text-[11.5px] text-ink-mute" x-text="it.meta"></p>
                    </div>
                </template>

                <template x-if="! drawer.itens.length">
                    <p class="my-4 text-center text-[12.5px] text-ink-faint">Nada agendado neste dia</p>
                </template>
            </div>

            <div class="shrink-0 border-t border-line px-[18px] py-3">
                <button type="button" @click="novoCompromisso(drawer.data)"
                        class="h-[34px] w-full rounded-control bg-brand text-[12.5px] font-semibold text-on-brand transition hover:bg-brand-bright">
                    + Novo compromisso
                </button>
            </div>
        </div>
    </div>
</template>
