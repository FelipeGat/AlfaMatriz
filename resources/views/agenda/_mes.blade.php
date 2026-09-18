{{--
    Quarenta e duas células, sempre — seis semanas fixas.

    Fixas de propósito: um mês que às vezes tem cinco linhas e às vezes seis faz
    a grade inteira mudar de altura ao navegar, e a mesma célula muda de tamanho
    conforme o mês que se está olhando. O preço é uma linha às vezes inteiramente
    do mês vizinho, e ela vem esmaecida para dizer que é vizinha.

    `grid-rows-6` com `min-h-0` nas células: sem o `min-h-0` o conteúdo define a
    altura da linha e as seis deixam de ser iguais — a grade volta a saltar.
--}}
<div class="grid min-h-0 flex-1 grid-cols-7 grid-rows-6 gap-1.5 rounded-panel border border-line bg-board p-2">
    @foreach ($faixa['de']->daysUntil($faixa['ate']) as $dia)
        @php
            $iso = $dia->toDateString();
            $doDia = $itensPorDia[$iso] ?? collect();
            $ehHoje = $iso === $hoje->toDateString();
            $doMes = $dia->month === $em->month;

            // Duas linhas por célula, e o resto vira contagem. Três já
            // estouravam a altura fixa nos meses de seis semanas, e uma célula
            // que cresce desfaz a grade que o parágrafo acima defende.
            $visiveis = $doDia->take(2);
            $restantes = $doDia->count() - $visiveis->count();
        @endphp

        <div @click="abrirDia('{{ $iso }}')"
             @drop.prevent="soltarEm('{{ $iso }}')"
             @dragover.prevent
             class="flex min-h-0 min-w-0 cursor-pointer flex-col gap-1 overflow-hidden rounded-ctl border p-1.5 transition hover:brightness-110
                    {{ $ehHoje ? 'border-brand bg-brand/10' : 'border-line bg-panel' }}
                    {{ $doMes ? '' : 'opacity-40' }}">
            <span class="shrink-0 text-[11.5px] {{ $ehHoje ? 'font-semibold text-brand-text' : 'text-ink' }}">
                {{ $dia->format('j') }}
            </span>

            @foreach ($visiveis as $item)
                <x-agenda-item :item="$item" variante="ponto" />
            @endforeach

            @if ($restantes > 0)
                <span class="shrink-0 font-mono text-[9.5px] text-ink-faint">+{{ $restantes }}</span>
            @endif
        </div>
    @endforeach
</div>
