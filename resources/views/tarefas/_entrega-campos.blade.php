{{--
    Os três campos de uma entrega (#210), por extenso e com as quebras de linha
    de quem escreveu — os passos de teste são lista, e colapsados numa linha só
    viram adivinhação. Um molde para o modal e para o histórico, para os dois
    não divergirem na primeira mexida.

    O PR sai em pedaços (`TarefaEntrega::trechosDoPr`): cada um escapado, e o
    `<a>` só em volta do que é http/https. Nada do texto livre vira HTML.

    Espera: $entrega.
--}}
<dl class="mt-1.5 flex flex-col gap-1.5">
    <div>
        <dt class="font-mono text-[9.5px] uppercase tracking-[0.08em] text-ink-faint">O que foi feito</dt>
        <dd class="text-[12.5px] leading-[1.45] text-ink whitespace-pre-wrap">{{ $entrega->o_que_foi_feito }}</dd>
    </div>
    <div>
        <dt class="font-mono text-[9.5px] uppercase tracking-[0.08em] text-ink-faint">Como testar</dt>
        <dd class="text-[12.5px] leading-[1.45] text-ink whitespace-pre-wrap">{{ $entrega->como_testar }}</dd>
    </div>
    @if (filled($entrega->pr_commits))
        <div>
            <dt class="font-mono text-[9.5px] uppercase tracking-[0.08em] text-ink-faint">PR e commits</dt>
            {{-- Montado em PHP, e não com @foreach/@if no meio da linha: o
                 pre-wrap mostraria cada quebra entre as diretivas como espaço,
                 e o Blade não compila duas diretivas coladas. Cada pedaço
                 passa pelo `e()` — o único HTML é o `<a>` daqui. --}}
            @php
                $prEmHtml = collect($entrega->trechosDoPr())
                    ->map(fn (array $trecho) => $trecho['url']
                        ? '<a href="'.e($trecho['url']).'" target="_blank" rel="noopener noreferrer"'
                            .' class="text-brand-text underline transition hover:text-brand">'.e($trecho['texto']).'</a>'
                        : e($trecho['texto']))
                    ->implode('');
            @endphp
            <dd class="text-[12.5px] leading-[1.45] text-ink whitespace-pre-wrap break-words">{!! $prEmHtml !!}</dd>
        </div>
    @endif
</dl>
