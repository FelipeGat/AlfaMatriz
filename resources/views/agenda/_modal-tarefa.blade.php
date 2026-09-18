{{--
    O detalhe da tarefa aberto a partir da Agenda.

    Ele NÃO é o modal do quadro, e a diferença é o ponto: aqui a pergunta é
    sobre a DATA, e as respostas são prioridade, sistema, responsável, prazo e as
    reuniões vinculadas. Quem quer o card inteiro tem "Ver no quadro", que leva
    para lá — reproduzir o formulário de 2 MB dentro da Agenda custaria o peso
    da tela inteira para responder uma pergunta que já foi respondida.
--}}
<template x-if="detalhe.aberto">
    <div @click="detalhe.aberto = false"
         @keydown.escape.window="detalhe.aberto = false"
         class="fixed inset-0 z-[62] flex items-center justify-center bg-black/45 p-4">

        <div @click.stop
             class="w-[360px] max-w-full rounded-panel border border-line bg-panel p-5 shadow-[0_24px_60px_-20px_rgba(0,0,0,0.6)]">

            <div class="flex items-center gap-2">
                <span class="h-2 w-2 rounded-full" :style="`background: ${corDe(detalhe.tom)}`"></span>
                <span class="font-mono text-[10.5px] uppercase tracking-[0.08em]"
                      :style="`color: ${corDe(detalhe.tom)}`" x-text="detalhe.prioridade"></span>

                {{-- A marca de estado ao lado do grau, e não no lugar dele: a
                     tarefa travada continua tendo a prioridade que tem, e
                     trocar uma pela outra faria a informação sumir. --}}
                <template x-if="detalhe.marca">
                    <span class="font-mono text-[10.5px] uppercase tracking-[0.08em]"
                          :style="`color: ${corDe(detalhe.marca.tom)}`"
                          x-text="`· ${detalhe.marca.sufixo}`"></span>
                </template>
            </div>

            <p class="mt-2.5 font-display text-[16.5px] font-semibold text-ink" x-text="detalhe.titulo"></p>

            <p class="mt-2.5 text-[12.5px] text-ink-mute" x-text="`Sistema: ${detalhe.sistema}`"></p>
            <p class="mt-1 text-[12.5px] text-ink-mute" x-text="`Responsável: ${detalhe.responsavel}`"></p>
            <p class="mt-1 text-[12.5px] text-ink-mute" x-text="`Prazo: ${detalhe.prazoLegivel}`"></p>

            <template x-if="detalhe.reunioes.length">
                <div>
                    <p class="mt-3 font-mono text-[11px] uppercase tracking-[0.08em] text-ink-faint">Reuniões vinculadas</p>
                    <template x-for="r in detalhe.reunioes" :key="r.id">
                        <p class="mt-1 cursor-pointer text-[12.5px] text-brand-text hover:underline"
                           @click="abrirCompromisso(r.id)"
                           x-text="`${r.quando} · ${r.titulo}`"></p>
                    </template>
                </div>
            </template>

            {{-- Linha inteira, e acima das outras duas: reservar tempo é a ação
                 que a tela inteira existe para oferecer, e dividir a linha com
                 "Fechar" a poria em pé de igualdade com desistir. --}}
            <button type="button" @click="reservarTempo(detalhe.id)"
                    class="mt-[18px] h-[34px] w-full rounded-control border border-btn-line text-[12.5px] font-semibold text-ink transition hover:bg-chip">
                Reservar tempo
            </button>

            <div class="mt-2 flex gap-2">
                <button type="button" @click="detalhe.aberto = false"
                        class="h-[34px] flex-1 rounded-control border border-btn-line text-[12.5px] font-semibold text-ink-mute transition hover:text-ink">
                    Fechar
                </button>
                <a :href="detalhe.rotaNoQuadro"
                   class="flex h-[34px] flex-1 items-center justify-center rounded-control bg-brand text-[12.5px] font-semibold text-on-brand transition hover:bg-brand-bright">
                    Ver no quadro
                </a>
            </div>
        </div>
    </div>
</template>
