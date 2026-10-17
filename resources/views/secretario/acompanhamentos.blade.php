<x-app-layout>
    <x-slot name="titulo">Acompanhamentos</x-slot>
    <x-slot name="contexto"><span>central de gestão · Grupo Soluções e VixCard</span></x-slot>

    {{-- Etapa 7 (docs/specs/secretario-acompanhamento). A lista é o que o motor
         achou na última conferência (a cada 5 min); o resumo no sino e no
         Telegram segue a política lá embaixo. --}}
    @php
        $rotulosPrioridade = ['alta' => 'Alta prioridade', 'normal' => 'Prioridade normal', 'baixa' => 'Baixa (só aqui e no briefing)'];
        $campo = 'rounded-control border border-btn-line bg-surface px-2 py-1.5 text-[13px] text-ink focus:border-brand focus:outline-none';
        $botao = 'h-[30px] rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim hover:border-brand hover:text-brand';
    @endphp
    <div class="mx-auto w-full max-w-5xl space-y-4">
        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif
        {{-- Etapa 8 (docs/specs/secretario-central-do-dia): a Central de gestão. --}}
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            @foreach ([
                ['Compromissos hoje', $numeros['compromissos'], route('secretario.agenda')],
                ['E-mails não lidos (3 dias)', $numeros['nao_lidos'], route('secretario.emails.index', ['ver' => 'todas'])],
                ['Tarefas vencidas', $numeros['vencidas'], route('secretario.meu-dia')],
                ['Alertas ativos', $numeros['alertas'], '#alertas'],
            ] as [$rotulo, $n, $link])
                <a href="{{ $link }}" class="rounded-panel border border-line bg-subtle px-4 py-3 hover:border-brand">
                    <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">{{ $rotulo }}</p>
                    <p class="mt-1 font-display text-[24px] font-semibold {{ $n > 0 && $rotulo === 'Tarefas vencidas' ? 'text-crit' : 'text-ink' }}">{{ $n }}</p>
                </a>
            @endforeach
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div id="grupo_solucoes" class="scroll-mt-20">
                <x-painel :titulo="'Grupo Soluções'.($gestor ? ' · Gestor lido às '.$gestor['lido_em']->format('H:i') : '')" solto>
                    @if (! $gestor)
                        <p class="px-4 py-3 text-[13px] text-ink-mute">Sua máquina ainda não leu o Gestor (a leitura é de hora em hora).</p>
                    @else
                        @if ($gestor['velho'])
                            <p class="border-b border-line bg-warn-tint px-4 py-2 text-[12.5px] text-warn">Retrato de {{ $gestor['lido_em']->format('d/m H:i') }}: desatualizado. Confira se a sua máquina está ligada.</p>
                        @endif
                        <ul class="divide-y divide-line">
                            @foreach ($gestor['blocos'] as $b)
                                <li class="px-4 py-2.5" x-data="{ aberto: false }">
                                    <button type="button" @click="aberto = !aberto" class="flex w-full items-baseline gap-2 text-left">
                                        <span class="font-display text-[18px] font-semibold {{ $b['quantidade'] > 0 ? 'text-ink' : 'text-ink-faint' }}">{{ $b['quantidade'] }}</span>
                                        <span class="min-w-0 flex-1 text-[13px] text-ink">{{ $b['rotulo'] }}</span>
                                        @if (! empty($b['valor']))<span class="whitespace-nowrap font-mono text-[12px] text-ink-dim">R$ {{ number_format($b['valor'], 2, ',', '.') }}</span>@endif
                                        @if ($b['itens'])<span class="text-[11px] text-ink-faint" x-text="aberto ? '▲' : '▼'"></span>@endif
                                    </button>
                                    @if ($b['itens'])
                                        <ul x-show="aberto" x-cloak class="mt-1.5 space-y-1 pl-7 text-[12.5px] text-ink-dim">
                                            @foreach ($b['itens'] as $it)
                                                <li>{{ $it['texto'] }}@if (! empty($it['data'])) <span class="text-ink-faint">· {{ \Carbon\Carbon::parse($it['data'])->format('d/m') }}</span>@endif</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </li>
                            @endforeach
                            @foreach ($pontos['grupo_solucoes'] as $i)
                                @include('secretario._ponto', ['i' => $i])
                            @endforeach
                        </ul>
                    @endif
                    @if (! $gestor && $pontos['grupo_solucoes']->isNotEmpty())
                        <ul class="divide-y divide-line border-t border-line">
                            @foreach ($pontos['grupo_solucoes'] as $i)
                                @include('secretario._ponto', ['i' => $i])
                            @endforeach
                        </ul>
                    @endif
                </x-painel>
            </div>
            <div id="vixcard" class="scroll-mt-20">
                <x-painel :titulo="'VixCard · '.$pontos['vixcard']->count().' ponto(s) em aberto'" solto>
                    <ul class="divide-y divide-line">
                        @forelse ($pontos['vixcard'] as $i)
                            @include('secretario._ponto', ['i' => $i])
                        @empty
                            <li class="px-4 py-3 text-[13px] text-ink-mute">Nenhum ponto lançado. A VixCard ainda não tem sistema: lance aqui o que precisa da sua decisão.</li>
                        @endforelse
                    </ul>
                </x-painel>
            </div>
        </div>

        <x-painel titulo="Lançar ponto de gestão">
            <form method="POST" action="{{ route('secretario.acompanhamentos.pontos') }}" class="flex flex-col gap-2 md:flex-row md:items-center">
                @csrf
                <select name="empresa" class="{{ $campo }}">
                    @foreach (\App\Services\Secretario\CentralDeGestao::EMPRESAS as $k => $nome)
                        <option value="{{ $k }}" @selected(old('empresa', 'vixcard') === $k)>{{ $nome }}</option>
                    @endforeach
                </select>
                <input type="text" name="titulo" value="{{ old('titulo') }}" maxlength="190" required placeholder="O que precisa da sua decisão (ex.: aprovar tabela de preços)" class="{{ $campo }} min-w-0 flex-1">
                <input type="date" name="prazo" value="{{ old('prazo') }}" class="{{ $campo }}" title="Prazo (opcional)">
                <select name="prioridade" class="{{ $campo }}">
                    @foreach (['normal' => 'normal', 'alta' => 'alta', 'baixa' => 'baixa'] as $k => $r)
                        <option value="{{ $k }}" @selected(old('prioridade', 'normal') === $k)>{{ $r }}</option>
                    @endforeach
                </select>
                <button class="h-[34px] rounded-control bg-brand px-4 text-[13px] font-semibold text-on-brand hover:bg-brand-bright">Lançar</button>
            </form>
            @error('titulo')<p class="mt-1 text-[12px] text-crit">{{ $message }}</p>@enderror
            <p class="mt-1.5 text-[12px] text-ink-mute">Com prazo, o Secretário lembra às 08:00 do dia e avisa se vencer.</p>
        </x-painel>

        <h2 id="alertas" class="scroll-mt-20 pt-2 font-display text-[16px] font-semibold text-ink">Alertas</h2>
        @if ($silencio)
            <p class="rounded-panel border border-line bg-subtle px-4 py-2.5 text-[13px] text-ink-mute">
                Em silêncio agora ({{ $config['fim_de_semana'] && now()->isWeekend() ? 'fim de semana' : 'das '.$config['silencio_inicio'].' às '.$config['silencio_fim'] }}): os resumos esperam. A lista abaixo continua valendo.
            </p>
        @endif

        @forelse ($ativos as $prioridade => $lista)
            <x-painel :titulo="$rotulosPrioridade[$prioridade].' ('.$lista->count().')'" solto>
                <ul class="divide-y divide-line">
                    @foreach ($lista as $a)
                        <li class="px-4 py-3">
                            <p class="text-[13.5px] text-ink break-words">
                                {{ $a->marca() }}
                                @if ($a->link)
                                    <a href="{{ $a->link }}" @if (! str_starts_with($a->link, url('/'))) target="_blank" rel="noopener noreferrer" @endif class="hover:text-brand hover:underline">{{ $a->texto }}</a>
                                @else
                                    {{ $a->texto }}
                                @endif
                            </p>
                            <p class="text-[11.5px] text-ink-mute">
                                {{ \App\Services\Secretario\Acompanhamento\Regras::REGRAS[$a->regra][0] ?? $a->regra }}
                                · desde {{ $a->created_at->format('d/m H:i') }}
                                @if ($a->avisos) · avisado {{ $a->avisos }}× @endif
                                @if ($a->adiado()) · <span class="text-warn">adiado até {{ $a->adiado_ate->format('d/m H:i') }}</span> @endif
                            </p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <form method="POST" action="{{ route('secretario.acompanhamentos.resolver', $a->id) }}">@csrf
                                    <button class="{{ $botao }}">✓ Resolvido</button>
                                </form>
                                @unless ($a->adiado())
                                    <form method="POST" action="{{ route('secretario.acompanhamentos.adiar', $a->id) }}">@csrf
                                        <button class="{{ $botao }}">Adiar 1 dia</button>
                                    </form>
                                @endunless
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-painel>
        @empty
            <p class="rounded-panel border border-line bg-subtle px-4 py-4 text-[13.5px] text-ink-mute">Nada pedindo acompanhamento agora.</p>
        @endforelse
        @if ($dispensados)
            <p class="text-[12px] text-ink-mute">{{ $dispensados }} situação(ões) marcada(s) como resolvida(s) e ainda valendo: não voltam a avisar.</p>
        @endif

        <x-painel titulo="Avisos enviados (7 dias)" solto>
            @forelse ($envios as $e)
                <p class="border-b border-line px-4 py-2 text-[12.5px] text-ink-dim last:border-0">
                    {{ $e->created_at->format('d/m H:i') }} · {{ $e->canal === 'sino' ? 'Sino' : 'Telegram' }} · {{ $e->quantos }} situação(ões) ·
                    <span class="{{ $e->status === 'falhou' ? 'text-crit' : ($e->status === 'entregue' ? 'text-good' : 'text-ink-mute') }}">{{ ['pendente' => 'na fila', 'enviando' => 'enviando', 'entregue' => 'entregue', 'falhou' => 'não chegou'][$e->status] ?? $e->status }}</span>
                </p>
            @empty
                <p class="px-4 py-3 text-[13px] text-ink-mute">Nenhum resumo enviado nos últimos 7 dias.</p>
            @endforelse
        </x-painel>

        <p class="text-center text-[12.5px] text-ink-mute">Regras e horários dos avisos: <a href="{{ route('secretario.configuracoes') }}#avisos" class="font-semibold text-brand-text hover:underline">Secretário → Configurações</a></p>
    </div>
</x-app-layout>
