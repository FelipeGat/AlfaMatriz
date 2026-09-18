{{--
    Sete colunas que repartem a largura por igual e rolam POR DENTRO.

    `flex-1 basis-0` com `min-w-[150px]`: a divisão é igual enquanto couber, e
    abaixo disso o quadro inteiro ganha rolagem horizontal em vez de espremer as
    colunas até o título virar uma letra por linha. O `min-h-0` em cada coluna é
    o que permite a rolagem interna — sem ele o filho cresce e empurra a coluna
    para fora da moldura, e a barra de rolagem aparece na página, não na coluna.
--}}
<div class="flex min-h-0 flex-1 gap-2 overflow-auto rounded-panel border border-line bg-board p-2.5">
    @foreach ($faixa['de']->daysUntil($faixa['ate']) as $dia)
        @php
            $iso = $dia->toDateString();
            $doDia = $itensPorDia[$iso] ?? collect();
            $ehHoje = $iso === $hoje->toDateString();
        @endphp

        <div class="flex min-w-[150px] flex-1 basis-0 flex-col overflow-hidden rounded-control border bg-panel
                    {{ $ehHoje ? 'border-brand' : 'border-line' }}"
             @drop.prevent="soltarEm('{{ $iso }}')"
             @dragover.prevent
             :class="arrastando && 'ring-1 ring-brand/40'">

            {{-- O cabeçalho é botão: clicar no dia abre o drawer com tudo o que
                 há nele. É o mesmo gesto da célula do Mês, e existe aqui porque
                 a coluna corta o que não cabe — o dia cheio tem mais do que a
                 coluna mostra. --}}
            <button type="button" @click="abrirDia('{{ $iso }}')"
                    class="flex shrink-0 items-center justify-between gap-1.5 border-b border-rule px-2.5 py-2 text-left transition hover:bg-chip
                           {{ $ehHoje ? 'bg-brand/10' : '' }}">
                <span class="font-mono text-[10px] uppercase tracking-[0.1em] text-ink-mute">
                    {{ $dia->translatedFormat('D') }}
                </span>
                <span class="flex h-5 w-5 items-center justify-center rounded-full text-[12px] font-semibold
                             {{ $ehHoje ? 'bg-brand text-on-brand' : 'text-ink' }}">
                    {{ $dia->format('j') }}
                </span>
            </button>

            <div class="flex min-h-0 flex-1 flex-col gap-[5px] overflow-y-auto p-1.5">
                @foreach ($doDia as $item)
                    <x-agenda-item :item="$item"
                                   variante="coluna"
                                   :arrastavel="$podeReagendar && $item['tipo'] === 'tarefa'" />
                @endforeach

                <button type="button" @click="novoCompromisso('{{ $iso }}')"
                        class="h-6 shrink-0 rounded-ctl border border-dashed border-btn-line text-[11px] text-ink-faint transition hover:text-ink-mute">
                    + adicionar
                </button>
            </div>
        </div>
    @endforeach
</div>
