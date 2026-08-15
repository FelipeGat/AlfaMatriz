<x-app-layout>
    <x-slot name="titulo">Manutenção e atualizações</x-slot>

    {{--
        Tela reservada: nenhum dado ligado ainda. Ela existe antes do conteúdo
        para o menu já contar que este é o lugar dele — a alternativa era a
        aba aparecer do nada no dia da integração, sem ninguém saber o que é.
        As duas linhas abaixo do aviso são o índice do que vem: dizem o
        recorte (por sistema) e a forma (agenda e histórico), que é o que dá
        para prometer sem inventar dado.
    --}}
    <div class="mx-auto max-w-[560px] pt-10">
        <div class="rounded-panel border border-line bg-surface overflow-hidden">
            <div class="p-6 text-center">
                <span class="mx-auto h-10 w-10 rounded-tile bg-chip text-ink-mute flex items-center justify-center">
                    <span class="h-[18px] w-[18px]"><x-nav-icon name="wrench" :peso="1.7" /></span>
                </span>

                <span class="mt-4 inline-block rounded-badge bg-brand/20 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-caps text-brand-text">
                    Em breve
                </span>

                <p class="mt-2 text-[13px] leading-relaxed text-ink-dim">
                    Este vai ser o lugar para acompanhar a manutenção dos
                    sistemas Alfa — o que está agendado e o que já mudou.
                </p>
            </div>

            <div class="flex items-start gap-3 border-t border-rule px-5 py-4">
                <span class="shrink-0 h-8 w-8 rounded-tile bg-chip text-ink-mute flex items-center justify-center">
                    <span class="h-4 w-4"><x-nav-icon name="clock" :peso="1.7" /></span>
                </span>
                <div class="min-w-0">
                    <p class="text-[13px] font-medium text-ink">Atualizações programadas</p>
                    <p class="mt-0.5 text-[12.5px] text-ink-mute">Quando cada sistema entra em manutenção, avisado antes de acontecer.</p>
                </div>
            </div>

            <div class="flex items-start gap-3 border-t border-rule px-5 py-4">
                <span class="shrink-0 h-8 w-8 rounded-tile bg-chip text-ink-mute flex items-center justify-center">
                    <span class="h-4 w-4"><x-nav-icon name="document" :peso="1.7" /></span>
                </span>
                <div class="min-w-0">
                    <p class="text-[13px] font-medium text-ink">Histórico de atualizações</p>
                    <p class="mt-0.5 text-[12.5px] text-ink-mute">O que cada versão publicada mudou, com o changelog completo.</p>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
