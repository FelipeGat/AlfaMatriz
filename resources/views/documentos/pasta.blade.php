<x-app-layout>
    <x-slot name="titulo">Documentos</x-slot>
    <x-slot name="contexto"><span>{{ $pasta->nome }} · {{ $pasta->lido_em ? 'espelhada às '.$pasta->lido_em->format('H:i') : 'ainda não lida' }}{{ $pasta->velha() ? ' (desatualizada)' : '' }}</span></x-slot>

    {{-- docs/specs/documentos-espelhados: o espelho da pasta do OneDrive, uma seção
         por subpasta (cliente). Os arquivos só saem por aqui, e só para quem tem
         acesso à pasta (FR-001, FR-003). --}}
    @php
        $botao = 'inline-flex h-[28px] items-center gap-1 whitespace-nowrap rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim hover:border-brand hover:text-brand';
        $icone = fn ($d) => match ($d->tipo()) { 'PDF' => 'document', 'Imagem' => 'imagem', 'Vídeo' => 'play', 'Áudio' => 'speaker-wave', 'Planilha' => 'chart-bar', default => 'arquivo' };
    @endphp
    <div x-data="{ compartilhar: false }" class="mx-auto w-full max-w-6xl space-y-4">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1">
                <h1 class="truncate font-display text-[22px] font-semibold leading-tight text-ink">{{ $pasta->nome }}</h1>
                <p class="mt-0.5 text-[13px] text-ink-mute">{{ $pasta->arquivos }} arquivo(s) · {{ number_format($pasta->bytes / 1048576, 0, ',', '.') }} MB · espelho da pasta do OneDrive de {{ $pasta->dono->name }}</p>
            </div>
            <form method="GET" class="relative w-full sm:w-[260px]">
                <span class="pointer-events-none absolute left-2.5 top-2 h-4 w-4 text-ink-faint"><x-nav-icon name="search" /></span>
                <input type="search" name="q" value="{{ $busca }}" placeholder="Buscar arquivo ou cliente…" class="w-full rounded-control border border-btn-line bg-canvas py-1.5 pl-8 pr-2 text-[13px] text-ink placeholder:text-ink-faint focus:border-brand focus:outline-none">
            </form>
            @if ($pasta->ehDono(auth()->user()))
                <button type="button" @click="compartilhar = ! compartilhar" class="{{ $botao }}"><span class="h-4 w-4"><x-nav-icon name="users" /></span> Compartilhar</button>
            @endif
        </div>
        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif
        @if ($pasta->velha())
            <x-aviso tom="atencao" :segundos="0">A sua máquina não espelha esta pasta há mais de {{ \App\Models\DocumentoPasta::VELHO_HORAS }} horas: o que mudou no OneDrive desde então ainda não está aqui.</x-aviso>
        @endif

        @if ($pasta->ehDono(auth()->user()))
            <form x-show="compartilhar" x-cloak method="POST" action="{{ route('documentos.acessos', $pasta) }}" class="rounded-panel border border-line bg-surface p-4">
                @csrf @method('PUT')
                <p class="mb-2 text-[12.5px] text-ink-mute">Quem vê esta pasta além de você. O perfil não importa: nem o administrador vê sem estar marcado aqui.</p>
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

        @if ($outras->isNotEmpty())
            <p class="text-[12.5px] text-ink-mute">Outras pastas:
                @foreach ($outras as $o)<a href="{{ route('documentos.pasta', $o) }}" class="text-brand-text hover:underline">{{ $o->nome }}</a>@if (! $loop->last) · @endif @endforeach
            </p>
        @endif

        @php $abertas = $busca !== '' || $grupos->count() <= 6; @endphp
        @forelse ($grupos as $subpasta => $docs)
            <section x-data="{ aberta: {{ $abertas ? 'true' : 'false' }} }" class="rounded-panel border border-line bg-surface">
                <button type="button" @click="aberta = ! aberta" class="flex w-full items-center gap-2.5 px-4 py-3 text-left">
                    <span class="h-5 w-5 text-brand-text"><x-nav-icon name="arquivo" peso="1.7" /></span>
                    <span class="min-w-0 flex-1 truncate font-display text-[15px] font-semibold text-ink">{{ $subpasta === '' ? 'Na raiz da pasta' : $subpasta }}</span>
                    <span class="text-[12px] text-ink-mute">{{ $docs->count() }} arquivo(s)</span>
                    <span class="text-ink-faint" x-text="aberta ? '▴' : '▾'"></span>
                </button>
                <div x-show="aberta" @if (! $abertas) x-cloak @endif class="border-t border-line">
                    <x-tabela min="680px" :fixa="true" class="!rounded-none !border-0 !bg-transparent">
                        <colgroup><col><col class="w-[110px]"><col class="w-[90px]"><col class="w-[140px]"><col class="w-[170px]"></colgroup>
                        <tbody class="divide-y divide-line">
                            @foreach ($docs as $d)
                                <tr class="hover:bg-head/50">
                                    <td class="px-4 py-2.5">
                                        <div class="flex items-center gap-2.5">
                                            <span class="h-[18px] w-[18px] shrink-0 text-ink-dim"><x-nav-icon :name="$icone($d)" peso="1.7" /></span>
                                            @php $dentro = trim(substr($d->subpasta, strlen((string) $subpasta)), '/'); @endphp
                                            <span class="min-w-0">
                                                <a href="{{ $d->abreNoNavegador() ? route('documentos.ver', $d->id) : route('documentos.baixar', $d->id) }}" @if ($d->abreNoNavegador()) target="_blank" rel="noopener" @endif
                                                   class="block truncate text-[13.5px] font-medium text-ink hover:text-brand" title="{{ $d->caminho }}">{{ $d->nome }}</a>
                                                @if ($dentro !== '')<span class="block truncate text-[11.5px] text-ink-faint" title="{{ $dentro }}">{{ str_replace('/', ' › ', $dentro) }}</span>@endif
                                            </span>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-[12.5px] text-ink-mute">{{ $d->tipo() }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-[12.5px] text-ink-mute">{{ $d->tamanhoLegivel() }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-[12.5px] text-ink-mute">{{ $d->modificado_em?->format('d/m/Y H:i') }}</td>
                                    <td class="px-3 py-2.5">
                                        <div class="flex justify-end gap-1">
                                            @if ($d->abreNoNavegador())
                                                <a href="{{ route('documentos.ver', $d->id) }}" target="_blank" rel="noopener" class="{{ $botao }}">Abrir</a>
                                            @endif
                                            <a href="{{ route('documentos.baixar', $d->id) }}" class="{{ $botao }}"><span class="h-3.5 w-3.5"><x-nav-icon name="download" /></span> Baixar</a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-tabela>
                </div>
            </section>
        @empty
            <div class="rounded-panel border border-line bg-surface px-4 py-8 text-center text-[13.5px] text-ink-mute">
                {{ $busca !== '' ? 'Nada encontrado para "'.$busca.'".' : 'A pasta ainda está vazia: a sua máquina espelha a cada 15 minutos.' }}
            </div>
        @endforelse
    </div>
</x-app-layout>
