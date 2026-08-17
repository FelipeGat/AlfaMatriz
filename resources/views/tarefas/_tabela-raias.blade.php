@php
    /**
     * A raia COM FILTRO, como tabela agrupada.
     *
     * Raia é uma grade de duas dimensões — pessoas na vertical, seis etapas na
     * horizontal. Sem filtro ela se paga: é o retrato do time. Com filtro, não.
     * O recorte esvazia as células e o custo de layout de cada faixa continua
     * inteiro (6 × 272px de largura, 180px de altura mínima), então sobra rolar
     * nos dois eixos atrás de meia dúzia de tarefas espalhadas em célula vazia.
     *
     * Aqui a etapa deixa de ser POSIÇÃO NO ESPAÇO e vira coluna do registro. Some
     * uma dimensão, some a rolagem horizontal, e a altura passa a ser
     * proporcional ao que o filtro deixou — que é o que se queria ver.
     *
     * Vive dentro do mesmo `x-data="quadroTarefas"` do quadro, e não é detalhe:
     * é dele que saem o menu "Mover ▾", o pedido de motivo e a abertura da
     * tarefa. Fora daquele escopo, a tabela viraria relatório — e o recorte é
     * justamente onde se trabalha.
     *
     * Espera: $raias, $filtros.
     */
    // A coluna que a seção já nomeia não se repete linha a linha (AC-254).
    $porResponsavel = $raias['modo'] === 'responsavel';
@endphp

