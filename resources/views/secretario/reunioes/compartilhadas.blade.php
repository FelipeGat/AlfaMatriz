<x-app-layout>
    <x-slot name="titulo">Reuniões compartilhadas comigo</x-slot>
    <x-slot name="contexto"><span>de quem gravou · o retrato da reunião, sem a transcrição</span></x-slot>

    {{-- docs/specs/secretario-reuniao-produto: só as reuniões em que o dono marcou a pessoa. --}}
    <div class="mx-auto w-full max-w-5xl">
        <section class="rounded-panel border border-line bg-surface">
            <x-tabela min="640px" class="!rounded-none !border-0 !bg-transparent">
                <thead>
                    <tr class="border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                        <th class="px-4 py-2.5 font-normal">Data</th><th class="px-3 py-2.5 font-normal">Reunião</th><th class="px-3 py-2.5 font-normal">Produto</th><th class="px-3 py-2.5 font-normal">De</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($reunioes as $r)
                        <tr class="hover:bg-head/50">
                            <td class="whitespace-nowrap px-4 py-3 text-[13px] text-ink">{{ $r->created_at->format('d/m/Y') }}<span class="block text-[11.5px] text-ink-mute">{{ $r->created_at->format('H:i') }}</span></td>
                            <td class="px-3 py-3">
                                <a href="{{ route('reunioes.compartilhada', $r->id) }}" class="text-[13.5px] font-semibold text-ink hover:text-brand">{{ $r->titulo }}</a>
                                <p class="line-clamp-1 text-[12px] text-ink-mute">{{ $r->resumo ?: 'Ainda em análise.' }}</p>
                            </td>
                            <td class="px-3 py-3 text-[13px] text-ink-dim">{{ $r->sistema?->nome ?? '—' }}</td>
                            <td class="px-3 py-3 text-[13px] text-ink-dim">{{ $r->dono?->name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-[13.5px] text-ink-mute">Nenhuma reunião compartilhada com você.</td></tr>
                    @endforelse
                </tbody>
            </x-tabela>
            <div class="px-4 py-2">{{ $reunioes->links() }}</div>
        </section>
    </div>
</x-app-layout>
