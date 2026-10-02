@php
    /**
     * Duplicidade no modal da tarefa (#205): o vínculo das duas pontas, o
     * lembrete da triagem e o gesto de marcar duplicada.
     *
     * O lembrete aparece só na fila (Aberta) e só para quem triaga, porque é a
     * hora em que as três perguntas mudam alguma coisa: depois de direcionada,
     * descobrir que o sistema estava errado já custou o trabalho de alguém. Foi
     * o que aconteceu com a #185 — aberta no AlfaControl, que não tem Wellhub, e
     * pedida de novo como #191 com outra prioridade.
     *
     * As perguntas vêm respondidas com o que o quadro sabe, e não como caixas a
     * marcar: um checklist que ninguém grava vira hábito de clicar sem ler.
     *
     * Marcar duplicada é um envio próprio (`duplicada-{id}`, em `_modais`):
     * formulário aninhado é HTML inválido, e o campo aponta para fora pelo
     * atributo `form`, como o bloqueio do rodapé.
     *
     * Espera: $tarefa.
     */
    $usuario = auth()->user();
    $naTriagem = $tarefa->status === 'aberta' && ($usuario?->podeTriarTarefas() ?? false);

    $parecidas = $naTriagem
        ? app(\App\Services\DuplicidadeDeTarefas::class)
            ->parecidas($tarefa->titulo, $tarefa->resumo, $tarefa->sistema_id, ignorarId: $tarefa->id)
        : collect();

    // Marcar é cancelar: vale para quem pode mover esta tarefa, e só enquanto
    // ela está em curso.
    $podeMarcar = ! in_array($tarefa->status, \App\Models\Tarefa::STATUS_TERMINAIS, true)
        && $tarefa->motivoParaNaoMover($usuario) === null;

    $outrosSistemas = $parecidas->pluck('sistema.nome')->filter()->unique()
        ->reject(fn ($nome) => $nome === $tarefa->sistema?->nome);

    $rotulo = fn ($chave) => \App\Models\Tarefa::PRIORIDADES[$chave] ?? $chave;
@endphp

@if ($tarefa->duplicadaDe)
    <p class="px-[11px] py-[9px] rounded-[5px] border border-line text-[12px] leading-[1.45] text-ink-dim">
        Cancelada como duplicada de
        <span class="font-mono text-ink">{{ $tarefa->duplicadaDe->codigo() }}</span> · {{ $tarefa->duplicadaDe->titulo }}
    </p>
@endif

@if ($tarefa->duplicadas->isNotEmpty())
    <p class="px-[11px] py-[9px] rounded-[5px] border border-line text-[12px] leading-[1.45] text-ink-dim">
        Pedida de novo em
        @foreach ($tarefa->duplicadas as $duplicada)
            <span class="font-mono text-ink">{{ $duplicada->codigo() }}</span>@if (! $loop->last), @endif
        @endforeach
        — {{ $tarefa->duplicadas->count() === 1 ? 'cancelada' : 'canceladas' }} como duplicada desta.
    </p>
@endif

@if ($naTriagem || $podeMarcar)
    <div class="flex flex-col gap-2" x-data="{ marcando: false, original: '' }">
        @if ($naTriagem)
            <div class="px-[11px] py-[9px] rounded-[5px] border border-line text-[11.5px] leading-[1.5] text-ink-dim"
                 data-triagem>
                <h4 class="font-mono text-[10.5px] font-semibold uppercase tracking-caps text-ink">Antes de direcionar</h4>

                <ul class="mt-1.5 space-y-1.5">
                    <li>
                        <strong class="font-semibold text-ink">O sistema confere?</strong>
                        @if ($tarefa->sistema)
                            Está em {{ $tarefa->sistema->nome }}.
                        @else
                            Ainda sem sistema.
                        @endif
                        @if ($outrosSistemas->isNotEmpty())
                            As parecidas estão em {{ $outrosSistemas->implode(', ') }}.
                        @endif
                    </li>

                    <li>
                        <strong class="font-semibold text-ink">Existe duplicada?</strong>
                        @if ($parecidas->isEmpty())
                            Nenhuma tarefa em curso parecida.
                        @else
                            Parecidas em curso:
                            <ul class="mt-1 space-y-1">
                                @foreach ($parecidas as $parecida)
                                    <li class="flex items-center gap-2">
                                        <span class="flex-1 min-w-0">
                                            <span class="font-mono text-ink">{{ $parecida->codigo() }}</span>
                                            · {{ $parecida->titulo }}
                                            <span class="text-ink-faint">
                                                — {{ \App\Models\Tarefa::rotuloDaEtapa($parecida->status) }}{{ $parecida->estaArquivada() ? ' (arquivada)' : '' }}
                                                · {{ $parecida->sistema?->nome ?? 'sem sistema' }}
                                            </span>
                                        </span>
                                        @if ($podeMarcar)
                                            <button type="button"
                                                    @click="original = @js($parecida->codigo()); marcando = true"
                                                    class="shrink-0 h-6 px-2.5 rounded-tile border border-btn-line text-[11.5px] font-semibold
                                                           text-ink-dim transition hover:text-ink hover:bg-chip">
                                                É esta
                                            </button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>

                    <li>
                        <strong class="font-semibold text-ink">Prioridade coerente?</strong>
                        @if ($parecidas->isEmpty())
                            Sem parecidas para comparar.
                        @else
                            As parecidas estão como
                            {{ $parecidas->map(fn ($p) => $p->codigo().' '.mb_strtolower($rotulo($p->prioridade)))->implode(', ') }}.
                        @endif
                    </li>
                </ul>
            </div>
        @endif

        @if ($podeMarcar)
            <div>
                <button type="button" @click="marcando = ! marcando"
                        class="text-[11.5px] text-ink-faint transition hover:text-ink">
                    É o mesmo pedido de outra tarefa? Marcar como duplicada
                </button>

                <div x-show="marcando" x-cloak class="mt-2 flex items-end gap-2">
                    <div class="flex-1 min-w-0">
                        <label for="duplicada-de-{{ $tarefa->id }}" class="block mb-[5px] text-[12px] font-medium text-ink-dim">
                            Duplicada de qual tarefa?
                        </label>
                        <input id="duplicada-de-{{ $tarefa->id }}" type="text" name="original" x-model="original"
                               form="duplicada-{{ $tarefa->id }}" required maxlength="20" placeholder="#185"
                               class="block w-full h-[34px] px-2.5 py-0 rounded-control bg-input border-line text-ink text-[12.5px]">
                    </div>
                    <button type="submit" form="duplicada-{{ $tarefa->id }}"
                            class="shrink-0 h-[34px] px-3 rounded-control border text-[12.5px] font-semibold transition hover:bg-chip"
                            style="border-color: var(--warn-line); color: rgb(var(--warn))">
                        Cancelar como duplicada
                    </button>
                </div>

                <p x-show="marcando" x-cloak class="mt-1 text-[11px] leading-[1.4] text-ink-faint">
                    Esta tarefa é cancelada, e o vínculo fica visível nas duas.
                </p>
            </div>
        @endif
    </div>
@endif
