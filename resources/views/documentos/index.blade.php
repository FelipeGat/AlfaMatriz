<x-app-layout>
    <x-slot name="titulo">Documentos</x-slot>
    <x-slot name="contexto"><span>pastas espelhadas do OneDrive</span></x-slot>

    {{-- docs/specs/documentos-espelhados: só as pastas da pessoa ou compartilhadas com ela. --}}
    <div class="mx-auto w-full max-w-5xl space-y-4">
        <h1 class="font-display text-[22px] font-semibold leading-tight text-ink">Documentos</h1>
        @if ($pastas->isEmpty())
            <div class="rounded-panel border border-line bg-surface px-4 py-8 text-center text-[13.5px] text-ink-mute">Nenhuma pasta compartilhada com você.</div>
        @endif
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($pastas as $p)
                <a href="{{ route('documentos.pasta', $p) }}" class="flex items-start gap-3 rounded-panel border border-line bg-surface p-4 hover:border-brand">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-ctl bg-brand/10 text-brand-text"><span class="h-5 w-5"><x-nav-icon name="arquivo" peso="1.7" /></span></span>
                    <span class="min-w-0">
                        <span class="block truncate font-display text-[15px] font-semibold text-ink">{{ $p->nome }}</span>
                        <span class="block text-[12.5px] text-ink-mute">{{ $p->arquivos }} arquivo(s) · {{ number_format($p->bytes / 1048576, 0, ',', '.') }} MB</span>
                        <span class="block text-[12px] text-ink-faint">de {{ $p->dono->name }}{{ $p->lido_em ? ' · lida '.$p->lido_em->format('d/m H:i') : '' }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    </div>
</x-app-layout>
