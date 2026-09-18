@php
    /**
     * A navegação é por LINK, e não por Alpine, de propósito.
     *
     * A faixa de datas decide o que o servidor consulta — trocar de semana é
     * trocar a pergunta, não esconder parte da resposta. Com estado no
     * navegador, ou a tela carregaria o ano inteiro de uma vez, ou cada seta
     * faria uma requisição para remontar o que um link já remonta de graça —
     * com URL compartilhável e botão "voltar" funcionando de brinde.
     */
    $comFiltro = fn (array $extra) => route('agenda.index', array_merge(
        ['visao' => $visao, 'em' => $em->toDateString(), 'pessoas' => $pessoas],
        $extra,
    ));

    // Métodos nomeados e não `sub($n, $unidade)`: a assinatura genérica do
    // Carbon troca de ordem entre versões, e "uma semana" é o passo do Mês
    // quando ela troca sem avisar.
    $anterior = ($visao === 'mes' ? $em->copy()->subMonthNoOverflow() : $em->copy()->subWeek())->toDateString();
    $proximo = ($visao === 'mes' ? $em->copy()->addMonthNoOverflow() : $em->copy()->addWeek())->toDateString();
@endphp

<div class="flex shrink-0 flex-wrap items-center gap-2.5">
    {{-- Seletor de visão. `Lista` e não `Agenda`: uma visão Agenda dentro da
         tela Agenda faria a mesma palavra nomear duas coisas na mesma barra. --}}
    <div class="flex items-center gap-0.5 rounded-control border border-btn-line bg-subtle p-[3px]">
        @foreach (['semana' => 'Semana', 'mes' => 'Mês', 'lista' => 'Lista'] as $chave => $rotulo)
            <a href="{{ $comFiltro(['visao' => $chave]) }}"
               class="flex h-[26px] items-center rounded-tile px-3 text-[12px] font-semibold transition
                      {{ $visao === $chave ? 'bg-nav-active text-brand-text' : 'text-ink-mute hover:text-ink' }}">
                {{ $rotulo }}
            </a>
        @endforeach
    </div>

    {{-- A navegação some na Lista: ela é sempre "próximos 21 dias", e uma seta
         que não muda nada é pior que seta nenhuma — ensina que a tela travou. --}}
    @if ($visao !== 'lista')
        <div class="flex items-center gap-1">
            <a href="{{ $comFiltro(['em' => $anterior]) }}" aria-label="Período anterior"
               class="flex h-7 w-7 items-center justify-center rounded-ctl border border-btn-line text-ink-mute transition hover:text-ink">
                <span class="h-[13px] w-[13px]"><x-nav-icon name="chevron-left" :peso="1.8" /></span>
            </a>
            <a href="{{ $comFiltro(['em' => $hoje->toDateString()]) }}"
               class="flex h-7 items-center rounded-ctl border border-btn-line px-2.5 text-[11.5px] font-semibold text-ink-mute transition hover:text-ink">
                Hoje
            </a>
            <a href="{{ $comFiltro(['em' => $proximo]) }}" aria-label="Próximo período"
               class="flex h-7 w-7 items-center justify-center rounded-ctl border border-btn-line text-ink-mute transition hover:text-ink">
                <span class="h-[13px] w-[13px]"><x-nav-icon name="chevron-right" :peso="1.8" /></span>
            </a>
        </div>
    @endif

    {{-- `flex:0 0 auto` via `shrink-0`: quando a barra aperta, quem cede é o
         bloco de chips à direita, não o rótulo que diz onde estamos. --}}
    <p class="shrink-0 whitespace-nowrap font-display text-[14.5px] font-semibold text-ink">{{ $faixaLabel }}</p>

    <div class="ml-auto flex flex-wrap items-center gap-2.5">
        {{-- Filtro de pessoas: iniciais em chip, e o link ALTERNA — clicar em
             quem já está filtrado tira. Vazio quer dizer todo mundo, e é por
             isso que "ninguém marcado" não esvazia a tela. --}}
        @if ($equipe->isNotEmpty())
            <div class="flex items-center gap-1">
                @foreach ($equipe as $pessoa)
                    @php
                        $marcada = in_array($pessoa->id, $pessoas, true);
                        $novas = $marcada
                            ? array_values(array_diff($pessoas, [$pessoa->id]))
                            : [...$pessoas, $pessoa->id];
                        $iniciais = \Illuminate\Support\Str::of($pessoa->name)
                            ->explode(' ')->filter()->take(2)
                            ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
                    @endphp
                    <a href="{{ $comFiltro(['pessoas' => $novas]) }}" title="{{ $pessoa->name }}"
                       class="flex h-[26px] items-center rounded-full border px-2 font-mono text-[10.5px] font-semibold transition
                              {{ $marcada
                                 ? 'border-brand bg-brand/15 text-brand-text'
                                 : 'border-btn-line text-ink-mute hover:text-ink' }}">
                        {{ $iniciais }}
                    </a>
                @endforeach
            </div>
        @endif

        <button type="button" @click="novoCompromisso()"
                class="flex h-8 shrink-0 items-center whitespace-nowrap rounded-control bg-brand px-3.5
                       text-[12.5px] font-semibold text-on-brand transition hover:bg-brand-bright">
            + Novo compromisso
        </button>
    </div>
</div>
