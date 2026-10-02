@php
    /**
     * O aviso de tarefas parecidas na criação (#205) — o que `tarefas.parecidas`
     * devolve e o `_parecidas-ao-criar` encaixa no formulário.
     *
     * Mesmo tom da nota de triagem do formulário (`warn`): é recado, não erro.
     * O aviso não trava o Salvar, e a última linha diz isso — sem ela, um
     * quadro amarelo acima do botão se leria como "não pode salvar".
     *
     * Espera: $parecidas.
     */
@endphp

@if ($parecidas->isNotEmpty())
    <div class="px-[11px] py-[9px] rounded-[5px] border text-[11.5px] leading-[1.5] text-ink-dim"
         style="background: var(--warn-tint); border-color: var(--warn-line)"
         data-parecidas>
        <p class="font-medium text-ink">Já existem tarefas parecidas em curso:</p>

        <ul class="mt-1 space-y-0.5">
            @foreach ($parecidas as $parecida)
                <li class="min-w-0">
                    <span class="font-mono text-ink">{{ $parecida->codigo() }}</span>
                    · {{ $parecida->titulo }}
                    <span class="text-ink-faint">
                        — {{ \App\Models\Tarefa::rotuloDaEtapa($parecida->status) }}{{ $parecida->estaArquivada() ? ' (arquivada)' : '' }}
                        · {{ $parecida->sistema?->nome ?? 'sem sistema' }}
                        · {{ \App\Models\Tarefa::PRIORIDADES[$parecida->prioridade] ?? $parecida->prioridade }}
                    </span>
                </li>
            @endforeach
        </ul>

        <p class="mt-1 text-ink-faint">
            Se for o mesmo pedido, comente na tarefa que já existe. Se for outro, é só salvar.
        </p>
    </div>
@endif
