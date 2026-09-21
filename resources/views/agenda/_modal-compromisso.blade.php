{{--
    O modal de compromisso — início, término e duração.

    Uma nota sobre `color-scheme` nos `input[type=date|time]`: o repositório já a
    declara no `:root` (escuro) e no `.theme-light` (claro) em `app.css`, então
    os ícones nativos de calendário e relógio acompanham o tema sozinhos. Era a
    armadilha número um do §19 e ela já estava resolvida aqui — não repita a
    declaração no elemento, porque uma cópia local é exatamente o que deixa de
    virar quando o tema vira.
--}}
<template x-if="modal.aberto">
    <div @click="fecharModal()"
         @keydown.escape.window="fecharModal()"
         class="fixed inset-0 z-[63] flex items-center justify-center overflow-y-auto bg-black/45 p-4">

        <div @click.stop
             class="w-[400px] max-w-full rounded-panel border border-line bg-panel p-5 shadow-[0_24px_60px_-20px_rgba(0,0,0,0.6)]">

            <div class="mb-3.5 flex items-center justify-between gap-2">
                <p class="font-display text-[16px] font-semibold text-ink"
                   x-text="modal.id ? (modal.somenteLeitura ? 'Compromisso' : 'Editar compromisso') : 'Novo compromisso'"></p>

                {{-- Duplicar: só ao editar um compromisso salvo e editável. Abre
                     uma CÓPIA como compromisso novo (mesmo título, detalhes,
                     duração, participantes e vínculo), sem salvar nada — a
                     pessoa muda o dia/hora e confirma. Repetir uma reunião
                     semanal deixa de ser redigitar tudo. --}}
                <template x-if="modal.id && ! modal.somenteLeitura">
                    <button type="button" @click="duplicarCompromisso()"
                            class="h-[22px] shrink-0 rounded-full border border-btn-line px-2.5 text-[10.5px] font-semibold text-ink-mute transition hover:text-ink hover:bg-chip">
                        Duplicar
                    </button>
                </template>
            </div>

            <input type="text" x-model="modal.titulo" :disabled="modal.somenteLeitura" placeholder="Título"
                   class="h-9 w-full rounded-control border border-btn-line bg-input px-2.5 text-[13.5px] text-ink
                          placeholder:text-ink-faint focus:border-brand focus:ring-0 disabled:opacity-60">

            <p class="mb-1.5 mt-3 font-mono text-[11px] uppercase tracking-[0.08em] text-ink-faint">Início</p>
            <div class="flex gap-2">
                <input type="date" x-model="modal.data" @change="recalcular()" :disabled="modal.somenteLeitura"
                       class="h-9 flex-1 rounded-control border border-btn-line bg-input px-2.5 text-[12.5px] text-ink focus:border-brand focus:ring-0 disabled:opacity-60">
                <input type="time" x-model="modal.hora" @change="recalcular()" :disabled="modal.somenteLeitura"
                       class="h-9 w-[110px] rounded-control border border-btn-line bg-input px-2.5 text-[12.5px] text-ink focus:border-brand focus:ring-0 disabled:opacity-60">
            </div>

            <div class="mb-1.5 mt-3.5 flex items-center justify-between">
                <p class="font-mono text-[11px] uppercase tracking-[0.08em] text-ink-faint">Término</p>
                {{-- O botão-pílula alterna a FONTE DA VERDADE do término, e o
                     modo escolhido é gravado: reabrir devolve o compromisso no
                     modo em que ele foi criado. Ver a migração. --}}
                <button type="button" @click="alternarModo()" :disabled="modal.somenteLeitura"
                        class="h-[22px] rounded-full border border-btn-line px-2 text-[10.5px] font-semibold transition disabled:opacity-60"
                        :class="modal.duracao_modo ? 'bg-nav-active text-brand-text' : 'text-ink-mute hover:text-ink'"
                        x-text="modal.duracao_modo ? 'Duração' : 'Horário livre'"></button>
            </div>

            <template x-if="modal.duracao_modo">
                <div class="flex items-center gap-2">
                    <input type="number" min="0.25" step="0.25" x-model.number="modal.duracao_horas"
                           @input="recalcular()" :disabled="modal.somenteLeitura"
                           class="h-9 w-[76px] rounded-control border border-btn-line bg-input px-2.5 text-[12.5px] text-ink focus:border-brand focus:ring-0 disabled:opacity-60">
                    <span class="whitespace-nowrap text-[12.5px] text-ink-mute">horas · termina às</span>
                    {{-- O término calculado é EXIBIDO, não editável: é o que
                         torna o modo Duração honesto — mudar o início ou as
                         horas sempre recalcula, e um campo editável ao lado
                         faria a pessoa digitar num valor que a próxima tecla
                         sobrescreve. --}}
                    <span class="whitespace-nowrap font-mono text-[13px] font-semibold text-brand-text"
                          x-text="modal.hora_fim + sufixoDoDiaSeguinte()"></span>
                </div>
            </template>

            <template x-if="! modal.duracao_modo">
                <div class="flex gap-2">
                    <input type="date" x-model="modal.data_fim" :disabled="modal.somenteLeitura"
                           class="h-9 flex-1 rounded-control border border-btn-line bg-input px-2.5 text-[12.5px] text-ink focus:border-brand focus:ring-0 disabled:opacity-60">
                    <input type="time" x-model="modal.hora_fim" :disabled="modal.somenteLeitura"
                           class="h-9 w-[110px] rounded-control border border-btn-line bg-input px-2.5 text-[12.5px] text-ink focus:border-brand focus:ring-0 disabled:opacity-60">
                </div>
            </template>

            <textarea x-model="modal.descricao" :disabled="modal.somenteLeitura" rows="3"
                      placeholder="Detalhes (opcional) — pauta, link da chamada, contexto…"
                      class="mt-3.5 h-[60px] w-full resize-none rounded-control border border-btn-line bg-input px-2.5 py-2
                             text-[12.5px] text-ink placeholder:text-ink-faint focus:border-brand focus:ring-0 disabled:opacity-60"></textarea>

            <p class="mb-1.5 mt-3.5 font-mono text-[11px] uppercase tracking-[0.08em] text-ink-faint">Participantes</p>
            <div class="flex flex-wrap gap-[5px]">
                @foreach ($equipe as $pessoa)
                    {{-- Cada chip diz a carga da pessoa NAQUELE DIA ("· 2 no
                         dia") e fica âmbar com "· conflito" quando ela já tem
                         compromisso sobreposto ao intervalo. São duas perguntas
                         diferentes: a carga é contexto para decidir se vale
                         convidar; o conflito é um choque de horário. --}}
                    <button type="button" @click="alternarParticipante({{ $pessoa->id }})"
                            :disabled="modal.somenteLeitura"
                            :title="rotuloDoChip({{ $pessoa->id }}, @js($pessoa->name))"
                            class="flex h-[26px] items-center whitespace-nowrap rounded-full border px-2.5 text-[11.5px] font-semibold transition disabled:opacity-60"
                            :class="classeDoChip({{ $pessoa->id }})"
                            x-text="rotuloDoChip({{ $pessoa->id }}, @js($pessoa->name))"></button>
                @endforeach
            </div>

            <div class="mb-1.5 mt-3.5 flex items-center justify-between gap-2">
                <p class="font-mono text-[11px] uppercase tracking-[0.08em] text-ink-faint">Vincular a uma tarefa (opcional)</p>
                {{-- "Virar tarefa" só num compromisso JÁ SALVO e SEM vínculo:
                     antes de salvar não há o que converter, e com vínculo a
                     tarefa já existe — um segundo clique criaria duplicata. --}}
                <template x-if="modal.id && ! modal.tarefa_id && ! modal.somenteLeitura">
                    <button type="button" @click="virarTarefa()"
                            class="h-[22px] shrink-0 rounded-full border border-btn-line px-2 text-[10.5px] font-semibold text-brand-text transition hover:bg-chip">
                        Virar tarefa
                    </button>
                </template>
            </div>
            {{-- `py-0` junto do `h-8`: o plugin de forms do Tailwind põe 8px de
                 padding em cima e embaixo, que somados à entrelinha passam dos
                 32px da caixa — e o texto da opção saía cortado por baixo. A
                 altura é a do desenho; quem cede é o padding. --}}
            {{--
                Busca em vez de <select>: com muitas tarefas, rolar uma lista de
                centenas de opções é inviável — aqui digita-se o # ou o nome e a
                lista filtra ao vivo. Os dados já vieram na página, então o
                filtro é no navegador, sem requisição.

                O campo mostra a tarefa vinculada como PLACEHOLDER quando fechado
                (você vê o que escolheu) e vira busca ao focar. `@click.outside`
                fecha a lista; o × limpa o vínculo.
            --}}
            <div class="relative" @click.outside="modal.vinculoAberto = false">
                {{-- O `:value` mostra a tarefa vinculada como TEXTO de verdade
                     quando o campo está fechado — tinta cheia, e não o cinza de
                     placeholder que fazia a seleção parecer um palpite. Ao
                     focar, o campo esvazia para a busca; ao fechar sem escolher,
                     volta a exibir o que estava vinculado. Por isso `:value` +
                     `@input`, e não `x-model`: o valor exibido não é sempre o
                     que se digita. --}}
                <input type="text"
                       :value="modal.vinculoAberto ? modal.vinculoBusca : (modal.tarefa_id ? rotuloTarefa(modal.tarefa_id) : '')"
                       @input="modal.vinculoBusca = $event.target.value"
                       @focus="modal.vinculoBusca = ''; modal.vinculoAberto = true"
                       @click="modal.vinculoAberto = true"
                       :disabled="modal.somenteLeitura"
                       placeholder="Buscar tarefa por # ou nome…"
                       class="h-8 w-full rounded-control border border-btn-line bg-input pl-2.5 pr-7 text-[12px] text-ink placeholder:text-ink-faint focus:border-brand focus:ring-0 disabled:opacity-60">

                {{-- Limpar o vínculo. Só aparece com algo vinculado. --}}
                <button type="button" x-show="modal.tarefa_id && ! modal.somenteLeitura" x-cloak
                        @click="limparVinculo()"
                        class="absolute right-1.5 top-1/2 -translate-y-1/2 h-5 w-5 rounded-badge text-ink-faint hover:text-ink transition flex items-center justify-center"
                        aria-label="Desvincular tarefa">
                    <span class="h-3 w-3"><x-nav-icon name="x-mark" :peso="1.8" /></span>
                </button>

                {{-- A lista filtrada. Rola por dentro; teto de altura para não
                     empurrar o rodapé do modal quando há muitos resultados. --}}
                <div x-show="modal.vinculoAberto && ! modal.somenteLeitura" x-cloak
                     x-transition:enter="transition ease-out duration-100"
                     x-transition:enter-start="opacity-0 -translate-y-1"
                     class="absolute z-10 mt-1 w-full max-h-52 overflow-y-auto rounded-control border border-line bg-panel shadow-[0_12px_28px_-12px_rgba(0,0,0,0.5)]">
                    <button type="button" @click="limparVinculo()"
                            class="block w-full px-2.5 py-1.5 text-left text-[12px] text-ink-mute hover:bg-chip transition">
                        Nenhuma
                    </button>

                    <template x-for="t in tarefasFiltradas()" :key="t.id">
                        <button type="button" @click="escolherTarefa(t)"
                                class="block w-full truncate px-2.5 py-1.5 text-left text-[12px] transition hover:bg-chip"
                                :class="modal.tarefa_id === t.id ? 'text-brand-text font-semibold' : 'text-ink'"
                                x-text="`#${t.id} — ${t.titulo}`"></button>
                    </template>

                    <p x-show="! tarefasFiltradas().length"
                       class="px-2.5 py-2 text-[11.5px] text-ink-faint">Nenhuma tarefa encontrada</p>
                </div>
            </div>

            <template x-if="modal.somenteLeitura">
                <p class="mt-2 text-[11.5px] text-warn">
                    Só quem marcou este compromisso — ou quem faz triagem — pode alterá-lo.
                </p>
            </template>

            <template x-if="modal.erro">
                <p class="mt-2 text-[11.5px] text-crit" x-text="modal.erro"></p>
            </template>

            {{-- Confirmação inline, e não um segundo modal: empilhar diálogo
                 sobre diálogo esconde o que se está prestes a excluir. --}}
            <template x-if="modal.confirmandoExclusao">
                <div class="mt-3.5 rounded-control border px-3 py-2.5"
                     style="background: rgb(var(--warn) / var(--tint-alpha)); border-color: var(--warn-line)">
                    <p class="mb-2 text-[12px] text-ink">Desmarcar este compromisso?</p>
                    <div class="flex gap-2">
                        <button type="button" @click="modal.confirmandoExclusao = false"
                                class="h-7 flex-1 rounded-ctl border border-btn-line text-[11.5px] font-semibold text-ink-mute transition hover:text-ink">
                            Cancelar
                        </button>
                        <button type="button" @click="excluir()"
                                class="h-7 flex-1 rounded-ctl bg-crit text-[11.5px] font-semibold text-white transition hover:brightness-110">
                            Desmarcar
                        </button>
                    </div>
                </div>
            </template>

            <template x-if="! modal.confirmandoExclusao">
                <div class="mt-[18px] flex gap-2">
                    <template x-if="modal.id && ! modal.somenteLeitura">
                        <button type="button" @click="modal.confirmandoExclusao = true"
                                class="h-[34px] shrink-0 rounded-control border px-3 text-[12.5px] font-semibold text-warn transition hover:bg-chip"
                                style="border-color: var(--warn-line)">
                            Desmarcar
                        </button>
                    </template>

                    <button type="button" @click="fecharModal()"
                            class="h-[34px] flex-1 rounded-control border border-btn-line text-[12.5px] font-semibold text-ink-mute transition hover:text-ink"
                            x-text="modal.somenteLeitura ? 'Fechar' : 'Cancelar'"></button>

                    <template x-if="! modal.somenteLeitura">
                        <button type="button" @click="salvar()" :disabled="modal.salvando"
                                class="h-[34px] flex-1 rounded-control bg-brand text-[12.5px] font-semibold text-on-brand transition hover:bg-brand-bright disabled:opacity-60"
                                x-text="modal.salvando ? 'Salvando…' : 'Salvar'"></button>
                    </template>
                </div>
            </template>
        </div>
    </div>
</template>
