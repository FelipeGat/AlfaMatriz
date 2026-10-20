<x-app-layout>
    <x-slot name="titulo">Documentos</x-slot>
    <x-slot name="contexto"><span>{{ $pasta->nome }} · {{ $pasta->lido_em ? 'espelhada às '.$pasta->lido_em->format('H:i') : 'ainda não lida' }}{{ $pasta->velha() ? ' (desatualizada)' : '' }}</span></x-slot>

    {{-- docs/specs/documentos-espelhados + documentos-mao-dupla: o espelho da pasta
         do OneDrive, navegado por níveis (cards ou linhas, à escolha). Quem vê a
         pasta também cria pasta, envia, move e apaga — o Matriz só ANOTA, e a
         máquina do dono aplica no OneDrive. --}}
    @php
        $botao = 'inline-flex h-[28px] items-center gap-1 whitespace-nowrap rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim hover:border-brand hover:text-brand';
        $mini = 'flex h-6 w-6 items-center justify-center rounded-control text-ink-faint hover:bg-head hover:text-brand';
        $icone = fn ($d) => match ($d->tipo()) { 'PDF' => 'document', 'Imagem' => 'imagem', 'Vídeo' => 'play', 'Áudio' => 'speaker-wave', 'Planilha' => 'chart-bar', default => 'arquivo' };
        $novasAqui = $pendentes->where('tipo', 'criar_pasta')->filter(fn ($o) => (str_contains($o->caminho, '/') ? substr($o->caminho, 0, strrpos($o->caminho, '/')) : '') === $nivel);
        $enviosAqui = $pendentes->where('tipo', 'enviar')->filter(fn ($o) => (str_contains($o->caminho, '/') ? substr($o->caminho, 0, strrpos($o->caminho, '/')) : '') === $nivel);
    @endphp
    <div x-data="documentosPasta()" x-init="iniciar()" class="relative mx-auto w-full max-w-7xl space-y-4"
         @dragover.prevent="arrastando = true" @dragleave.self="arrastando = false" @drop.prevent="arrastando = false; enviarTodos($event.dataTransfer.files)">
        {{-- Solte aqui: cobre a tela enquanto um arquivo é arrastado de fora. --}}
        <div x-show="arrastando" x-cloak @dragleave="arrastando = false" class="pointer-events-none fixed inset-0 z-40 flex items-center justify-center bg-brand/10 backdrop-blur-[1px]">
            <div class="rounded-panel border-2 border-dashed border-brand bg-surface px-8 py-6 text-center">
                <span class="mx-auto block h-8 w-8 text-brand-text"><x-nav-icon name="upload" peso="1.7" /></span>
                <p class="mt-2 font-display text-[15px] font-semibold text-ink">Solte para enviar a {{ $nivel === '' ? $pasta->nome : str_replace('/', ' › ', $nivel) }}</p>
                <p class="text-[12.5px] text-ink-mute">Vai para o OneDrive pela sua máquina · até 200 MB por arquivo</p>
            </div>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1">
                <h1 class="truncate font-display text-[22px] font-semibold leading-tight text-ink">{{ $pasta->nome }}</h1>
                <p class="mt-0.5 text-[13px] text-ink-mute">Pasta para gestão de documentos, propostas, projetos, etc.</p>
            </div>
            <form method="GET" class="relative w-full sm:w-[240px]">
                <span class="pointer-events-none absolute left-2.5 top-2 h-4 w-4 text-ink-faint"><x-nav-icon name="search" /></span>
                <input type="search" name="q" value="{{ $busca }}" placeholder="Buscar arquivo ou cliente…" class="w-full rounded-control border border-btn-line bg-canvas py-1.5 pl-8 pr-2 text-[13px] text-ink placeholder:text-ink-faint focus:border-brand focus:outline-none">
            </form>
        </div>
        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif
        @if ($errors->any())
            <x-aviso tom="critico" :segundos="0">{{ $errors->first() }}</x-aviso>
        @endif
        @if ($pasta->velha())
            <x-aviso tom="atencao" :segundos="0">A máquina de {{ $pasta->dono->name }} não espelha esta pasta há mais de {{ \App\Models\DocumentoPasta::VELHO_HORAS }} horas: o que mudou no OneDrive desde então ainda não está aqui, e o que for alterado aqui espera ela voltar.</x-aviso>
        @endif

        @if ($pasta->ehDono(auth()->user()))
            <form x-show="compartilhar" x-cloak method="POST" action="{{ route('documentos.acessos', $pasta) }}" class="rounded-panel border border-line bg-surface p-4">
                @csrf @method('PUT')
                <p class="mb-2 text-[12.5px] text-ink-mute">Quem vê esta pasta além de você — e, vendo, também cria pasta, envia, move e apaga. O perfil não importa: nem o administrador vê sem estar marcado aqui.</p>
                <div class="grid grid-cols-1 gap-1.5 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($pessoas as $u)
                        <label class="flex items-center gap-2 text-[13px] text-ink">
                            <input type="checkbox" name="pessoas[]" value="{{ $u->id }}" @checked($pasta->pessoas->contains($u->id)) class="rounded border-btn-line">{{ $u->name }}
                        </label>
                    @endforeach
                </div>
                <button class="mt-3 h-[30px] rounded-control bg-brand px-3 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright">Salvar acesso</button>
            </form>
        @endif

        {{-- Envios em curso (navegador → Matriz). --}}
        <template x-if="envios.length">
            <section class="rounded-panel border border-line bg-surface px-4 py-3">
                <p class="mb-2 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Enviando</p>
                <template x-for="e in envios" :key="e.id">
                    <div class="flex items-center gap-3 py-1 text-[12.5px]">
                        <span class="min-w-0 flex-1 truncate text-ink" x-text="e.nome"></span>
                        <span class="h-1.5 w-[140px] overflow-hidden rounded-full bg-head"><span class="block h-full rounded-full transition-all" :class="e.erro ? 'bg-crit' : 'bg-brand'" :style="'width:' + e.pct + '%'"></span></span>
                        <span class="w-[150px] text-right" :class="e.erro ? 'text-crit' : 'text-ink-mute'" x-text="e.erro || (e.pct >= 100 ? 'na fila da máquina' : e.pct + '%')"></span>
                    </div>
                </template>
            </section>
        </template>

        {{-- O que ainda não chegou ao OneDrive (FR-005) e o que já foi feito. --}}
        @if ($pendentes->isNotEmpty())
            <section class="rounded-panel border border-brand/40 bg-brand/5 px-4 py-3">
                <p class="flex items-center gap-2 text-[13px] font-semibold text-ink">
                    <span class="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-brand"></span>
                    Aguardando a máquina de {{ $pasta->dono->name }} ({{ $pendentes->count() }}) <span class="font-normal text-ink-mute">· aplica no OneDrive em até 1 minuto</span>
                </p>
                <ul class="mt-1.5 space-y-0.5 text-[12.5px] text-ink-dim">
                    @foreach ($pendentes->take(8) as $o)
                        <li class="truncate">{{ $o->descricao() }} <span class="text-ink-faint">· {{ $o->autor?->name ?? '—' }}, {{ $o->created_at->format('H:i') }}</span></li>
                    @endforeach
                    @if ($pendentes->count() > 8)<li class="text-ink-faint">e mais {{ $pendentes->count() - 8 }}</li>@endif
                </ul>
            </section>
        @endif
        @if ($recentes->isNotEmpty())
            <details class="rounded-panel border border-line bg-surface px-4 py-2.5 text-[12.5px]" @if ($recentes->where('situacao', 'falhou')->isNotEmpty()) open @endif>
                <summary class="cursor-pointer text-ink-mute">Alterações recentes feitas pelo Matriz ({{ $recentes->count() }})@if ($n = $recentes->where('situacao', 'falhou')->count()) · <span class="text-crit">{{ $n }} com falha</span>@endif</summary>
                <ul class="mt-2 space-y-0.5">
                    @foreach ($recentes as $o)
                        <li class="truncate {{ $o->situacao === 'falhou' ? 'text-crit' : 'text-ink-dim' }}">
                            {{ $o->situacao === 'feita' ? '✓' : '✕' }} {{ $o->descricao() }}{{ $o->erro ? ' — '.$o->erro : '' }}
                            <span class="text-ink-faint">· {{ $o->autor?->name ?? '—' }}, {{ $o->updated_at->format('d/m H:i') }}</span>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif

        @if ($outras->isNotEmpty())
            <p class="text-[12.5px] text-ink-mute">Outras pastas:
                @foreach ($outras as $o)<a href="{{ route('documentos.pasta', $o) }}" class="text-brand-text hover:underline">{{ $o->nome }}</a>@if (! $loop->last) · @endif @endforeach
            </p>
        @endif

        {{-- Trilha: de volta a qualquer nível (navegação por pastas, 19/10/2026). --}}
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        @if ($busca === '')
            <nav class="flex min-w-0 flex-wrap items-center gap-1.5 text-[13px]">
                <a href="{{ route('documentos.pasta', $pasta) }}" class="font-semibold {{ $nivel === '' ? 'text-ink' : 'text-brand-text hover:underline' }}">{{ $pasta->nome }}</a>
                @foreach ($trilha as $t)
                    <span class="text-ink-faint">›</span>
                    <a href="{{ route('documentos.pasta', [$pasta, 'caminho' => $t['caminho']]) }}" class="{{ $loop->last ? 'font-semibold text-ink' : 'text-brand-text hover:underline' }}">{{ $t['nome'] }}</a>
                @endforeach
            </nav>
        @else
            <p class="text-[13px] text-ink-mute">{{ $arquivos->count() }} resultado(s) para "{{ $busca }}" em todas as subpastas · <a href="{{ route('documentos.pasta', $pasta) }}" class="text-brand-text hover:underline">limpar busca</a></p>
        @endif
            <div class="ml-auto flex flex-wrap items-center gap-1.5">
                <div class="flex rounded-control border border-btn-line p-0.5" role="group" aria-label="Modo de exibição">
                    <button type="button" @click="trocar('cards')" :class="modo === 'cards' ? 'bg-head text-ink' : 'text-ink-faint'" class="flex h-[24px] items-center gap-1 rounded-control px-2 text-[12px] font-semibold" title="Cards"><span class="h-3.5 w-3.5"><x-nav-icon name="view-grid" /></span> Cards</button>
                    <button type="button" @click="trocar('linhas')" :class="modo === 'linhas' ? 'bg-head text-ink' : 'text-ink-faint'" class="flex h-[24px] items-center gap-1 rounded-control px-2 text-[12px] font-semibold" title="Linhas"><span class="h-3.5 w-3.5"><x-nav-icon name="view-list" /></span> Linhas</button>
                </div>
                @if ($busca === '')
                    <button type="button" @click="dialogo = { tipo: 'pasta' }" class="{{ $botao }}"><span class="h-4 w-4"><x-nav-icon name="plus" /></span> Nova pasta</button>
                    <label class="{{ $botao }} cursor-pointer"><span class="h-4 w-4"><x-nav-icon name="upload" /></span> Enviar arquivos
                        <input type="file" multiple class="sr-only" @change="enviarTodos($event.target.files); $event.target.value = ''">
                    </label>
                @endif
                @if ($pasta->ehDono(auth()->user()))
                    <button type="button" @click="compartilhar = ! compartilhar" class="{{ $botao }}"><span class="h-4 w-4"><x-nav-icon name="users" /></span> Compartilhar</button>
                @endif
            </div>
        </div>

        @if ($cards->isNotEmpty() || $novasAqui->isNotEmpty())
            {{-- Cards: até 5 por linha (19/10/2026), cada um dizendo o que tem dentro. --}}
            <div x-show="modo === 'cards'" class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                @foreach ($cards as $c)
                    <a href="{{ route('documentos.pasta', [$pasta, 'caminho' => $c['caminho']]) }}" class="group flex flex-col rounded-panel border border-line bg-surface p-3 transition hover:border-brand">
                        <div class="flex items-start gap-2.5">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-ctl bg-brand/10 text-brand-text"><span class="h-4 w-4"><x-nav-icon name="arquivo" peso="1.7" /></span></span>
                            <div class="min-w-0 flex-1">
                                <p class="line-clamp-2 break-words font-display text-[13.5px] font-semibold leading-snug text-ink group-hover:text-brand" title="{{ $c['nome'] }}">{{ $c['nome'] }}</p>
                                <p class="mt-0.5 text-[11.5px] text-ink-mute">
                                    @if ($c['subpastas']->isNotEmpty()){{ $c['subpastas']->count() }} pasta(s) · @endif{{ $c['arquivos'] }} arq. · {{ number_format($c['bytes'] / 1048576, 1, ',', '.') }} MB
                                </p>
                            </div>
                        </div>
                        @if ($c['tipos'])
                            <div class="mt-2 flex flex-wrap gap-1">
                                @foreach (array_slice($c['tipos'], 0, 3, true) as $tipo => $n)
                                    <span class="rounded-badge bg-head px-1.5 py-0.5 text-[10.5px] text-ink-dim">{{ $n }} {{ $tipo }}</span>
                                @endforeach
                                @if (count($c['tipos']) > 3)<span class="px-0.5 py-0.5 text-[10.5px] text-ink-faint">+{{ count($c['tipos']) - 3 }}</span>@endif
                            </div>
                        @endif
                        <p class="mt-2 line-clamp-2 text-[11.5px] leading-snug text-ink-mute">
                            @if ($c['arquivos'] === 0 && $c['subpastas']->isEmpty())
                                <span class="text-ink-faint">Pasta vazia — arraste arquivos depois de abrir.</span>
                            @else
                                <span class="text-ink-faint">{{ $c['subpastas']->isNotEmpty() ? 'Dentro:' : 'Recentes:' }}</span> {{ implode(', ', $c['exemplos']) }}{{ $c['subpastas']->count() > 4 ? ' e mais '.($c['subpastas']->count() - 4) : '' }}
                            @endif
                        </p>
                        <div class="mt-auto flex items-center pt-2">
                            <p class="min-w-0 flex-1 truncate text-[10.5px] text-ink-faint">{{ $c['atualizado'] ? 'Atualizado em '.$c['atualizado']->format('d/m/Y') : 'Sem arquivos' }}</p>
                            <button type="button" title="Mover ou renomear" class="{{ $mini }}" @click.prevent="dialogo = { tipo: 'mover', caminho: @js($c['caminho']), destino: @js($c['caminho']) }"><span class="h-3.5 w-3.5"><x-nav-icon name="pencil" /></span></button>
                            <button type="button" title="Apagar" class="{{ $mini }} hover:!text-crit" @click.prevent="dialogo = { tipo: 'apagar', caminho: @js($c['caminho']), pasta: true, arquivos: {{ $c['arquivos'] }} }"><span class="h-3.5 w-3.5"><x-nav-icon name="trash" /></span></button>
                        </div>
                    </a>
                @endforeach
                @foreach ($novasAqui as $o)
                    <div class="flex flex-col rounded-panel border border-dashed border-brand/50 bg-surface p-3">
                        <p class="truncate font-display text-[13.5px] font-semibold text-ink">{{ basename($o->caminho) }}</p>
                        <p class="mt-1 text-[11.5px] text-ink-mute">Nova pasta · aguardando a máquina criar no OneDrive</p>
                    </div>
                @endforeach
            </div>

            {{-- Linhas: as mesmas pastas, uma por linha. --}}
            <section x-show="modo === 'linhas'" x-cloak class="rounded-panel border border-line bg-surface">
                <x-tabela min="680px" :fixa="true" class="!rounded-none !border-0 !bg-transparent">
                    <colgroup><col><col class="w-[220px]"><col class="w-[90px]"><col class="w-[110px]"><col class="w-[80px]"></colgroup>
                    <tbody class="divide-y divide-line">
                        @foreach ($cards as $c)
                            <tr class="group hover:bg-head/50">
                                <td class="px-4 py-2">
                                    <a href="{{ route('documentos.pasta', [$pasta, 'caminho' => $c['caminho']]) }}" class="flex items-center gap-2.5">
                                        <span class="h-[18px] w-[18px] shrink-0 text-brand-text"><x-nav-icon name="arquivo" peso="1.7" /></span>
                                        <span class="min-w-0">
                                            <span class="block truncate text-[13.5px] font-semibold text-ink hover:text-brand">{{ $c['nome'] }}</span>
                                            <span class="block truncate text-[11.5px] text-ink-faint">{{ $c['subpastas']->isNotEmpty() ? 'Dentro: ' : '' }}{{ implode(', ', $c['exemplos']) }}</span>
                                        </span>
                                    </a>
                                </td>
                                <td class="px-3 py-2 text-[12px] text-ink-mute">
                                    <span class="block truncate">@if ($c['subpastas']->isNotEmpty()){{ $c['subpastas']->count() }} pasta(s) · @endif{{ $c['arquivos'] }} arquivo(s)</span>
                                    <span class="block truncate text-[11px] text-ink-faint">{{ collect($c['tipos'])->map(fn ($n, $t) => $n.' '.$t)->implode(' · ') }}</span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-[12.5px] text-ink-mute">{{ number_format($c['bytes'] / 1048576, 1, ',', '.') }} MB</td>
                                <td class="whitespace-nowrap px-3 py-2 text-[12.5px] text-ink-mute">{{ $c['atualizado']?->format('d/m/Y') ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <div class="flex justify-end">
                                        <button type="button" title="Mover ou renomear" class="{{ $mini }}" @click="dialogo = { tipo: 'mover', caminho: @js($c['caminho']), destino: @js($c['caminho']) }"><span class="h-3.5 w-3.5"><x-nav-icon name="pencil" /></span></button>
                                        <button type="button" title="Apagar" class="{{ $mini }} hover:!text-crit" @click="dialogo = { tipo: 'apagar', caminho: @js($c['caminho']), pasta: true, arquivos: {{ $c['arquivos'] }} }"><span class="h-3.5 w-3.5"><x-nav-icon name="trash" /></span></button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        @foreach ($novasAqui as $o)
                            <tr><td colspan="5" class="px-4 py-2 text-[12.5px] text-ink-mute"><span class="font-semibold text-ink">{{ basename($o->caminho) }}</span> · nova pasta, aguardando a máquina criar no OneDrive</td></tr>
                        @endforeach
                    </tbody>
                </x-tabela>
            </section>
        @endif

        {{-- Arquivos do próprio nível (ou o resultado da busca). Em Cards, miniatura grande
             como os "ícones grandes" do Windows (19/10/2026); em Linhas, a miniatura pequena. --}}
        @if ($arquivos->isNotEmpty() || $enviosAqui->isNotEmpty())
            @if ($cards->isNotEmpty())
                <p class="pt-1 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Arquivos nesta pasta</p>
            @endif
            <div x-show="modo === 'cards'" class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6">
                @foreach ($enviosAqui as $o)
                    <div class="flex flex-col rounded-panel border border-dashed border-brand/50 bg-surface p-2">
                        <div class="flex aspect-[4/3] items-center justify-center rounded-ctl bg-head text-ink-faint"><span class="h-8 w-8"><x-nav-icon name="upload" peso="1.5" /></span></div>
                        <p class="mt-1.5 line-clamp-2 break-words text-[12.5px] font-medium leading-snug text-ink">{{ basename($o->caminho) }}</p>
                        <p class="text-[11px] text-ink-mute">aguardando a máquina gravar no OneDrive</p>
                    </div>
                @endforeach
                @foreach ($arquivos as $d)
                    @php $abrir = $d->abreNoNavegador() ? route('documentos.ver', $d->id) : route('documentos.baixar', $d->id); @endphp
                    <div class="group flex flex-col rounded-panel border border-line bg-surface p-2 transition hover:border-brand" x-data="{ semMini: {{ $d->temMiniatura() ? 'false' : 'true' }} }">
                        <a href="{{ $abrir }}" @if ($d->abreNoNavegador()) target="_blank" rel="noopener" @endif title="{{ $d->caminho }}"
                           class="relative flex aspect-[4/3] items-center justify-center overflow-hidden rounded-ctl bg-head">
                            @if ($d->temMiniatura())
                                <img x-show="! semMini" src="{{ route('documentos.miniatura', $d->id) }}?v={{ substr($d->sha256, 0, 8) }}" alt="" loading="lazy" x-on:error="semMini = true"
                                     class="h-full w-full {{ $d->extensao === 'pdf' ? 'bg-white object-contain' : 'object-cover' }}">
                            @endif
                            <span x-show="semMini" @if ($d->temMiniatura()) x-cloak @endif class="flex flex-col items-center gap-1 text-ink-faint">
                                <span class="h-8 w-8"><x-nav-icon :name="$icone($d)" peso="1.5" /></span>
                                <span class="font-mono text-[10px] uppercase">{{ $d->extensao ?: 'arquivo' }}</span>
                            </span>
                            @if ($d->tipo() === 'Vídeo' && $d->temMiniatura())
                                <span x-show="! semMini" class="absolute flex h-8 w-8 items-center justify-center rounded-full bg-black/55 text-white"><span class="ml-0.5 h-4 w-4"><x-nav-icon name="play" /></span></span>
                            @endif
                        </a>
                        <a href="{{ $abrir }}" @if ($d->abreNoNavegador()) target="_blank" rel="noopener" @endif class="mt-1.5 line-clamp-2 break-words text-[12.5px] font-medium leading-snug text-ink hover:text-brand" title="{{ $d->nome }}">{{ $d->nome }}</a>
                        @if ($busca !== '' && $d->subpasta !== '')<p class="truncate text-[11px] text-ink-faint" title="{{ $d->subpasta }}">{{ str_replace('/', ' › ', $d->subpasta) }}</p>@endif
                        <div class="mt-auto flex items-center pt-1">
                            <p class="min-w-0 flex-1 truncate text-[11px] text-ink-mute">{{ $d->tipo() }} · {{ $d->tamanhoLegivel() }}</p>
                            <a href="{{ route('documentos.baixar', $d->id) }}" title="Baixar" class="{{ $mini }}"><span class="h-3.5 w-3.5"><x-nav-icon name="download" /></span></a>
                            <button type="button" title="Mover ou renomear" class="{{ $mini }}" @click="dialogo = { tipo: 'mover', caminho: @js($d->caminho), destino: @js($d->caminho) }"><span class="h-3.5 w-3.5"><x-nav-icon name="pencil" /></span></button>
                            <button type="button" title="Apagar" class="{{ $mini }} hover:!text-crit" @click="dialogo = { tipo: 'apagar', caminho: @js($d->caminho), pasta: false }"><span class="h-3.5 w-3.5"><x-nav-icon name="trash" /></span></button>
                        </div>
                    </div>
                @endforeach
            </div>

            <section x-show="modo === 'linhas'" x-cloak class="rounded-panel border border-line bg-surface">
                <x-tabela min="720px" :fixa="true" class="!rounded-none !border-0 !bg-transparent">
                    <colgroup><col><col class="w-[100px]"><col class="w-[90px]"><col class="w-[130px]"><col class="w-[210px]"></colgroup>
                    <tbody class="divide-y divide-line">
                        @foreach ($enviosAqui as $o)
                            <tr>
                                <td class="px-4 py-2.5 text-[13.5px] text-ink">{{ basename($o->caminho) }}</td>
                                <td colspan="4" class="px-3 py-2.5 text-[12.5px] text-ink-mute">aguardando a máquina gravar no OneDrive · {{ $o->autor?->name }}</td>
                            </tr>
                        @endforeach
                        @foreach ($arquivos as $d)
                            <tr class="hover:bg-head/50">
                                <td class="px-4 py-2">
                                    <div class="flex items-center gap-2.5">
                                        <span class="flex h-9 w-12 shrink-0 items-center justify-center overflow-hidden rounded-tile bg-head text-ink-dim" x-data="{ semMini: {{ $d->temMiniatura() ? 'false' : 'true' }} }">
                                            @if ($d->temMiniatura())
                                                <img x-show="! semMini" src="{{ route('documentos.miniatura', $d->id) }}?v={{ substr($d->sha256, 0, 8) }}" alt="" loading="lazy" x-on:error="semMini = true" class="h-full w-full object-cover">
                                            @endif
                                            <span x-show="semMini" @if ($d->temMiniatura()) x-cloak @endif class="h-[18px] w-[18px]"><x-nav-icon :name="$icone($d)" peso="1.7" /></span>
                                        </span>
                                        <span class="min-w-0">
                                            <a href="{{ $d->abreNoNavegador() ? route('documentos.ver', $d->id) : route('documentos.baixar', $d->id) }}" @if ($d->abreNoNavegador()) target="_blank" rel="noopener" @endif
                                               class="block truncate text-[13.5px] font-medium text-ink hover:text-brand" title="{{ $d->caminho }}">{{ $d->nome }}</a>
                                            @if ($busca !== '' && $d->subpasta !== '')<span class="block truncate text-[11.5px] text-ink-faint">{{ str_replace('/', ' › ', $d->subpasta) }}</span>@endif
                                        </span>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-[12.5px] text-ink-mute">{{ $d->tipo() }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-[12.5px] text-ink-mute">{{ $d->tamanhoLegivel() }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-[12.5px] text-ink-mute">{{ $d->modificado_em?->format('d/m/Y H:i') }}</td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($d->abreNoNavegador())
                                            <a href="{{ route('documentos.ver', $d->id) }}" target="_blank" rel="noopener" class="{{ $botao }}">Abrir</a>
                                        @endif
                                        <a href="{{ route('documentos.baixar', $d->id) }}" class="{{ $botao }}" title="Baixar"><span class="h-3.5 w-3.5"><x-nav-icon name="download" /></span></a>
                                        <button type="button" title="Mover ou renomear" class="{{ $mini }}" @click="dialogo = { tipo: 'mover', caminho: @js($d->caminho), destino: @js($d->caminho) }"><span class="h-3.5 w-3.5"><x-nav-icon name="pencil" /></span></button>
                                        <button type="button" title="Apagar" class="{{ $mini }} hover:!text-crit" @click="dialogo = { tipo: 'apagar', caminho: @js($d->caminho), pasta: false }"><span class="h-3.5 w-3.5"><x-nav-icon name="trash" /></span></button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-tabela>
            </section>
        @endif

        @if ($cards->isEmpty() && $arquivos->isEmpty() && $novasAqui->isEmpty() && $enviosAqui->isEmpty())
            <div class="rounded-panel border border-dashed border-line bg-surface px-4 py-10 text-center text-[13.5px] text-ink-mute">
                @if ($busca !== '')
                    Nada encontrado para "{{ $busca }}".
                @else
                    <span class="mx-auto mb-2 block h-7 w-7 text-ink-faint"><x-nav-icon name="upload" peso="1.6" /></span>
                    Pasta vazia. Arraste arquivos para cá ou use <strong>Enviar arquivos</strong>.
                @endif
            </div>
        @endif

        {{-- Diálogos: nova pasta, mover/renomear e apagar (com confirmação). --}}
        <div x-show="dialogo" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @keydown.escape.window="dialogo = null" @click.self="dialogo = null">
            <template x-if="dialogo && dialogo.tipo === 'pasta'">
                <form method="POST" action="{{ route('documentos.criar-pasta', $pasta) }}" class="w-full max-w-sm rounded-panel border border-line bg-surface p-4">
                    @csrf <input type="hidden" name="nivel" value="{{ $nivel }}">
                    <p class="font-display text-[15px] font-semibold text-ink">Nova pasta</p>
                    <p class="mb-3 text-[12.5px] text-ink-mute">Em {{ $nivel === '' ? $pasta->nome : str_replace('/', ' › ', $nivel) }}</p>
                    <input name="nome" required maxlength="120" x-init="$nextTick(() => $el.focus())" placeholder="Ex.: Padaria Monte Líbano" class="w-full rounded-control border border-btn-line bg-canvas px-2.5 py-1.5 text-[13px] text-ink focus:border-brand focus:outline-none">
                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" @click="dialogo = null" class="{{ $botao }}">Cancelar</button>
                        <button class="h-[28px] rounded-control bg-brand px-3 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright">Criar</button>
                    </div>
                </form>
            </template>
            <template x-if="dialogo && dialogo.tipo === 'mover'">
                <form method="POST" action="{{ route('documentos.mover', $pasta) }}" class="w-full max-w-lg rounded-panel border border-line bg-surface p-4">
                    @csrf <input type="hidden" name="caminho" :value="dialogo.caminho">
                    <p class="font-display text-[15px] font-semibold text-ink">Mover ou renomear</p>
                    <p class="mb-3 text-[12.5px] text-ink-mute">Edite o caminho: trocar o fim renomeia; trocar o começo muda de pasta. Separe as pastas com <code>/</code>.</p>
                    <input name="destino" required maxlength="600" x-model="dialogo.destino" x-init="$nextTick(() => $el.focus())" class="w-full rounded-control border border-btn-line bg-canvas px-2.5 py-1.5 font-mono text-[12.5px] text-ink focus:border-brand focus:outline-none">
                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" @click="dialogo = null" class="{{ $botao }}">Cancelar</button>
                        <button class="h-[28px] rounded-control bg-brand px-3 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright">Mover</button>
                    </div>
                </form>
            </template>
            <template x-if="dialogo && dialogo.tipo === 'apagar'">
                <form method="POST" action="{{ route('documentos.apagar', $pasta) }}" class="w-full max-w-md rounded-panel border border-line bg-surface p-4">
                    @csrf <input type="hidden" name="caminho" :value="dialogo.caminho">
                    <p class="font-display text-[15px] font-semibold text-ink">Apagar <span x-text="dialogo.caminho.split('/').pop()"></span>?</p>
                    <p class="mt-1 text-[12.5px] text-ink-mute" x-text="dialogo.pasta ? 'A pasta e tudo o que tem dentro (' + dialogo.arquivos + ' arquivo(s)) saem do OneDrive e do Matriz.' : 'O arquivo sai do OneDrive e do Matriz.'"></p>
                    <p class="mt-1 text-[12px] text-ink-faint">Fica na lixeira do OneDrive por 30 dias.</p>
                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" @click="dialogo = null" class="{{ $botao }}">Cancelar</button>
                        <button class="h-[28px] rounded-control bg-crit px-3 text-[12.5px] font-semibold text-on-brand hover:opacity-90">Apagar</button>
                    </div>
                </form>
            </template>
        </div>
    </div>

    <script>
        function documentosPasta() {
            const PARTE = {{ \App\Services\Documentos\Espelho::PARTE_BYTES }};
            const MAX = {{ \App\Models\Documento::MAX_BYTES }};
            return {
                compartilhar: false, arrastando: false, dialogo: null, envios: [], modo: 'cards', pendentes: {{ $pendentes->count() }},
                iniciar() {
                    try { this.modo = localStorage.getItem('documentos:modo') === 'linhas' ? 'linhas' : 'cards'; } catch (e) {}
                    // Com alteração na fila, confere a cada 10 s e recarrega quando a máquina terminar.
                    if (this.pendentes) {
                        const t = setInterval(async () => {
                            try {
                                const j = await (await fetch(@js(route('documentos.pendentes', $pasta)), { headers: { Accept: 'application/json' } })).json();
                                if (j.pendentes < this.pendentes && !this.envios.some(e => e.pct < 100 && !e.erro) && !this.dialogo) { clearInterval(t); location.reload(); }
                            } catch (e) {}
                        }, 10000);
                    }
                },
                trocar(m) { this.modo = m; try { localStorage.setItem('documentos:modo', m); } catch (e) {} },
                async enviarTodos(lista) {
                    // Pasta arrastada chega como "arquivo" sem tipo e vazio: fica de fora (só arquivos, por enquanto).
                    const arquivos = Array.from(lista || []).filter(f => f.type !== '' || f.size > 0);
                    for (const f of arquivos) {
                        const e = { id: Math.random().toString(36).slice(2), nome: f.name, pct: 0, erro: '' };
                        this.envios.push(e);
                        if (f.size > MAX) { e.erro = 'acima de 200 MB'; continue; }
                        await this.enviarUm(f, e);
                    }
                    if (arquivos.length && this.envios.every(e => e.erro || e.pct >= 100)) {
                        setTimeout(() => location.reload(), 600);
                    }
                },
                async enviarUm(f, e) {
                    const envio = (crypto.randomUUID ? crypto.randomUUID() : Date.now() + '-' + Math.random().toString(36).slice(2)).replace(/[^A-Za-z0-9-]/g, '');
                    const partes = Math.max(1, Math.ceil(f.size / PARTE));
                    for (let i = 0; i < partes; i++) {
                        const corpo = new FormData();
                        corpo.append('envio', envio); corpo.append('nome', f.name); corpo.append('nivel', @js($nivel));
                        corpo.append('tamanho', f.size); corpo.append('parte', i); corpo.append('partes', partes);
                        corpo.append('arquivo', f.slice(i * PARTE, (i + 1) * PARTE), 'parte.bin');
                        const r = await fetch(@js(route('documentos.enviar', $pasta)), { method: 'POST', body: corpo,
                            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } }).catch(() => null);
                        if (!r || !r.ok) {
                            const j = r ? await r.json().catch(() => ({})) : {};
                            e.erro = (j.errors && Object.values(j.errors)[0][0]) || j.message || 'falhou — tente de novo';
                            return;
                        }
                        e.pct = Math.round((i + 1) * 100 / partes);
                    }
                },
            };
        }
    </script>
</x-app-layout>
