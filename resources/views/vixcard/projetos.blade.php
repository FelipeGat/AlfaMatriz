<x-app-layout>
    <x-slot name="titulo">Projetos</x-slot>
    <x-slot name="contexto"><span>{{ $totais['projetos'] }} produtos · {{ $totais['em_andamento'] }} tarefas em andamento</span></x-slot>

    {{-- docs/specs/acesso-vixcard: o gestor bate o olho e vê cada produto — o que
         é, como está, quanto andou e o que roda agora. Card ou lista; a escolha
         fica no navegador (só conveniência: sem armazenamento, abre em card). --}}
    @php
        $situacoes = [
            'atrasado' => ['critico', 'Atrasado', 'bg-crit'],
            'atencao' => ['atencao', 'Atenção', 'bg-warn'],
            'em_dia' => ['bom', 'Em dia', 'bg-good'],
            'concluido' => ['marca', 'Concluído', 'bg-brand'],
            'sem_tarefas' => ['neutro', 'A começar', 'bg-ink-faint'],
        ];
        $quando = fn ($c) => $c ? \Carbon\Carbon::parse($c->data)->format('d/m').($c->hora ? ' '.substr($c->hora, 0, 5) : '') : null;
    @endphp
    <div x-data="{ modo: (() => { try { return localStorage.getItem('vixcard:modo') || 'card' } catch (e) { return 'card' } })(),
                   trocar(m) { this.modo = m; try { localStorage.setItem('vixcard:modo', m) } catch (e) {} } }"
         class="mx-auto w-full max-w-7xl space-y-4">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1">
                <h1 class="font-display text-[22px] font-semibold leading-tight text-ink">{{ \App\Services\PainelVixcard::PASTAS[$pasta][0] }}</h1>
                <p class="mt-0.5 text-[13px] text-ink-mute">Como está cada produto, de relance.</p>
            </div>
            {{-- As duas pastas de Comercial › Projetos (19/10/2026). A conta VixCard só tem a dela. --}}
            @if ($pastas)
                <nav class="inline-flex rounded-control border border-btn-line p-0.5" aria-label="Pastas de projetos">
                    @foreach ($pastas as $k => [$rotulo])
                        <a href="{{ route('vixcard.projetos', $k === 'vixcard' ? [] : ['pasta' => $k]) }}" @if ($pasta === $k) aria-current="page" @endif
                           class="inline-flex h-[26px] items-center gap-1.5 rounded-[6px] px-2.5 text-[12.5px] font-semibold {{ $pasta === $k ? 'bg-head text-ink' : 'text-ink-dim hover:text-brand' }}">
                            <span class="h-4 w-4"><x-nav-icon name="arquivo" /></span>{{ $rotulo }}
                        </a>
                    @endforeach
                </nav>
            @endif
            <div class="inline-flex rounded-control border border-btn-line p-0.5" role="group" aria-label="Modo de exibição">
                @foreach (['card' => ['view-grid', 'Cards'], 'lista' => ['view-list', 'Lista']] as $m => [$icone, $rotulo])
                    <button type="button" @click="trocar('{{ $m }}')" :aria-pressed="modo === '{{ $m }}'"
                            class="inline-flex h-[26px] items-center gap-1.5 rounded-[6px] px-2.5 text-[12.5px] font-semibold"
                            :class="modo === '{{ $m }}' ? 'bg-brand text-on-brand' : 'text-ink-dim hover:text-brand'">
                        <span class="h-4 w-4"><x-nav-icon :name="$icone" /></span>{{ $rotulo }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Números do topo --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ([
                ['Produtos', $totais['projetos'], 'text-ink'],
                ['Em andamento', $totais['em_andamento'], 'text-brand-text'],
                ['Atrasadas', $totais['atrasadas'], $totais['atrasadas'] ? 'text-crit' : 'text-ink'],
                ['Entregas em 7 dias', $totais['entregas_semana'], 'text-good'],
            ] as [$rotulo, $n, $cor])
                <div class="rounded-panel border border-line bg-surface px-3.5 py-3">
                    <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">{{ $rotulo }}</p>
                    <p class="font-display text-[21px] font-semibold leading-tight {{ $cor }}">{{ $n }}</p>
                </div>
            @endforeach
        </div>

        @if ($projetos->isEmpty())
            <div class="rounded-panel border border-line bg-surface px-4 py-8 text-center text-[13.5px] text-ink-mute">
                Nenhum produto cadastrado nesta pasta ainda.
            </div>
        @endif

        {{-- Cards --}}
        <div x-show="modo === 'card'" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($projetos as $p)
                @php [$tom, $rotulo, $barra] = $situacoes[$p['situacao']]; @endphp
                <a href="{{ route('tarefas.index', ['sistema' => $p['sistema']->id]) }}"
                   class="flex flex-col rounded-panel border border-line bg-surface p-4 transition hover:border-brand">
                    <div class="flex items-start gap-2">
                        <h2 class="min-w-0 flex-1 truncate font-display text-[16px] font-semibold text-ink">{{ $p['sistema']->nome }}</h2>
                        <x-badge :tom="$tom" ponto>{{ $rotulo }}</x-badge>
                    </div>
                    <p class="mt-1 line-clamp-2 min-h-[2.5em] text-[12.5px] text-ink-mute">{{ $p['resumo'] ?: 'Sem resumo ainda.' }}</p>

                    <div class="mt-3 flex items-center gap-2">
                        <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-head"><span class="block h-full {{ $barra }}" style="width: {{ $p['progresso'] }}%"></span></span>
                        <span class="font-mono text-[11.5px] text-ink-dim">{{ $p['progresso'] }}%</span>
                    </div>
                    <p class="mt-1 text-[12px] {{ $p['situacao'] === 'atrasado' ? 'text-crit' : 'text-ink-mute' }}">{{ $p['motivo'] }} · {{ $p['concluidas'] }}/{{ $p['total'] }} concluídas</p>

                    <div class="mt-3 space-y-1 border-t border-line pt-3">
                        <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Agora</p>
                        @forelse ($p['agora'] as $t)
                            <p class="truncate text-[13px] text-ink" title="{{ $t['titulo'] }}">
                                <span class="text-ink-mute">{{ $t['etapa'] }} ·</span> {{ $t['titulo'] }}@if ($t['quem'])<span class="text-ink-faint"> — {{ $t['quem'] }}</span>@endif
                            </p>
                        @empty
                            <p class="text-[13px] text-ink-mute">Nada em andamento.</p>
                        @endforelse
                    </div>

                    <dl class="mt-3 grid grid-cols-3 gap-2 border-t border-line pt-3 text-[12px]">
                        <div class="min-w-0"><dt class="text-ink-faint">Próximo prazo</dt>
                            <dd class="truncate text-ink" title="{{ $p['proximo_prazo']['titulo'] ?? '' }}">{{ $p['proximo_prazo'] ? $p['proximo_prazo']['data']->format('d/m') : '—' }}</dd></div>
                        <div class="min-w-0"><dt class="text-ink-faint">Última entrega</dt>
                            <dd class="truncate text-ink" title="{{ $p['entrega']['titulo'] ?? '' }}">{{ $p['entrega'] ? $p['entrega']['em']->format('d/m') : '—' }}</dd></div>
                        <div class="min-w-0"><dt class="text-ink-faint">Próxima reunião</dt>
                            <dd class="truncate text-ink" title="{{ $p['reuniao']?->titulo }}">{{ $quando($p['reuniao']) ?? '—' }}</dd></div>
                    </dl>
                </a>
            @endforeach
        </div>

        {{-- Reuniões que o dono compartilhou com esta conta (secretario-reuniao-produto). --}}
        @if ($reunioes->isNotEmpty())
            <section class="rounded-panel border border-line bg-surface">
                <p class="flex items-center justify-between border-b border-line px-4 py-2.5 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                    Reuniões dos projetos <a href="{{ route('reunioes.compartilhadas') }}" class="normal-case tracking-normal text-brand-text hover:underline">ver todas</a>
                </p>
                <ul class="divide-y divide-line">
                    @foreach ($reunioes as $r)
                        <li><a href="{{ route('reunioes.compartilhada', $r->id) }}" class="flex items-center gap-3 px-4 py-2.5 hover:bg-head/50">
                            <span class="w-[78px] shrink-0 text-[12px] text-ink-mute">{{ $r->created_at->format('d/m H:i') }}</span>
                            <span class="min-w-0 flex-1"><span class="block truncate text-[13.5px] font-semibold text-ink">{{ $r->titulo }}</span>
                                <span class="block truncate text-[12px] text-ink-mute">{{ $r->resumo ?: 'Ainda em análise.' }}</span></span>
                            <span class="shrink-0 rounded-badge bg-head px-2 py-0.5 text-[11.5px] text-ink-dim">{{ $r->sistema?->nome }}</span>
                        </a></li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- Lista --}}
        <div x-show="modo === 'lista'" x-cloak>
            <x-tabela min="860px">
                <thead>
                    <tr class="border-b border-line bg-head font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                        <th class="px-4 py-2.5 font-semibold">Produto</th>
                        <th class="px-3 py-2.5 font-semibold">Situação</th>
                        <th class="px-3 py-2.5 font-semibold">Andamento</th>
                        <th class="px-3 py-2.5 font-semibold">Agora</th>
                        <th class="px-3 py-2.5 font-semibold">Próximo prazo</th>
                        <th class="px-3 py-2.5 font-semibold">Última entrega</th>
                        <th class="px-3 py-2.5 font-semibold">Reunião</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($projetos as $p)
                        @php [$tom, $rotulo, $barra] = $situacoes[$p['situacao']]; @endphp
                        <tr class="align-top hover:bg-head/50">
                            <td class="px-4 py-3">
                                <a href="{{ route('tarefas.index', ['sistema' => $p['sistema']->id]) }}" class="font-display text-[14px] font-semibold text-ink hover:text-brand">{{ $p['sistema']->nome }}</a>
                                <p class="line-clamp-1 max-w-[260px] text-[12px] text-ink-mute" title="{{ $p['resumo'] }}">{{ $p['resumo'] ?: '—' }}</p>
                            </td>
                            <td class="px-3 py-3">
                                <x-badge :tom="$tom" ponto>{{ $rotulo }}</x-badge>
                                <p class="mt-1 max-w-[180px] text-[11.5px] text-ink-mute">{{ $p['motivo'] }}</p>
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex w-[120px] items-center gap-2">
                                    <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-head"><span class="block h-full {{ $barra }}" style="width: {{ $p['progresso'] }}%"></span></span>
                                    <span class="font-mono text-[11.5px] text-ink-dim">{{ $p['progresso'] }}%</span>
                                </div>
                                <p class="mt-1 text-[11.5px] text-ink-mute">{{ $p['concluidas'] }}/{{ $p['total'] }} concluídas</p>
                            </td>
                            <td class="px-3 py-3 text-[12.5px] text-ink">
                                @if ($p['agora'])
                                    <p class="line-clamp-1 max-w-[220px]" title="{{ $p['agora'][0]['titulo'] }}">{{ $p['agora'][0]['titulo'] }}</p>
                                    <p class="text-[11.5px] text-ink-mute">{{ $p['em_andamento'] }} em andamento</p>
                                @else
                                    <span class="text-ink-mute">—</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-3 text-[12.5px] text-ink">{{ $p['proximo_prazo'] ? $p['proximo_prazo']['data']->format('d/m') : '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-[12.5px] text-ink">{{ $p['entrega'] ? $p['entrega']['em']->format('d/m') : '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-[12.5px] text-ink" title="{{ $p['reuniao']?->titulo }}">{{ $quando($p['reuniao']) ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-tabela>
        </div>
    </div>
</x-app-layout>