<div class="flex-1 min-h-0 overflow-auto p-3.5" data-rolagem="tabela-raias" data-tabela-raias>
    @foreach ($raias['faixas'] as $faixa)
        @continue(collect($faixa['colunas'])->flatten()->isEmpty())

        <section class="mb-4 last:mb-0">
            {{-- O nome do grupo é o cabeçalho da seção: é ele que dispensa a
                 coluna repetida em cada linha. --}}
            {{-- `items-baseline` pelo mesmo motivo do cabeçalho da faixa na
                 grade: texto menor ao lado do nome alinha pela linha de base,
                 não pelo centro da caixa. --}}
            <header class="flex items-baseline gap-2 mb-1.5">
                <h3 class="font-display text-[13.5px] font-semibold text-ink">
                    {{ $faixa['titulo'] ?? 'Todas' }}
                </h3>

                <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                    {{ collect($faixa['colunas'])->flatten()->count() }}
                </span>

                {{-- A mesma porta da grade (AC-354): daqui o clique soma o
                     filtro desta seção ao recorte que já está ligado, e o
                     conjunto cai no quadro plano do AC-353. --}}
                <a href="{{ request()->fullUrlWithQuery([$raias['modo'] => $faixa['filtro']]) }}"
                   data-ver-so-a-faixa
                   title="Aplicar o filtro e ver o quadro só com estas tarefas"
                   class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint hover:text-brand transition">
                    ver só estas
                </a>

                @if ($faixa['sobrecarga'])
                    <x-badge tom="atencao" title="Mais de duas tarefas em andamento ao mesmo tempo">
                        trabalho em paralelo
                    </x-badge>
                @endif
            </header>

            <x-tabela min="820px">
                <thead>
                    <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                        <th class="px-3 py-2 font-semibold">Tarefa</th>
                        <th class="px-3 py-2 font-semibold">Etapa</th>
                        <th class="px-3 py-2 font-semibold">{{ $porResponsavel ? 'Sistema' : 'Responsável' }}</th>
                        <th class="px-3 py-2 font-semibold">Prioridade</th>
                        <th class="px-3 py-2 font-semibold text-right">Na etapa há</th>
                        <th class="px-3 py-2 font-semibold text-right">Ação</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach (collect($faixa['colunas'])->flatten() as $tarefa)
                        @php
                            $impedimento = $tarefa->motivoParaNaoMover(auth()->user());
                            $transicoes = $tarefa->destinosPara(auth()->user());
                            $eventoAberto = $tarefa->eventos->firstWhere('saiu_em', null);
                            $progresso = $tarefa->progressoDoChecklist();
                        @endphp

                        {{-- O `x-data` da linha é o mesmo do card: o menu "Mover ▾"
                             lê `menuAberto` dali. `data-tarefa` idem — é por ele
                             que o acompanhamento pós-movimento acha a linha, que
                             nesta vista é o card. O teclado do quadro não se
                             confunde: ele só procura dentro de
                             `section[data-status]`, que a tabela não tem. --}}
                        <tr x-data="{ menuAberto: false, destino: '{{ $transicoes[0] ?? '' }}' }"
                            data-tarefa="{{ $tarefa->id }}"
                            class="border-b border-rule hover:bg-chip transition cursor-pointer"
                            @click="$dispatch('abrir-tarefa', {{ $tarefa->id }})">

                            <td class="px-3 py-2.5">
                                <p class="text-[13px] text-ink leading-tight">
                                    <span class="font-mono text-[10.5px] text-ink-faint">#{{ $tarefa->id }}</span>
                                    {{ $tarefa->titulo }}
                                </p>
                                @if ($tarefa->resumo)
                                    <p class="mt-0.5 text-[11.5px] text-ink-mute truncate max-w-[42ch]">{{ $tarefa->resumo }}</p>
                                @endif

                                {{-- As tarjas do card viram sinais na mesma célula do
                                     título: travada e pergunta mudam de quem é a vez,
                                     e some-las aqui esconderia justamente o que faz a
                                     tarefa não andar. --}}
                                <div class="mt-1 flex items-center gap-2">
                                    @if ($tarefa->estaBloqueada())
                                        <x-badge tom="bloqueio" title="{{ $tarefa->bloqueio_motivo }}">travada</x-badge>
                                    @endif
                                    @if ($tarefa->pergunta_em)
                                        <x-badge tom="pergunta" title="Aguardando resposta de {{ $tarefa->perguntaPara?->name ?? 'alguém' }}">
                                            pergunta
                                        </x-badge>
                                    @endif
                                    @if ($progresso)
                                        <span class="font-mono text-[10.5px] text-ink-faint"
                                              title="{{ $progresso['feitos'] }} de {{ $progresso['total'] }} itens concluídos">
                                            {{ $progresso['feitos'] }}/{{ $progresso['total'] }}
                                        </span>
                                    @endif
                                </div>
                            </td>

                            <td class="px-3 py-2.5 whitespace-nowrap">
                                <span class="inline-flex items-center gap-1.5 text-[12.5px] text-ink-dim">
                                    <span class="h-[7px] w-[7px] rounded-full shrink-0"
                                          style="background: rgb(var(--{{ \App\Models\Tarefa::corDaEtapa($tarefa->status) }}))"></span>
                                    {{ \App\Models\Tarefa::rotuloDaEtapa($tarefa->status) }}
                                </span>
                            </td>

                            <td class="px-3 py-2.5 text-[12.5px] text-ink-dim whitespace-nowrap">
                                {{ $porResponsavel
                                    ? ($tarefa->sistema?->nome ?? '—')
                                    : ($tarefa->responsavel?->name ?? '—') }}
                            </td>

                            <td class="px-3 py-2.5 whitespace-nowrap">
                                <span class="text-[12.5px] text-ink-dim">
                                    {{ \App\Models\Tarefa::PRIORIDADES[$tarefa->prioridade] ?? $tarefa->prioridade }}
                                </span>
                            </td>

                            <td class="px-3 py-2.5 text-right font-mono text-[11px] text-ink-faint whitespace-nowrap">
                                {{ $eventoAberto?->entrou_em?->diffForHumans(null, true) ?? '—' }}
                            </td>

                            {{-- O clique na célula de ação NÃO abre a tarefa: aqui
                                 mora o menu de mover, e abrir o modal por baixo dele
                                 tiraria da tela o menu que acabou de ser aberto. --}}
                            <td class="px-3 py-2.5 text-right relative" @click.stop>
                                <div data-motivo="{{ $tarefa->id }}"></div>
                                @include('tarefas._mover', ['tarefa' => $tarefa, 'transicoes' => $transicoes])
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-tabela>
        </section>
    @endforeach
</div>
