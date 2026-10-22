<x-app-layout>
    <x-slot name="titulo">Acompanhamentos</x-slot>
    <x-slot name="contexto"><span>central de gestão · Grupo Soluções e VixCard</span></x-slot>
    <x-slot name="acoes">
        <a href="{{ route('secretario.configuracoes') }}#avisos"
           class="h-[30px] px-3 inline-flex items-center gap-1.5 rounded-control border border-btn-line text-ink-dim text-[12.5px] font-semibold hover:text-brand hover:border-brand transition whitespace-nowrap">
            <span class="h-4 w-4"><x-nav-icon name="settings" /></span> Regras dos avisos
        </a>
    </x-slot>

    {{-- Central de gestão (desenho de 17/10/2026; docs/specs/secretario-central-do-dia).
         Uma tabela só com três fontes reais: pontos lançados à mão (a VixCard
         ainda não tem sistema), alertas das regras e os itens do retrato do
         Gestor (até 10 por bloco, os que a máquina lê). Filtros no navegador. --}}
    @php
        $campo = 'w-full rounded-control border border-btn-line bg-canvas px-2.5 py-1.5 text-[13px] text-ink focus:border-brand focus:outline-none';
        $corStatus = fn ($s) => match ($s) {
            'Vencido' => 'bg-crit-tint text-crit', 'Vence hoje', 'A pagar' => 'bg-warn-tint text-warn', 'Adiado', 'Parado' => 'bg-head text-ink-mute',
            'Aguardando aprovação', 'Próximo passo', 'Pausado' => 'bg-warn-tint text-warn', 'Em elaboração', 'Em andamento' => 'bg-brand/10 text-brand-text', default => 'bg-head text-ink-dim',
        };
        $corPrio = ['alta' => ['bg-crit-tint text-crit', 'bg-crit', 'Alta'], 'normal' => ['bg-warn-tint text-warn', 'bg-warn', 'Média'], 'baixa' => ['bg-brand/10 text-brand-text', 'bg-brand', 'Baixa']];
        $porAba = $linhas->countBy('aba');
        $resumo = $linhas->countBy('status')->sortDesc();
    @endphp
    <div x-data="{ aba: 'todos', busca: '', prio: '', origem: '', filtros: false,
                   mostra(l) { return (this.aba === 'todos' || l.aba === this.aba) && (!this.prio || l.prio === this.prio) && (!this.origem || l.fonte === this.origem)
                       && (!this.busca || l.texto.includes(this.busca.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''))); } }"
         class="mx-auto grid w-full max-w-7xl grid-cols-1 gap-4 2xl:grid-cols-[minmax(0,1fr)_320px]">
        <div class="min-w-0 space-y-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1">
                    <h1 class="font-display text-[22px] font-semibold leading-tight text-ink">Acompanhamentos</h1>
                    <p class="mt-0.5 text-[13px] text-ink-mute">Tudo o que precisa de atenção, em um só lugar.</p>
                </div>
                <button type="button" @click="$refs.ponto.focus()" class="inline-flex h-[30px] items-center gap-2 rounded-control bg-brand px-4 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright">
                    <span class="h-4 w-4"><x-nav-icon name="plus" peso="2" /></span> Novo ponto de gestão
                </button>
            </div>
            @if (session('status'))
                <x-aviso>{{ session('status') }}</x-aviso>
            @endif

            {{-- Aberta pelo aviso do sino, a tela mostra SÓ os itens dele (Felipe, 22/10/2026: "abrir já
                 filtrado; listou todas e não sei qual é aquele alerta"). Os números e a lista voltam em "Ver todos". --}}
            @if ($doAviso->isEmpty())
            {{-- Números do dia --}}
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    ['calendar', 'bg-brand/10 text-brand-text', 'Compromissos hoje', $numeros['compromissos'], $numeros['da_agenda'].' da agenda · '.$numeros['do_secretario'].' do Secretário', route('secretario.agenda'), 'text-ink-mute'],
                    ['envelope', 'bg-crit-tint text-crit', 'E-mails não lidos (3 dias)', $numeros['nao_lidos'], $numeros['prioritarios'].' prioritário(s)', route('secretario.emails.index', ['ver' => 'todas']), $numeros['prioritarios'] ? 'text-crit' : 'text-ink-mute'],
                    ['check-circle', 'bg-warn-tint text-warn', 'Tarefas vencidas', $numeros['vencidas'], 'Ver tarefas', route('secretario.meu-dia'), 'text-brand-text'],
                    ['bell', 'bg-good-tint text-good', 'Alertas ativos', $numeros['alertas'], $silencio ? 'Avisos em silêncio agora' : 'Requerem atenção', '#tabela', 'text-ink-mute'],
                ] as [$icone, $corIcone, $rotulo, $n, $sub, $link, $corSub])
                    <a href="{{ $link }}" class="flex items-center gap-3 rounded-panel border border-line bg-surface px-3.5 py-3.5 hover:border-brand">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-ctl {{ $corIcone }}"><span class="h-6 w-6"><x-nav-icon :name="$icone" peso="1.7" /></span></span>
                        <span class="min-w-0">
                            <span class="block font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">{{ $rotulo }}</span>
                            <span class="block font-display text-[21px] font-semibold leading-tight {{ $rotulo === 'Tarefas vencidas' && $n ? 'text-crit' : 'text-ink' }}">{{ $n }}</span>
                            <span class="block text-[12.5px] leading-snug {{ $corSub }}">{{ $sub }}</span>
                        </span>
                    </a>
                @endforeach
            </div>

            @endif

            {{-- O que o aviso do sino trouxe (22/10/2026): os itens dele, com a ação de cada um. --}}
            @if ($doAviso->isNotEmpty())
                <section id="do-aviso" class="scroll-mt-20 rounded-panel border border-line bg-warn-tint">
                    <div class="flex items-baseline justify-between gap-2 border-b border-line px-4 py-2.5">
                        <h2 class="text-[14px] font-semibold text-ink">Do aviso: o que precisa de você ({{ $doAviso->count() }})</h2>
                        <a href="{{ route('secretario.acompanhamentos') }}" class="text-[12.5px] font-semibold text-brand-text hover:underline">Ver todos os acompanhamentos</a>
                    </div>
                    <ul class="divide-y divide-line">
                        @foreach ($doAviso as $a)
                            @php $ativo = $a->situacao === \App\Models\SecretarioAcompanhamento::ATIVO && ! ($a->adiado_ate && $a->adiado_ate->isFuture()); @endphp
                            <li class="flex flex-wrap items-center gap-3 px-4 py-2.5">
                                <span class="min-w-0 flex-1 text-[13px] {{ $ativo ? 'text-ink' : 'text-ink-faint line-through' }}">
                                    @if ($a->prioridade === 'alta')<span class="mr-1 font-semibold text-crit">●</span>@endif{{ $a->texto }}
                                </span>
                                @if ($ativo)
                                    @if ($a->link)
                                        <a href="{{ $a->link }}" class="inline-flex h-[28px] items-center rounded-control bg-brand px-3 text-[12px] font-semibold text-on-brand hover:bg-brand-bright">Abrir</a>
                                    @endif
                                    <form method="POST" action="{{ route('secretario.acompanhamentos.resolver', $a->id) }}">@csrf
                                        <button class="inline-flex h-[28px] items-center rounded-control border border-btn-line px-3 text-[12px] font-semibold text-ink-dim hover:text-ink">Resolvido</button></form>
                                    <form method="POST" action="{{ route('secretario.acompanhamentos.adiar', $a->id) }}">@csrf
                                        <button class="inline-flex h-[28px] items-center rounded-control border border-btn-line px-3 text-[12px] font-semibold text-ink-dim hover:text-ink">Adiar até amanhã</button></form>
                                @else
                                    <span class="text-[12px] text-ink-faint">{{ $a->situacao === \App\Models\SecretarioAcompanhamento::ATIVO ? 'adiado até '.$a->adiado_ate->format('d/m H:i') : 'resolvido' }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($doAviso->isEmpty())
            {{-- A tabela --}}
            <section id="tabela" class="scroll-mt-20 rounded-panel border border-line bg-surface">
                <div class="flex flex-wrap items-center gap-2 border-b border-line px-3 pt-2">
                    <nav class="flex w-full min-w-0 gap-1 overflow-x-auto">
                        @foreach (['todos' => 'Todos'] + \App\Services\Secretario\CentralDeGestao::ABAS as $k => $rotulo)
                            @php $n = $k === 'todos' ? $linhas->count() : ($porAba[$k] ?? 0); @endphp
                            <button type="button" @click="aba = '{{ $k }}'" class="flex shrink-0 items-center gap-1.5 border-b-2 px-2.5 pb-2 pt-1 text-[13px]"
                                    :class="aba === '{{ $k }}' ? 'border-brand font-semibold text-ink' : 'border-transparent text-ink-mute hover:text-ink'">
                                {{ $rotulo }}
                                <span class="rounded-full px-1.5 text-[11px] font-semibold" :class="aba === '{{ $k }}' ? 'bg-brand text-on-brand' : 'bg-head text-ink-mute'">{{ $n }}</span>
                            </button>
                        @endforeach
                    </nav>
                    <div class="flex w-full justify-end gap-2 pb-2">
                        <label class="relative min-w-0 flex-1 md:w-[220px]">
                            <span class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-ink-faint"><x-nav-icon name="search" /></span>
                            <input type="search" x-model="busca" placeholder="Buscar acompanhamentos…" class="w-full rounded-control border border-btn-line bg-canvas py-2 pl-8 pr-2 text-[13px] text-ink placeholder:text-ink-faint focus:border-brand focus:outline-none">
                        </label>
                        <button type="button" @click="filtros = !filtros" class="inline-flex h-[30px] items-center gap-1.5 rounded-control border px-2.5 text-[12.5px] font-semibold"
                                :class="(filtros || prio || origem) ? 'border-brand text-brand-text' : 'border-btn-line text-ink-dim hover:text-brand'">
                            <span class="h-4 w-4"><x-nav-icon name="funil" /></span> Filtros
                        </button>
                    </div>
                    <div x-show="filtros" x-cloak class="flex w-full flex-wrap gap-2 pb-2">
                        <select x-model="prio" class="rounded-control border border-btn-line bg-canvas px-2 py-1.5 text-[13px] text-ink">
                            <option value="">Toda prioridade</option><option value="alta">Alta</option><option value="normal">Média</option><option value="baixa">Baixa</option>
                        </select>
                        <select x-model="origem" class="rounded-control border border-btn-line bg-canvas px-2 py-1.5 text-[13px] text-ink">
                            <option value="">Toda origem</option><option value="Ponto de gestão">Pontos lançados</option><option value="Alerta · regra">Alertas das regras</option><option value="Gestor">Gestor (Grupo Soluções)</option><option value="VixOrganize">VixOrganize (VixCard)</option>
                        </select>
                        <button type="button" @click="prio = ''; origem = ''; busca = ''" class="text-[12.5px] text-ink-mute hover:text-brand">limpar</button>
                    </div>
                </div>

                {{-- Em grupos, um por categoria, com os 5 primeiros e "ver todos" (Felipe, 22/10/2026: "a tela está
                     infinita"). Busca e filtro mostram tudo o que casa. Status e prioridade dividem a coluna, e os
                     botões empilham: com seis colunas a tabela rolava e cortava os botões. --}}
                <x-tabela min="640px" class="!rounded-none !border-0 !bg-transparent">
                        <thead>
                            <tr class="border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                                <th class="w-1/2 px-4 py-2.5 font-normal">Assunto</th><th class="px-3 py-2.5 font-normal">Cliente / origem</th>
                                <th class="px-3 py-2.5 font-normal">Prazo</th><th class="px-3 py-2.5 font-normal">Situação</th><th class="w-[92px] px-2 py-2.5"></th>
                            </tr>
                        </thead>
                        @foreach (\App\Services\Secretario\CentralDeGestao::ABAS as $grupo => $rotuloGrupo)
                            @php $doGrupo = $linhas->where('aba', $grupo)->values(); @endphp
                            @continue($doGrupo->isEmpty())
                            <tbody x-show="aba === 'todos' || aba === '{{ $grupo }}'" x-data="{ todos: false }" class="divide-y divide-line border-b border-line">
                                <tr class="bg-head/60">
                                    <td colspan="5" class="px-4 py-2">
                                        <span class="text-[12.5px] font-semibold text-ink">{{ $rotuloGrupo }}</span>
                                        <span class="ml-1.5 rounded-full bg-surface px-1.5 text-[11px] font-semibold text-ink-mute">{{ $doGrupo->count() }}</span>
                                    </td>
                                </tr>
                                @foreach ($doGrupo as $i => $l)
                                    @php
                                        $chave = ['aba' => $l['aba'], 'prio' => $l['prioridade'], 'fonte' => $l['chip'],
                                            'texto' => \App\Services\Secretario\Memoria\BuscaNaMemoria::normalizar($l['assunto'].' '.$l['sub'].' '.$l['origem'])];
                                        [$prioCor, $prioPonto, $prioRotulo] = $corPrio[$l['prioridade']] ?? $corPrio['normal'];
                                    @endphp
                                    <tr x-show="mostra(@js($chave)) && (todos || {{ $i }} < 5 || busca || prio || origem)" class="align-top hover:bg-head/50">
                                        <td class="px-4 py-3">
                                            <div class="flex gap-3">
                                                <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-ctl bg-head text-ink-dim"><span class="h-[18px] w-[18px]"><x-nav-icon :name="$l['icone']" peso="1.7" /></span></span>
                                                <div class="min-w-0">
                                                    <p class="line-clamp-2 text-[13.5px] font-semibold text-ink" title="{{ $l['assunto'] }}">
                                                        @if ($l['link'])<a href="{{ $l['link'] }}" @if (! str_starts_with($l['link'], url('/'))) target="_blank" rel="noopener noreferrer" @endif class="hover:text-brand">{{ $l['assunto'] }}</a>@else{{ $l['assunto'] }}@endif
                                                    </p>
                                                    <p class="text-[12px] text-ink-mute">{{ $l['sub'] }}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-3 py-3">
                                            <p class="line-clamp-2 max-w-[200px] text-[13px] text-ink" title="{{ $l['origem'] }}">{{ $l['origem'] }}</p>
                                            <span class="mt-1 inline-block rounded-badge bg-brand/10 px-1.5 py-0.5 text-[11px] text-brand-text">{{ $l['chip'] }}</span>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-3">
                                            @if ($l['quando'])
                                                <p class="text-[13px] font-semibold {{ in_array($l['prazo'], ['Hoje', 'Vencido'], true) ? 'text-crit' : ($l['prazo'] === 'Amanhã' ? 'text-warn' : 'text-brand-text') }}">{{ $l['prazo'] }}</p>
                                                <p class="text-[12px] text-ink-mute">{{ $l['quando']->format('d/m') }}{{ $l['so_data'] ? '' : ' '.$l['quando']->format('H:i') }}</p>
                                            @else
                                                <p class="text-[13px] text-ink-mute">Sem prazo</p>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3">
                                            <div class="flex flex-col items-start gap-1">
                                                <span class="inline-block whitespace-nowrap rounded-control px-2 py-0.5 text-[12px] font-semibold {{ $corStatus($l['status']) }}">{{ $l['status'] }}</span>
                                                <span class="inline-flex items-center gap-1.5 rounded-control px-2 py-0.5 text-[12px] font-semibold {{ $prioCor }}"><i class="h-2 w-2 rounded-full {{ $prioPonto }}"></i>{{ $prioRotulo }}</span>
                                            </div>
                                        </td>
                                        <td class="px-2 py-3">
                                            {{-- Botões na linha, e não menu suspenso: a tabela rola por dentro e cortaria o menu. --}}
                                            <div class="flex flex-col items-stretch gap-1">
                                                @foreach ($l['acoes'] as [$rotulo, $url])
                                                    <form method="POST" action="{{ $url }}">@csrf
                                                        <button title="{{ $rotulo }}" class="h-[28px] w-full whitespace-nowrap rounded-control border border-btn-line px-2 text-[11.5px] font-semibold text-ink-dim hover:border-brand hover:text-brand">{{ $rotulo === 'Adiar 1 dia' ? 'Adiar' : $rotulo }}</button>
                                                    </form>
                                                @endforeach
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                                @if ($doGrupo->count() > 5)
                                    <tr x-show="!busca && !prio && !origem">
                                        <td colspan="5" class="px-4 py-2 text-center">
                                            <button type="button" @click="todos = !todos" class="text-[12.5px] font-semibold text-brand-text hover:text-brand"
                                                    x-text="todos ? 'Mostrar só os 5 primeiros' : 'Ver todos os {{ $doGrupo->count() }}'"></button>
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                        @endforeach
                </x-tabela>
                @if ($linhas->isEmpty())
                    <p class="px-4 py-6 text-center text-[13.5px] text-ink-mute">Nada pedindo acompanhamento agora.</p>
                @endif
                <p class="border-t border-line px-4 py-2.5 text-[12px] text-ink-mute">
                    @if ($gestor)
                        Gestor lido às {{ $gestor['lido_em']->format('H:i') }}{{ $gestor['velho'] ? ' (desatualizado)' : '' }}: mostra os itens mais antigos de cada bloco —
                        {{ collect($gestor['blocos'])->filter(fn ($b) => $b['quantidade'] > 0)->map(fn ($b) => $b['quantidade'].' '.$b['rotulo'])->implode(' · ') }}.
                    @else
                        O Gestor ainda não foi lido pela sua máquina.
                    @endif
                    @if ($dispensados) · {{ $dispensados }} alerta(s) marcado(s) como resolvido(s). @endif
                </p>
            </section>
            @endif
        </div>

        <aside class="grid min-w-0 grid-cols-1 items-start gap-4 md:grid-cols-3 2xl:block 2xl:space-y-4">
            <section class="rounded-panel border border-line bg-surface p-4">
                <div class="mb-3 flex items-center gap-2">
                    <span class="h-5 w-5 text-brand-text"><x-nav-icon name="clock" peso="1.7" /></span>
                    <h2 class="font-display text-[15px] font-semibold text-ink">Lançar ponto de gestão</h2>
                </div>
                <form method="POST" action="{{ route('secretario.acompanhamentos.pontos') }}" class="space-y-2.5">
                    @csrf
                    <select name="empresa" class="{{ $campo }}">
                        @foreach (\App\Services\Secretario\CentralDeGestao::EMPRESAS as $k => $nome)
                            <option value="{{ $k }}" @selected(old('empresa', 'vixcard') === $k)>{{ $nome }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="titulo" x-ref="ponto" value="{{ old('titulo') }}" maxlength="190" required placeholder="O que precisa da sua decisão?" class="{{ $campo }}">
                    @error('titulo')<p class="text-[12px] text-crit">{{ $message }}</p>@enderror
                    <div class="grid grid-cols-[minmax(0,1fr)_110px] gap-2">
                        <input type="date" name="prazo" value="{{ old('prazo') }}" class="{{ $campo }}" title="Prazo (opcional)">
                        <select name="prioridade" class="{{ $campo }}">
                            @foreach (['normal' => 'normal', 'alta' => 'alta', 'baixa' => 'baixa'] as $k => $r)
                                <option value="{{ $k }}" @selected(old('prioridade', 'normal') === $k)>{{ $r }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="h-[30px] w-full rounded-control bg-brand text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright">Lançar</button>
                    <p class="text-[12px] text-ink-mute">Com prazo, o Secretário lembra às 08:00 do dia e avisa se vencer.</p>
                </form>
            </section>

            <section class="rounded-panel border border-line bg-surface p-4">
                <div class="mb-2 flex items-center gap-2">
                    <span class="h-5 w-5 text-brand-text"><x-nav-icon name="calendar" peso="1.7" /></span>
                    <h2 class="font-display text-[15px] font-semibold text-ink">Resumo por status</h2>
                </div>
                <ul class="space-y-2">
                    @forelse ($resumo as $status => $n)
                        <li class="flex items-center gap-2.5 text-[13.5px] text-ink">
                            <i class="h-2.5 w-2.5 rounded-full {{ $status === 'Vencido' ? 'bg-crit' : (in_array($status, ['Em aberto', 'Em elaboração'], true) ? 'bg-brand' : (in_array($status, ['Adiado', 'Parado'], true) ? 'bg-ink-faint' : 'bg-warn')) }}"></i>
                            <span class="flex-1">{{ $status }}</span><span class="font-semibold">{{ $n }}</span>
                        </li>
                    @empty
                        <li class="text-[13px] text-ink-mute">Nada em aberto.</li>
                    @endforelse
                </ul>
            </section>

            <section class="rounded-panel border border-line bg-surface">
                <div class="flex items-center gap-2 px-4 pt-4">
                    <span class="h-5 w-5 text-brand-text"><x-nav-icon name="clock" peso="1.7" /></span>
                    <h2 class="font-display text-[15px] font-semibold text-ink">Atividade recente</h2>
                </div>
                <ul class="mt-2 divide-y divide-line">
                    @forelse ($atividade as $a)
                        <li class="flex items-start gap-3 px-4 py-2.5">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand/10 text-brand-text"><span class="h-4 w-4"><x-nav-icon :name="$a['icone']" peso="1.7" /></span></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[13px] font-semibold text-ink">{{ $a['titulo'] }}</p>
                                <p class="truncate text-[12px] text-ink-mute">{{ $a['sub'] }}</p>
                            </div>
                            <span class="shrink-0 text-[11.5px] text-ink-faint">{{ $a['em']->isToday() ? $a['em']->format('H:i') : $a['em']->format('d/m') }}</span>
                        </li>
                    @empty
                        <li class="px-4 py-3 text-[13px] text-ink-mute">Nada ainda.</li>
                    @endforelse
                </ul>
            </section>
        </aside>
    </div>
</x-app-layout>
