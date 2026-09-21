{{--
    A Semana como GRADE DE HORÁRIOS, no estilo Google Agenda.

    Três partes empilhadas num só container que rola: o cabeçalho dos dias e a
    faixa "dia inteiro" ficam GRUDADOS no topo (`sticky top-0`) enquanto a grade
    de horas rola por baixo; a régua de horas fica GRUDADA à esquerda
    (`sticky left-0`) quando a semana não cabe e rola na horizontal. Assim, o
    dia e a hora que a pessoa olha nunca somem da tela.

    As alturas são pixel fixo — 48px por hora, 1152px o dia inteiro — e os blocos
    se posicionam por porcentagem desses 1152px, como o `AgendaService` calcula.
    O `$env:` de cada bloco (top, altura, coluna) vem pronto do serviço.
--}}
@php
    $alturaHora = 48;            // px por hora — o padrão de agenda
    $alturaGrade = $alturaHora * 24;
    $larguraRegua = 52;         // px da coluna de horas
@endphp

<div class="min-h-0 flex-1 overflow-auto rounded-panel border border-line bg-board"
     x-data="{ inicioGrade: {{ $alturaHora * 7 }} }"
     x-init="$el.scrollTop = inicioGrade">

    {{-- min-w garante que as sete colunas não colapsem: abaixo disso, rolagem
         horizontal em vez de coluna de uma letra por linha. --}}
    <div class="min-w-[860px]">

        {{-- Cabeçalho + dia inteiro, grudados no topo ao rolar as horas. --}}
        <div class="sticky top-0 z-20 bg-board">
            {{-- Linha dos dias --}}
            <div class="flex border-b border-line">
                <div class="sticky left-0 z-10 shrink-0 bg-board" style="width: {{ $larguraRegua }}px"></div>

                @foreach ($grade['dias'] as $d)
                    <button type="button" @click="abrirDia('{{ $d['data'] }}')"
                            class="flex flex-1 basis-0 min-w-[104px] items-center justify-center gap-1.5 border-l border-line px-2 py-1.5 transition hover:bg-chip
                                   {{ $d['ehHoje'] ? 'bg-brand/10' : '' }}">
                        <span class="font-mono text-[10px] uppercase tracking-[0.1em] text-ink-mute">{{ $d['nome'] }}</span>
                        <span class="flex h-5 w-5 items-center justify-center rounded-full text-[12px] font-semibold
                                     {{ $d['ehHoje'] ? 'bg-brand text-on-brand' : 'text-ink' }}">{{ $d['numero'] }}</span>
                    </button>
                @endforeach
            </div>

            {{-- Faixa "dia inteiro": prazos e compromissos de vários dias. --}}
            <div class="flex border-b border-line">
                <div class="sticky left-0 z-10 flex shrink-0 items-center justify-end bg-board pr-1.5"
                     style="width: {{ $larguraRegua }}px">
                    <span class="font-mono text-[8.5px] uppercase leading-tight tracking-[0.06em] text-ink-faint">dia<br>inteiro</span>
                </div>

                @foreach ($grade['dias'] as $d)
                    <div class="flex flex-1 basis-0 min-w-[104px] flex-col gap-0.5 border-l border-line p-1
                                {{ $d['ehHoje'] ? 'bg-brand/[0.04]' : '' }}">
                        @foreach ($d['inteiroDia'] as $item)
                            <x-agenda-item :item="$item" variante="chip" />
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>

        {{-- A grade de horas propriamente. --}}
        <div class="flex">
            {{-- Régua de horas, grudada à esquerda. As etiquetas ficam na LINHA
                 de cada hora, puxadas meio texto para cima para centrar no traço. --}}
            <div class="sticky left-0 z-10 shrink-0 bg-board" style="width: {{ $larguraRegua }}px">
                {{-- `relative` interno para conter as etiquetas absolutas: um
                     ancestral `sticky` não é garantia de bloco de contenção em
                     todo navegador, então a âncora explícita é aqui. --}}
                <div class="relative" style="height: {{ $alturaGrade }}px">
                    @for ($h = 1; $h < 24; $h++)
                        <span class="absolute right-1.5 -translate-y-1/2 font-mono text-[9.5px] text-ink-faint"
                              style="top: {{ $h * $alturaHora }}px">{{ sprintf('%02dh', $h) }}</span>
                    @endfor
                </div>
            </div>

            {{-- As sete colunas de dia. Cada uma é o palco dos blocos. --}}
            @foreach ($grade['dias'] as $d)
                <div class="relative flex-1 basis-0 min-w-[104px] border-l border-line
                            {{ $d['ehHoje'] ? 'bg-brand/[0.04]' : '' }}"
                     style="height: {{ $alturaGrade }}px"
                     @click="novoNoHorario('{{ $d['data'] }}', $event)">

                    {{-- Linhas de hora: o gabarito de fundo. --}}
                    @for ($h = 1; $h < 24; $h++)
                        <div class="pointer-events-none absolute inset-x-0 border-t border-rule/60"
                             style="top: {{ $h * $alturaHora }}px"></div>
                    @endfor

                    {{-- A linha do "agora", só na coluna de hoje. --}}
                    @if ($d['ehHoje'] && $grade['agoraPct'] !== null)
                        <div class="pointer-events-none absolute inset-x-0 border-t-2 border-crit"
                             style="top: {{ $grade['agoraPct'] }}%">
                            <span class="absolute -left-1 -top-[3px] h-1.5 w-1.5 rounded-full bg-crit"></span>
                        </div>
                    @endif

                    {{-- Os blocos, posicionados por horário. Altura pela duração,
                         com um piso para o de 15 min continuar clicável. Largura
                         repartida quando há sobreposição (col/cols). --}}
                    @foreach ($d['blocos'] as $b)
                        <button type="button" @click.stop="abrirCompromisso({{ $b['id'] }})"
                                class="absolute overflow-hidden rounded-ctl px-1.5 py-0.5 text-left transition hover:brightness-110"
                                style="top: {{ $b['topPct'] }}%;
                                       height: {{ $b['altPct'] }}%;
                                       min-height: 16px;
                                       left: calc({{ $b['col'] }} * (100% - 4px) / {{ $b['cols'] }} + 2px);
                                       width: calc((100% - 4px) / {{ $b['cols'] }} - 2px);
                                       background: linear-gradient(rgb(var(--{{ $b['token'] }}) / var(--tint-alpha)), rgb(var(--{{ $b['token'] }}) / var(--tint-alpha))), rgb(var(--panel));
                                       border-left: 2px solid rgb(var(--{{ $b['token'] }}))">
                            <span class="block truncate text-[10.5px] font-semibold leading-tight text-ink">{{ $b['titulo'] }}</span>
                            <span class="block truncate text-[9.5px] leading-tight text-ink-mute">{{ $b['meta'] }}</span>
                        </button>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
</div>
