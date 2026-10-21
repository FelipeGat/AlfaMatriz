<x-app-layout>
    <x-slot name="titulo">Projetos VixCard</x-slot>
    <x-slot name="contexto"><span>VixOrganize · {{ $conta->lido_em ? 'lido às '.$conta->lido_em->format('H:i') : 'ainda não lido' }}{{ $conta->velho() ? ' (desatualizado)' : '' }}</span></x-slot>
    <x-slot name="acoes">
        <form method="POST" action="{{ route('secretario.vixorganize.atualizar') }}">@csrf
            <button class="h-[30px] px-3 inline-flex items-center gap-1.5 rounded-control border border-btn-line text-ink-dim text-[12.5px] font-semibold hover:text-brand hover:border-brand transition whitespace-nowrap">
                <span class="h-4 w-4"><x-nav-icon name="repeat" /></span> Atualizar
            </button>
        </form>
    </x-slot>

    {{-- docs/specs/secretario-vixorganize: a fila vem do retrato (lido a cada 10 min
         e logo depois de cada envio); tudo o que se lança aqui vai na hora para o
         VixOrganize, com "(via AlfaMatriz)" no histórico de lá. --}}
    @php
        $campo = 'w-full rounded-control border border-btn-line bg-canvas px-2.5 py-1.5 text-[13px] text-ink focus:border-brand focus:outline-none';
        $botao = 'h-[28px] whitespace-nowrap rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim hover:border-brand hover:text-brand';
        $corStatus = ['aguardando' => 'bg-head text-ink-dim', 'em_andamento' => 'bg-brand/10 text-brand-text', 'pausado' => 'bg-warn-tint text-warn', 'concluido' => 'bg-good-tint text-good'];
        $hoje = now()->startOfDay();
        $vencido = fn ($p) => ! empty($p['due_date']) && $p['status'] !== 'concluido' && \Carbon\Carbon::parse($p['due_date'])->lt($hoje);
        [$abertos, $concluidos] = collect($projetos)->partition(fn ($p) => $p['status'] !== 'concluido');
        $emAndamento = $abertos->where('status', 'em_andamento')->count();
    @endphp
    <div x-data="{ novo: {{ $errors->has('name') ? 'true' : 'false' }} }" class="mx-auto w-full max-w-7xl space-y-4">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1">
                <h1 class="font-display text-[22px] font-semibold leading-tight text-ink">Projetos VixCard</h1>
                <p class="mt-0.5 text-[13px] text-ink-mute">A fila do VixOrganize: o que você lança aqui vai direto para lá.</p>
            </div>
            <button type="button" @click="novo = !novo" class="inline-flex h-[30px] items-center gap-2 rounded-control bg-brand px-4 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright">
                <span class="h-4 w-4"><x-nav-icon name="plus" peso="2" /></span> Novo projeto
            </button>
        </div>
        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif
        @if (session('erro'))
            <x-aviso tom="critico" :segundos="0">{{ session('erro') }}</x-aviso>
        @endif
        @if ($conta->erro)
            <x-aviso tom="atencao" :segundos="0">Última leitura falhou ({{ $conta->erro_em?->format('d/m H:i') }}): {{ $conta->erro }} Mostrando o retrato de {{ $conta->lido_em?->format('d/m H:i') ?? '—' }}.</x-aviso>
        @endif

        <form x-show="novo" x-cloak method="POST" action="{{ route('secretario.vixorganize.projetos') }}" class="grid gap-2 rounded-panel border border-line bg-surface p-4 sm:grid-cols-[minmax(0,1fr)_160px]">
            @csrf
            <input name="name" value="{{ old('name') }}" maxlength="120" required placeholder="Nome do projeto" class="{{ $campo }}">
            <input name="due_date" type="date" value="{{ old('due_date') }}" title="Prazo (opcional)" class="{{ $campo }}">
            <textarea name="description" rows="2" maxlength="5000" placeholder="Descrição (opcional)" class="{{ $campo }} sm:col-span-2">{{ old('description') }}</textarea>
            @error('name')<p class="text-[12px] text-crit sm:col-span-2">{{ $message }}</p>@enderror
            <div class="flex items-center gap-2 sm:col-span-2">
                <button class="h-[30px] rounded-control bg-brand px-3 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright">Criar no VixOrganize</button>
                <span class="text-[12px] text-ink-mute">Entra no fim da fila, com as 6 fases padrão. A ordem da fila o Victor ajusta lá.</span>
            </div>
        </form>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-[300px_minmax(0,1fr)]">
            {{-- A fila --}}
            <aside class="min-w-0 rounded-panel border border-line bg-surface">
                <div class="flex items-center gap-2 border-b border-line px-4 py-3">
                    <h2 class="flex-1 font-display text-[15px] font-semibold text-ink">Fila</h2>
                    <span class="text-[12px] text-ink-mute">{{ $emAndamento }} em andamento</span>
                </div>
                <ul class="divide-y divide-line">
                    @forelse ($abertos as $p)
                        <li>
                            <a href="{{ route('secretario.vixorganize', ['projeto' => $p['slug']]) }}"
                               class="block px-4 py-2.5 hover:bg-head/50 {{ ($aberto['slug'] ?? null) === $p['slug'] ? 'bg-brand/5 border-l-2 border-brand' : '' }}">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-[11px] text-ink-faint">#{{ $p['rank'] }}</span>
                                    <span class="min-w-0 flex-1 truncate text-[13.5px] font-semibold text-ink" title="{{ $p['name'] }}">{{ $p['name'] }}</span>
                                    <span class="shrink-0 rounded-badge px-1.5 py-0.5 text-[11px] font-semibold {{ $corStatus[$p['status']] ?? 'bg-head text-ink-dim' }}">{{ $p['status_label'] }}</span>
                                </div>
                                <div class="mt-1.5 flex items-center gap-2">
                                    <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-head"><span class="block h-full bg-brand" style="width: {{ (int) $p['progress'] }}%"></span></span>
                                    <span class="font-mono text-[11px] text-ink-mute">{{ (int) $p['progress'] }}%</span>
                                </div>
                                <p class="mt-1 truncate text-[12px] {{ $vencido($p) ? 'text-crit' : 'text-ink-mute' }}">
                                    {{ $p['current_phase'] ?? 'sem fase' }}@if (! empty($p['due_date'])) · {{ $vencido($p) ? 'venceu ' : 'até ' }}{{ \Carbon\Carbon::parse($p['due_date'])->format('d/m') }}@endif
                                </p>
                            </a>
                        </li>
                    @empty
                        <li class="px-4 py-4 text-[13px] text-ink-mute">Nenhum projeto aberto no VixOrganize.</li>
                    @endforelse
                </ul>
                @if ($concluidos->isNotEmpty())
                    <details class="border-t border-line">
                        <summary class="cursor-pointer px-4 py-2.5 text-[12.5px] text-ink-mute hover:text-brand">Concluídos ({{ $concluidos->count() }})</summary>
                        <ul class="divide-y divide-line">
                            @foreach ($concluidos as $p)
                                <li><a href="{{ route('secretario.vixorganize', ['projeto' => $p['slug']]) }}" class="block truncate px-4 py-2 text-[13px] text-ink-dim hover:text-brand">{{ $p['name'] }}</a></li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </aside>

            {{-- O projeto aberto --}}
            <section class="min-w-0 space-y-4">
                @if (! $aberto)
                    <div class="rounded-panel border border-line bg-surface px-4 py-8 text-center text-[13.5px] text-ink-mute">
                        {{ request('projeto') ? 'Esse projeto não está no último retrato da fila.' : 'Escolha um projeto na fila, ou crie um novo.' }}
                    </div>
                @else
                    @php $fases = $aberto['phases'] ?? null; @endphp
                    <div class="rounded-panel border border-line bg-surface p-4">
                        <div class="flex flex-wrap items-start gap-3">
                            <div class="min-w-0 basis-full sm:basis-0 sm:flex-1">
                                <p class="font-mono text-[11px] text-ink-faint">#{{ $aberto['rank'] }} na fila{{ ! empty($aberto['owner']) ? ' · responsável '.$aberto['owner'] : '' }}</p>
                                <h2 class="font-display text-[19px] font-semibold leading-tight text-ink">{{ $aberto['name'] }}</h2>
                                <p class="mt-0.5 text-[12.5px] {{ $vencido($aberto) ? 'text-crit' : 'text-ink-mute' }}">
                                    Fase atual: {{ $aberto['current_phase'] ?? '—' }} · {{ (int) $aberto['progress'] }}% feito
                                    @if (! empty($aberto['due_date'])) · prazo {{ \Carbon\Carbon::parse($aberto['due_date'])->format('d/m/Y') }}{{ $vencido($aberto) ? ' (vencido)' : '' }}@endif
                                </p>
                            </div>
                            <form method="POST" action="{{ route('secretario.vixorganize.status', $aberto['slug']) }}" class="flex items-center gap-1.5">@csrf
                                <select name="status" onchange="this.form.submit()" class="rounded-control border border-btn-line bg-canvas py-1 pl-2 pr-8 text-[12.5px] text-ink" title="Status no VixOrganize">
                                    @foreach (\App\Http\Controllers\SecretarioVixOrganizeController::STATUS as $k => $rotulo)
                                        <option value="{{ $k }}" @selected($aberto['status'] === $k)>{{ $rotulo }}</option>
                                    @endforeach
                                </select>
                                <noscript><button class="{{ $botao }}">Mudar</button></noscript>
                            </form>
                            <a href="{{ $conta->linkDoProjeto($aberto['slug']) }}" target="_blank" rel="noopener noreferrer" class="{{ $botao }} inline-flex items-center">Abrir no VixOrganize</a>
                        </div>
                        @if (! empty($aberto['description']))
                            <p class="mt-2 whitespace-pre-line text-[13px] text-ink-dim">{{ $aberto['description'] }}</p>
                        @endif

                        @if ($fases !== null)
                            {{-- Nova demanda: vira passo na fase escolhida (a atual por padrão). --}}
                            <form method="POST" action="{{ route('secretario.vixorganize.passos', $aberto['slug']) }}" class="mt-3 grid gap-2 border-t border-line pt-3 sm:grid-cols-[minmax(0,1fr)_180px_auto]">
                                @csrf
                                <input name="titulo" value="{{ old('titulo') }}" maxlength="200" required placeholder="Nova demanda (ex.: Cotar leitores UHF)" class="{{ $campo }}">
                                <select name="fase" class="{{ $campo }}">
                                    @foreach ($fases as $f)
                                        <option value="{{ $f['id'] }}" @selected((int) old('fase', 0) === $f['id'] || (! old('fase') && $f['name'] === $aberto['current_phase']))>{{ $f['name'] }}{{ $f['completed_at'] ? ' ✓' : '' }}</option>
                                    @endforeach
                                </select>
                                <button class="h-[30px] rounded-control bg-brand px-3 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright">Enviar demanda</button>
                                <input name="notas" value="{{ old('notas') }}" maxlength="4000" placeholder="Observação (opcional)" class="{{ $campo }} sm:col-span-3">
                                @error('titulo')<p class="text-[12px] text-crit sm:col-span-3">{{ $message }}</p>@enderror
                            </form>
                        @endif
                    </div>

                    @if ($fases === null)
                        <div class="rounded-panel border border-line bg-surface px-4 py-6 text-center text-[13.5px] text-ink-mute">Projeto concluído: as fases e os passos estão no VixOrganize.</div>
                    @else
                        <div class="flex gap-3 overflow-x-auto pb-2">
                            @foreach ($fases as $f)
                                @php $feitos = collect($f['steps'])->where('is_done', true)->count(); $atual = $f['name'] === $aberto['current_phase']; @endphp
                                <div class="w-[260px] shrink-0 rounded-panel border {{ $atual ? 'border-brand' : 'border-line' }} bg-surface">
                                    <div class="flex items-center gap-2 border-b border-line px-3 py-2">
                                        <span class="min-w-0 flex-1 truncate text-[13px] font-semibold {{ $f['completed_at'] ? 'text-good' : 'text-ink' }}">{{ $f['completed_at'] ? '✓ ' : '' }}{{ $f['name'] }}</span>
                                        <span class="font-mono text-[11px] text-ink-mute">{{ $feitos }}/{{ count($f['steps']) }}</span>
                                    </div>
                                    <ul class="divide-y divide-line">
                                        @forelse ($f['steps'] as $s)
                                            <li class="px-3 py-2" x-data="{ reg: false }">
                                                <div class="flex items-start gap-2">
                                                    <form method="POST" action="{{ route('secretario.vixorganize.marcar', [$aberto['slug'], $s['id']]) }}">@csrf
                                                        <input type="hidden" name="feito" value="{{ $s['is_done'] ? 0 : 1 }}">
                                                        <button title="{{ $s['is_done'] ? 'Desmarcar' : 'Marcar como feito' }}"
                                                                class="mt-0.5 flex h-[18px] w-[18px] items-center justify-center rounded border {{ $s['is_done'] ? 'border-good bg-good text-on-brand' : 'border-btn-line hover:border-brand' }} text-[11px]">{{ $s['is_done'] ? '✓' : '' }}</button>
                                                    </form>
                                                    <div class="min-w-0 flex-1">
                                                        <p class="text-[13px] leading-snug {{ $s['is_done'] ? 'text-ink-mute line-through' : 'text-ink' }}">{{ $s['title'] }}</p>
                                                        @if (! empty($s['notes']))<p class="line-clamp-3 text-[12px] text-ink-mute" title="{{ $s['notes'] }}">{{ $s['notes'] }}</p>@endif
                                                        @if ($s['is_done'] && ! empty($s['done_at']))
                                                            <p class="text-[11.5px] text-ink-faint">{{ $s['done_by'] ?? 'feito' }} · {{ \Carbon\Carbon::parse($s['done_at'])->timezone(config('app.timezone'))->format('d/m H:i') }}</p>
                                                        @endif
                                                        <button type="button" @click="reg = !reg" class="text-[11.5px] text-ink-mute hover:text-brand">registro</button>
                                                        <form x-show="reg" x-cloak method="POST" action="{{ route('secretario.vixorganize.registrar', [$aberto['slug'], $s['id']]) }}" class="mt-1 space-y-1">@csrf
                                                            <textarea name="texto" rows="2" maxlength="4000" required placeholder="O que aconteceu neste passo" class="{{ $campo }}"></textarea>
                                                            <button class="{{ $botao }}">Enviar registro</button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </li>
                                        @empty
                                            <li class="px-3 py-3 text-[12.5px] text-ink-mute">Sem passos.</li>
                                        @endforelse
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
